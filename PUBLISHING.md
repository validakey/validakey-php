# Publishing validakey/validakey-php

## 1. Push to GitHub

Create a public repository (for example `validakey/validakey-php`) and push:

```bash
git remote add origin git@github.com:validakey/validakey-php.git
git push -u origin main
git push origin v1.0.0
```

## 2. Register on Packagist

1. Sign in at [packagist.org](https://packagist.org)
2. Click **Submit** and enter the GitHub repository URL
3. Enable the GitHub webhook for automatic updates on new tags

After registration, consumers can install with:

```bash
composer require validakey/validakey-php
```

## 3. Release checklist

- [ ] All tests pass (`vendor/bin/phpunit`)
- [ ] The envelope still agrees with the server: run `verify-crypto-parity.php` and `verify-client-parity.php` in the `vkey` plugin. Any change to a field width, the bucket list or the offset constants breaks handshakes with no useful diagnostic, so this is not optional
- [ ] `composer.lock` committed
- [ ] `version` in `composer.json` matches the tag
- [ ] Version tag pushed (`git tag v1.x.x && git push origin v1.x.x`)
- [ ] Packagist shows the new version (or trigger manual update)

## Security

This repository must never contain:

- Real API user UUIDs
- Private keys
- Private server hostnames used in production (use placeholders in docs)

Credentials belong in the consuming application's environment or private `config.php`.
