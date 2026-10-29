# Operations Guide

## Configuration

Start with [.env.example](../.env.example). Real credentials stay outside Git.
The bootstrap reads `.env` when present; its values override matching process
environment values, so do not accidentally reuse a production file in staging.

| Variable | Purpose |
| --- | --- |
| APP_ENV / APP_DEBUG | Local/production mode and debug behavior |
| APP_URL / APP_BASE_PATH | Public API base URL and optional installation prefix |
| APP_KEY | Long-lived encryption/online signing secret, at least 32 characters |
| LICENSE_SALT | License-key generation/validation dependency; preserve it |
| ADMIN_BEARER_TOKEN | Privileged operator/API token |
| ADMIN_BEARER_TOKEN_PREVIOUS | Optional overlap token during controlled rotation |
| DB_HOST / DB_PORT | Database network address |
| DB_SOCKET | Optional Unix socket instead of host/port |
| DB_DATABASE / DB_USERNAME / DB_PASSWORD | Database credentials |
| CORS_ALLOWED_ORIGINS | Comma-separated exact browser origins; empty disables cross-origin access |
| CUSTOMER_PORTAL_URL | Portal URL used during tenant-plan onboarding |
| MAIL_ENABLED / MAIL_* | Global mail defaults; stored settings can override defaults |
| STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET | Global provider credentials; tenant connections are separate |

Use separate credentials and databases for development, staging and production.
Generate random secrets locally, do not copy values from documentation. Keep
secret files readable only by the application/operator that needs them.

## Local Setup

Install Composer dependencies and prepare an empty database with an application
user. Generate keys, configure `.env`, then import `database/schema.sql` using
`php scripts/import_schema.php`. Do not rerun the fresh-schema installer as a
general production upgrade tool.

Run `php -S 127.0.0.1:8000 -t public public/index.php` for local development.
Check `/health`, then open `/admin` and enter your local admin token. There is no
default admin password. The customer account login is a different interface.
The legacy `create_admin_user.php` helper is not needed for the token-based
dashboard login.

## Demo Data

Only use a disposable local database and `APP_ENV=local`. Keep mail disabled and
leave payment credentials unset. Set a generated demo password in your environment:

```sh
export DEMO_EMAIL=demo@example.invalid
export DEMO_PASSWORD="$(openssl rand -hex 24)"
php scripts/provision_demo_tenant.php
```

Ensure the application's `.env` also selects local mode and the intended empty
database. The script adds demonstration applications, products, seven tariffs,
three licenses and synthetic operational records. It does not configure real
Stripe credentials. Existing demo credentials are not silently overwritten;
retain the same generated demo password for repeat provisioning.

The demo account belongs to the customer API. To view the administration
dashboard, use ADMIN_BEARER_TOKEN, not the demo customer's password. Screenshots
should use this synthetic dataset rather than real customer/license records.

## Hosting

Use a maintained PHP runtime with the required extensions and a patched MariaDB
installation. CI covers PHP 8.2-8.5, but integration and rollback tests are still
needed when changing the runtime. No workflow automatically deploys this project.

Serve only `public/`. Keep `.env`, Composer files, source, logs, database dumps and
private keys outside the webroot. Apache deployments can use `public/.htaccess`;
other web servers need equivalent routing, Authorization forwarding and sensitive
file restrictions. Use HTTPS and restrict administrative access.

Configure trusted reverse-proxy behavior deliberately. Client IPs affect rate
limits; forwarded protocol affects secure session cookies. Do not trust
arbitrary client-provided forwarding headers. Monitor errors without collecting
request bodies containing passwords, license keys or provider credentials.

Create required runtime storage with restrictive permissions. The PHP process
should not be able to rewrite application source or read unrelated applications'
secrets. Back up the database and the exact matching key configuration together.

## Background Work

`scripts/maintenance.php` changes state: it deactivates stale installations,
prunes rate-limit/verification records, expires licenses and sends notifications.
Review its 30-day activation, 48-hour rate-limit and 90-day verification retention
defaults before scheduling it. Review grace-period behavior and avoid overlapping
runs. Do not run it blindly as a harmless health check.

**Do not schedule `scripts/queue_worker.php` for real jobs.** It is a placeholder
that reserves and removes jobs without domain handlers. Implement and test the
handlers, retry behavior and delivery guarantees before using that queue.

SMTP and billing side effects currently run synchronously. A transactional outbox
is a future improvement, not a feature provided by the generic worker.

## Backups, Upgrades and Recovery

Back up application source/image, database, environment, runtime storage and
encrypted signing-key records. Protect backups like production. Test restoring
them into an isolated environment with outbound mail/payments disabled.

Select migrations against the current schema. Historical migrations contain
legacy app identifiers and are not necessarily safe to rerun in arbitrary order.
Before deploying, compare API contracts and frontend CSRF/session behavior, run
the suite and provider sandbox tests, and prepare a compatible rollback image.

Do not replace APP_KEY or LICENSE_SALT during routine deployment. A key migration
needs an explicit plan for encrypted data and outstanding tokens. Do not regenerate
offline keys merely because old ciphertext is unreadable. After migration,
rollback software must understand the newly written ciphertext format.

An image rollback does not automatically require a database restore. Restoring
an old dump can discard purchases and account changes made since that backup.

## Known Boundaries

- Public licensing/checkout handlers do not currently enforce generated app keys.
  License keys and admin tokens have different trust scopes; do not confuse them.
- Verification resolves app/system policies. Stored product/tariff policies are
  not all enforced there. `allow_auto_activation` is exposed in policy settings
  but is not currently read by verification; unknown installations may activate.
- A device identifier is not hardware attestation or unbreakable DRM.
- Offline tokens cannot observe revocations before reconnecting/expiring.
- The revenue dashboard can aggregate amounts with a fixed currency label; do
  not interpret mixed-currency cards as converted or reconciled financial totals.
- Google Analytics integration is configuration, not imported analytics reports.
- AI summaries/drafts are deterministic routines, not external model inference.
  Provider/privacy/limit preferences are stored but are not enforced by the
  generation services. Draft input is retained without automatic redaction;
  do not submit sensitive customer messages or credentials.
- Form submissions are not a calendar engine or a campaign-management service.
- Payment/email success paths need real provider sandbox tests beyond the local
  regression suite. Not every historical migration/browser flow is covered.

For security reports use [SECURITY.md](../SECURITY.md). For implementation history
and already repaired invariants see [REVIEW.md](REVIEW.md).
