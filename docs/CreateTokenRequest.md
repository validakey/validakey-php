# CreateTokenRequest

The `CreateTokenRequest` class is a data transfer object used to create access tokens for Validakey licenses. It represents the request parameters that go into creating a new license token via the client's `createToken()` method.

## Constructor Parameters and Their API Fields

| Parameter | Type | Default | API Field |
|-----------|------|---------|-----------|
| **duration** | `?int` | null | `duration` — omit when there is no clock limit |
| **expiresAt** | `?int` | null | `expires_at` |
| **uses** | `?int` | null | `uses` |
| **recurrence** | `?string` | null | `recurrence` (e.g. `monthly`) |
| **autoRenew** | `?bool` | null | `auto_renew` |
| **costUsd** | `?float` | null | `cost_USD` |
| **taxUsd** | `?float` | null | `tax_USD` |
| **noExpiry** | `bool` | false | `no_expiry` |

Only one basis price field is sent (`basisCents` → `amount` → `costUsd`). `total_USD` is not a client input; the server stores `cost + tax`.

## Key Methods

### `toArray()`
```php
public function toArray(): array
```
Returns the JSON payload for mint. `duration` is included only when non-null.

## Usage Examples

#### Limited (time and/or uses)
```php
// Time only
$client->createToken(new CreateTokenRequest(duration: 3600, costUsd: 9.99));

// Uses only
$client->createToken(new CreateTokenRequest(uses: 5));

// Both
$client->createToken(new CreateTokenRequest(duration: 3600, uses: 3));
```

#### Free transactional (Type 1)
```php
use Validakey\Request\CreateTokenRequest;

$client->createToken(CreateTokenRequest::free());
```

## Types of vKey

| Type | Request Example |
|------|-----------------|
| **1 Transactional** | `CreateTokenRequest::free()` or `noExpiry: true` (optional `costUsd` / `taxUsd`) |
| **2 Limited** | `duration` and/or `uses` (at least one) |
| **3 Subscription Time** | `duration` + `autoRenew: true` + `recurrence` |
| **4 Subscription Use** | `uses` + `autoRenew: true` (+ price as needed) |

## Important Notes

* Limited replaces the former separate “Limited Time” and “Limited Use” types.
* Uses-only Limited must omit `duration` so the server does not attach a clock expiry.
