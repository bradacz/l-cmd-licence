# Correctness and security repair notes

This is the sanitized public source edition of **L-CMD License Server**.
A production-compatible release was deployed on 2026-10-29. That release retains
the previous HTTP routing, portal CSRF contract, throttling and authorization
conventions; this repository's HTTP layer is not its exact deployment snapshot.
The public edition removes production origins and mail defaults, uses explicit
environment configuration and includes hosted test/security workflows. It does
not include the deployment's credentials, customer data or server inventory.

## Repairs

- License activation, implicit activation during verification, and deactivation
  serialize on the parent license row. Failed signing or database work rolls back
  activation counters and verification records.
- Pending licenses cannot activate implicitly. Explicit expiry also applies to
  lifetime licenses. A missing platform cannot bypass an allowed-platform policy.
- Online JWTs require an explicit valid expiry, the expected algorithm and a
  sufficiently long application key. Their expiry is capped by license expiry
  plus configured grace. Claims identify the license and installation; clients
  must check that binding when using cached tokens.
- An explicitly empty feature policy no longer restores default entitlements.
- Secret encryption fails closed rather than falling back to unauthenticated
  encryption. Existing CBC values remain readable for migration. Stripe and SMTP
  secret migration no longer recurses, and SMTP password whitespace is retained.
- Partial Stripe settings updates preserve omitted values. Masked UI placeholders
  are not saved as credentials.
- Disabled users and suspended/archived tenants lose portal access. Password
  changes invalidate existing customer sessions. Activation and password-reset
  tokens are consumed transactionally. Invitations cannot replace an existing
  account's password; an existing user must provide their current password.
- Checkout metadata from clients cannot override validated product, tenant or
  license fields. Platform-plan checkout uses platform Stripe credentials.
- Webhook signatures retain their tenant identity. A tenant's key cannot change
  another tenant's licenses or authorize platform subscription billing.
- Unpaid checkout completion does not issue a license; asynchronous payment
  success is handled. Event receipts deduplicate retries, and checkout issuance
  is transactional and serialized per tenant, including across signing scopes.
- Renewal uses the paid subscription line period instead of adding time to the
  delivery date. Older invoices cannot shorten expiry or reactivate a revoked or
  refunded license.
- Offline signing-key initialization is serialized. Missing or unreadable parts
  of an existing key pair cause failure, not silent replacement. Public-key output
  remains stable across reads.
- Empty CSRF tokens are rejected. Unexpected exceptions are no longer returned as
  raw database/provider errors in the repaired API response paths.
- Fresh schema import creates referenced tables in the right order. Reimport
  preserves existing seed records instead of overwriting them or failing.
- Demo provisioning requires local mode and an explicit strong password, with no
  hardcoded login password and no silent overwrite of an existing password.

## Verification

Local environment: PHP 8.5.4, PHPUnit 10.5.66, MariaDB 11.4.13 over a private Unix
socket with TCP networking disabled. No production database was used. The tests
do not load the application `.env`; mail delivery is disabled.

- `composer test`: **54 tests, 256 assertions passed**, including a clean schema
  installation and multi-process activation, deactivation, webhook and key tests.
- `composer lint`: PHP syntax validation.
- `composer validate --strict`: passed.
- Composer dependency advisory check: no advisories reported at verification time.

Run unit tests without a database:

```sh
composer install
composer test:unit
composer lint
```

Integration tests require an explicitly opted-in, disposable database. The name
must end in `_test`; tests write schema, synthetic fixtures and diagnostic logs.
Configure DB connection variables for that database, then run:

```sh
LCMD_TEST_DB=1 DB_DATABASE=lcmd_test composer test
```

Tests support `DB_HOST` / `DB_PORT` or `DB_SOCKET`, plus `DB_USERNAME` and
`DB_PASSWORD`. Do not point them at a real application database.

## Deployment prerequisites and limits

The production-compatible release passed 53 tests / 253 assertions on PHP 8.2.35
and MariaDB 11.8.6, followed by 29 isolated smoke checks before deployment. Its
existing public product catalogs and offline signing keys were preserved. A
subsequent local regression test verifies invitation acceptance with an existing
account's unchanged legacy password. Domain validation errors are caught by the
customer API, and the Apache routing/authorization `.htaccess` is included.

This is not a claim that the whole system is free of vulnerabilities. Deploying
this repository's HTTP layer still needs coordinated frontend compatibility
verification rather than blindly replacing the production-compatible release.

Existing databases need `database/migrations/20261029_01_webhook_receipts.sql`
before the new webhook handler is deployed. Use reviewed backups and staging.
Preserve the existing APP_KEY: changing it loses access to encrypted secrets.
Deployments with keys shorter than 32 characters need a controlled key migration,
not a blind replacement of the environment value.

Existing customer sessions will require login once because the session now tracks
password changes. Offline keys that cannot be decrypted must be restored from a
known-good backup/configuration, not regenerated automatically.

Live Stripe/SMTP integration and complete browser workflows are not exercised by
the regression suite. Hosted CI runs the PHP-version matrix separately.
Stripe API-version compatibility must be
verified in test mode before deployment, including subscription invoice lines and
platform-plan onboarding. External mail and billing side effects are still
synchronous, not a transactional outbox: delivery/recovery guarantees need a
separate queue/outbox change. Webhook receipts are durable records; define a
retention policy before high-volume use.

Webhook behavior was checked against the official
[Stripe webhook guidance](https://docs.stripe.com/webhooks).
