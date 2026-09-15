# Access and authentication

Validakey has two authentication paths, and which one applies depends on the endpoint.

| Path | Endpoints | Authenticated by | Identity on the wire |
|------|-----------|------------------|----------------------|
| Token | `/v1/i/`, `/v1/r/`, `/v1/t/`, `/v1/m/`, `/v1/v/`, `/v1/p/` | Instance handshake | 8-character lookup prefix only |
| Account | `/v1/u/`, `/v1/billing/*` | `Bearer <api_pkey>` | Cleartext headers and query fields |

The account path is ordinary bearer-token authentication and needs no explanation. The rest of this document covers the token path.

The split is not a convenience. The token path runs on machines you do not control — your customers' — and everything a license needs to do lives there, so the private key never has to travel to one of them.

## Why the token path exists

Tokens are minted from client machines the account holder does not necessarily control tightly: WordPress plugins on shared hosts, several applications on one server, one application across several hosts. Sending the account UUID and application id in cleartext on every mint would put long-lived credentials on the wire repeatedly, and in request logs.

The instance handshake replaces that with a short-lived, per-machine credential. Proving you hold the application id is done once; after that, requests are keyed by an instance secret that the server can rotate at will.

## Identifiers

| Identifier | Issued by | Lifetime | Role |
|------------|-----------|----------|------|
| `api_uuid` | Validakey, per account | Long | Names the account. Never sent in cleartext on the token path. |
| `user_app_id` | Validakey, per application | Long | Secret. Keys the handshake. A UUIDv4. |
| `subject` | Client | Stable per end customer | Names the customer inside one app. Required on handshake. The PHP client assigns a random Subject ID when you omit it. |
| `fingerprint` | Client, derived | Stable per machine+host | Names the machine. See below. |
| `instance_id` | Validakey, per handshake | Until the next handshake | Secret. Keys every token request. A UUIDv4. |

`user_app_id` is a credential, not a label. Anyone holding it can complete a handshake for the account it belongs to.

### Fingerprint

`machineId() + '_' + host`, where `machineId()` is the first readable of `/sys/class/dmi/id/product_uuid`, `/etc/machine-id`, or `/var/lib/dbus/machine-id`. That order prefers stability: `product_uuid` survives an OS reinstall but is usually root-only, while `machine-id` is readable but is regenerated on reinstall.

The host is part of the fingerprint because one machine may serve several hosts, and each should get its own instance.

## The envelope

Every message on the token path has the same shape:

```
prefix || hex( Feistel_key( plaintext ) )
```

- `prefix` is the first 8 characters of the key id, in cleartext. It is a database lookup hint, nothing more.
- `key` is the **full** key id concatenated with an obfuscated timestamp: `keyId . dechex(modTime(keyId))`.
- The full key id never appears on the wire.

Replies omit the prefix, because the peer already knows which key it used.

The message travels as a single JSON field:

```json
{ "q": "a7c93f10ff3dc1e69654f8c..." }
```

### Obfuscated timestamp

```
modTime(keyId) = ((time() >> 8) << 8) - (hexdec(keyId[10..11]) << 13)
```

The quantization to 256-second buckets is what lets both ends derive the same key without exchanging a nonce first. The offset is a pure function of the key id, so anyone holding the id computes the same value, while an observer cannot tell how far the reported time was displaced.

A receiver tries buckets in the order `0, -1, +1` so a request that crosses a bucket boundary in flight still opens.

### Plaintext layouts

Fields are fixed-width with no delimiters. Both ends carry the same widths as constants, so the structure gives an observer nothing. Only the final field may be variable-width; PKCS#7 unpadding recovers it exactly.

| Message | Key | Layout |
|---------|-----|--------|
| M1 handshake request | `user_app_id` | `nonce(8) + api_uuid(36) + subject_len(3) + subject + fingerprint(…)` |
| M2 handshake reply | `user_app_id` | `nonce(8) + instance_id(36)` |
| M3 token request | `instance_id` | `nonce(8) + timestamp(10) + json(…)` |
| M4 token reply | `instance_id` | `nonce(8) + json(…)` |

M3/M4 is not specific to minting. Validation, renewal, revocation, rotation and card operations all use the same pair, and name what they want in the JSON — so there is one format to keep in step rather than five.

Every plaintext starts with 8 random bytes. The cipher runs in ECB mode, so without that block two requests with the same payload would produce byte-identical ciphertext, and requests sharing a prefix would visibly share leading blocks.

## The handshake

```
     Client                                          Server
       │
       │  M1  POST /v1/i/                              │
       │  { q: appIdPre8 + E(appId)(nonce+uuid+fp) }   │
       ├──────────────────────────────────────────────►│
       │                                               │  1. prefix → candidate app ids
       │                                               │  2. for each candidate × each bucket:
       │                                               │       try decrypt; PKCS#7 rejects ~255/256
       │                                               │  3. accept the one decrypting to its
       │                                               │     own registered account UUID
       │                                               │  4. reject if the nonce was already spent
       │                                               │  5. reject if the account has no card
       │                                               │  6. issue instance_id, rotating in place
       │  M2  { q: E(appId)(nonce+instanceId) }         │
       │◄──────────────────────────────────────────────┤
       │
       │  store instance_id
```

Authentication is possession of the full `user_app_id`: only that key produces a payload that decrypts to the account UUID the server has on record for it.

Every failure returns the same response — HTTP 401, `handshake_failed`. Distinguishing "no such prefix" from "prefix exists but did not decrypt" would turn the endpoint into an oracle for enumerating valid prefixes.

