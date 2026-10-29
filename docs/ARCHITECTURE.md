# Architecture

## Request Boundary

`public/index.php` loads the application bootstrap, constructs a request and
dispatches routes. `Http/Api.php` serves application-facing licensing endpoints,
`Http/CustomerApi.php` serves customer operations, and `Http/AdminWeb.php` renders
the administration UI. Unexpected internal errors are sanitized by `ClientError`.

The customer frontend is separate. Credentialed browser access is explicitly
configured with `CORS_ALLOWED_ORIGINS`, and state-changing customer requests must
follow the CSRF contract in the router. CORS is not an authorization mechanism.

## Domain and Persistence

Services coordinate business decisions; repositories use prepared PDO statements.
Tenant/app context is resolved before accessing tenant-owned records. MariaDB
transactions and parent-row locks serialize license activation/deactivation, while
webhook receipts and scoped locks prevent repeated license issuance.

Important invariants are tested directly: pending licenses do not activate;
explicit expiry applies to lifetime licenses too; counters match live
activations; an invitation cannot reset an existing account's global password;
and a tenant's Stripe credential cannot authorize another tenant's operation.

## Secrets and Signatures

Configuration comes from the environment or an untracked `.env`. APP_KEY is a
long-lived encryption dependency, not a disposable deployment setting. SecretBox
writes authenticated ciphertext and can read legacy ciphertext for migration.
Online JWTs bind license and installation claims. Offline signing keys are
initialized under a lock and must not be silently replaced if decryption fails.

## Deliberate Boundaries

There is no dependency-injection framework, ORM or bundled customer SPA. HTTP
adapters and the server-rendered admin UI are comparatively large legacy classes;
the review does not pretend they have already been split into small controllers.
External billing and mail effects are synchronous rather than an outbox. Future
changes should preserve API contracts and add tests at the service boundary.