The billing check is the exception, and it is deliberate. It runs only after the envelope has opened, because checking it any earlier would need a cleartext identity — the thing this endpoint exists to avoid. Once opened, the caller has proved it holds the app id, so answering `payment_required` (402) reveals nothing it could not learn from a token request, and it keeps a lapsed card from being misreported as bad credentials.

### Rotation

A repeat handshake for a subject the server already knows **rotates the instance in place**: one row per application/subject/account, a fresh instance secret, and the previous secret immediately invalid.

This bounds the value of a stolen `instance_id` — it stops working at the next legitimate handshake. The cost is that two live copies of the same app-and-subject credentials will fight, each invalidating the other. That is visible in the data: rotating an instance that was used within the last 60 seconds sets a `flagged` marker for review, since legitimate redeploys rarely look like that.

The client therefore persists its instance id and only handshakes when it has none, or when the server rejects the one it has.

To rotate on purpose, use `POST /v1/r/` (`rotateInstance()`) instead of a repeat handshake. It is sealed under the id being replaced, so only a holder of the live instance id can rotate it, and it is not flagged as a possible clone — the caller asked for it and had to prove it held the live id to ask. It is also not gated on billing: refusing rotation to a lapsed account would only keep a stale credential alive longer.

To move an Instance Entity to a different customer, use `POST /v1/t/` (`transferSubject()`). It is also sealed under the current instance id, but the server additionally requires a one-shot transfer window the account holder opened through `PATCH /v1/instance/`. A successful transfer rotates the instance id and clears the stored IE card so one customer cannot inherit another's payment method.

The reply to a rotation is sealed under the **old** instance id, not the new one. A client whose response is lost has therefore not lost its credential, and can retry.

## Nonce scope

Every instance-sealed route shares one nonce scope. A captured envelope is spent exactly once across all of them, so it cannot be replayed from one route onto another — for instance, replaying a mint request onto `/v1/r/` to force an unwanted rotation. Sharing costs a well-behaved client nothing, because each envelope carries fresh random bytes, so two genuine requests never collide however close together they are sent.

## Requesting a token

```
     Client                                          Server
       │  M3  POST /v1/m/                              │
       │  { q: instPre8 + E(instId)(nonce+ts+json) }   │
       ├──────────────────────────────────────────────►│
       │                                               │  prefix → candidate instances,
       │                                               │  decrypt, reject spent nonce,
       │                                               │  check timestamp, check billing
       │  M4  { q: E(instId)(nonce+json) }              │
       │◄──────────────────────────────────────────────┤
```

Opening M3 identifies the account, application and machine at once, because the instance row records all three. Nothing in the request says who is calling.

If the server no longer recognises the instance — HTTP 401, 403 or 409 — the client discards it, handshakes once, and retries. It does not retry a second time, and it does not retry other errors: a `payment_required` response is not a stale instance.

## What the design does and does not protect against

Replaying a captured M1 or M3 fails: the nonce is recorded on first use, and the record outlives the widest bucket a replay could still decrypt in.

An observer who captures both M1 and M2 for the same handshake can recover the instance id, since both are encrypted under the same key. What they cannot do is recover the `user_app_id` or the account UUID, and the instance they learn stops working at the next rotation. Capturing M3/M4 similarly yields the token but not the instance secret.

An attacker holding a stolen `user_app_id` and `api_uuid` can complete a handshake. Nothing in this layer prevents that; it limits the blast radius by making the resulting instance visible in the data, tied to a fingerprint, and rotatable.

The Feistel cipher here is an obfuscation layer, not a substitute for TLS. It runs in ECB mode with a 64-bit block, so identical plaintext blocks within one message are still visible as identical ciphertext blocks — the nonce prefix limits what that leaks, but does not eliminate it. **Always use HTTPS.**

## Client usage

```php
use Validakey\Instance\FileInstanceStore;
use Validakey\Request\CreateTokenRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;

$client = new ValidakeyClient(
    new ValidakeyConfig(
        baseUrl: 'https://validakey.com/api/v1',
        apiUUID: getenv('VALIDAKEY_API_UUID'),
        userAppId: getenv('VALIDAKEY_USER_APP_ID'),
    ),
    null,
    new FileInstanceStore('/var/lib/myapp/validakey-instance.json'),
);

// Handshakes on first use, then reuses the stored instance.
$token = $client->createToken(new CreateTokenRequest(duration: 3600));
```

Without an instance store the client keeps the instance in memory only, which means one handshake — and one rotation — per PHP process. Supply a store for anything long-lived. `FileInstanceStore` writes `0600`; on WordPress use `Validakey\WordPress\OptionsInstanceStore` so the secret is not under a web-servable path.

## Keeping the two implementations in step

The cipher and envelope are implemented twice, in this library and in the `vkey` server plugin. They must stay byte-identical or handshakes fail with no useful diagnostic.

The guard is `tests/fixtures/feistel-vectors.json`, committed verbatim in both repositories. Both sides assert against it:

```bash
# client
vendor/bin/phpunit

# server
php wp-content/plugins/vkey/tools/verify-crypto-parity.php
php wp-content/plugins/vkey/tools/verify-client-parity.php   # loads both, checks live interop
php wp-content/plugins/vkey/tools/verify-handshake-e2e.php   # full flow against a dev database
```

Any change to a field width, the bucket list, or the offset constants has to be made on both sides, with the fixture regenerated.
