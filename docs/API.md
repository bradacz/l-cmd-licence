# API Guide

The base URL belongs to your deployment, for example `https://license.example.invalid`.
Send JSON with `Content-Type: application/json`. Examples below are templates,
not working credentials. The complete route inventory is in
[`public/index.php`](../public/index.php); handlers are in
[`Api.php`](../src/Http/Api.php) and [`CustomerApi.php`](../src/Http/CustomerApi.php).

## Authentication Boundaries

| Surface | Access model |
| --- | --- |
| `/health`, public catalog/checkout/forms | Public, subject to endpoint validation and applicable limits |
| Application licensing | License key plus installation identifier and app-scoped validation |
| `/api/v1/admin/*` and `/api/v1/customer/licenses` | Privileged `Authorization: Bearer` admin token |
| `/customer-api/*` | Customer session; mutating methods also require CSRF |
| `/admin/*` | Admin-token login followed by an operator session and form CSRF |
| Stripe webhook | Raw-body signature verification with the configured webhook secret |

The system manages client/server application keys, but the current public
activation, verification and checkout handlers do not require an application-key
header. Do not assume those generated keys enforce access where the handler does
not validate them. The admin token is never a public client credential.

## Application Endpoints

Prefer app-scoped paths for new integrations. Legacy unscoped license/catalog
paths remain for compatibility.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Application/database readiness |
| GET | `/api/v1/apps/{app_slug}/products` | Catalog; optional `currency` query |
| POST | `/api/v1/apps/{app_slug}/checkout/create` | Create Stripe session |
| GET | `/api/v1/apps/{app_slug}/checkout/status` | Status using `session_id` query |
| POST | `/api/v1/apps/{app_slug}/license/activate` | Explicitly activate installation |
| POST | `/api/v1/apps/{app_slug}/license/verify` | Verify and potentially auto-activate |
| POST | `/api/v1/apps/{app_slug}/license/deactivate` | Release installation |
| POST | `/api/v1/webhook/stripe` | Receive signed provider events |

### Activate

```json
{
  "license_key": "REPLACE_WITH_YOUR_ISSUED_TEST_LICENSE",
  "domain": "app.example.invalid",
  "installation_hash": "example-installation-001",
  "platform": "desktop",
  "php_version": "8.5.0",
  "cms_version": "1.0.0"
}
```

The first three fields are required. `domain` must be a hostname without a scheme
or path. `installation_hash` is 8-64 characters from letters, digits, `.`, `_`
and `-`. Use a real generated license; the placeholder does not pass validation.
Platform must match any configured allowlist. Version fields are optional legacy
metadata, not proof of a client runtime.

Success has `status: success`, `license`, `activation_id`, `capabilities` and
`device_binding`. Duplicate activation is not an idempotent success: use verify
for an already-active installation. Capacity failures include
`code: MAX_ACTIVATIONS_REACHED`.

### Verify

```json
{
  "license_key": "REPLACE_WITH_YOUR_ISSUED_TEST_LICENSE",
  "installation_hash": "example-installation-001",
  "metadata": {
    "platform": "desktop",
    "app_version": "1.0.0",
    "request_offline_token": true
  }
}
```

Success has `status: valid`, an online `token`, `capabilities`, `device_binding`
and optionally `offline`. The online token includes features, expiry and identity
claims. It uses HS256; local verification by a distributed client must not require
sharing the server secret. Use the separate RS256 offline mechanism when needed.

Business failures include `invalid`, `expired`, `revoked` and `limit_exceeded`.
Unknown or inactive apps and app/license mismatches return 404 at the scoped
boundary. Calling verify can create an activation or update counters/timestamps.

### Deactivate

```json
{
  "license_key": "REPLACE_WITH_YOUR_ISSUED_TEST_LICENSE",
  "installation_hash": "example-installation-001",
  "reason": "moving-to-another-device"
}
```

The first two fields are required; reason is optional and at most 255 characters.
The response uses `status: deactivated`. Repeating deactivation does not decrement
the counter twice. App-scoped ownership checks still apply.

### Checkout

```json
{
  "priceId": "REPLACE_WITH_A_CONFIGURED_TEST_PRICE_ID",
  "licenseType": "annual",
  "customerEmail": "customer@example.invalid"
}
```

The tariff must exist, be active and match the license type. The app slug is
taken from the URL. Configure success/cancel URLs on the application. The result
contains `sessionId` and `url`; use `session_id` when requesting checkout status.
Validated billing metadata cannot be overridden by client-supplied metadata.

## Customer Portal Contract

Fetch `GET /customer-api/csrf` first and retain its session cookie. Read the returned
`csrf_token` and send it as `X-CSRF-Token` for POST/PATCH/DELETE requests, including
login, onboarding and password-reset requests. Browser clients use
`credentials: 'include'` and an explicitly allowed origin. After authentication
changes, fetch a fresh token as needed.

Account endpoints cover `auth/register`, `auth/activate`, `auth/set-password`,
`auth/login`, `auth/logout`, `auth/forgot-password`, `auth/reset-password` and
`auth/switch-tenant`. Authenticated reads include `me`, `memberships`, `tenant`
and `billing/plan`.

Tenant resource families cover apps, products, tariffs, licenses, policies,
notifications, mail templates, app-key management, Stripe/SMTP settings and
operational records. Integration helpers include:

- `GET /customer-api/apps/{id}/integration-guide`
- `GET /customer-api/apps/{id}/offline-public-key`

The second returns `offline_verification.algorithm`, `key_id` and
`public_key_pem`. Access is authenticated and scoped to the tenant. Neither
response contains the offline private key.

## Other Endpoints

Administrative API routes create/list/update licenses, expose stats and manage
SMTP/Stripe settings and templates. Customer-license lookup by email is also
admin-protected, despite its `/api/v1/customer/licenses` path.

Public forms use `contact/submit`, `booking/submit` and `newsletter/submit` under
`/api/v1/`, plus `GET /api/v1/public/forms/config`. Consult
[`PublicFormService`](../src/Services/PublicFormService.php) for each form's fields,
including optional `turnstileToken`, honeypot and submission timing data.

## Errors and Retries

| HTTP status | Typical meaning |
| --- | --- |
| 200 | Successful operation; still inspect the JSON business state |
| 400 / 422 | Malformed input or business validation failure |
| 401 | Missing/invalid operator or customer authentication |
| 403 | CSRF failure or insufficient authorization/capability |
| 404 | Unknown app/resource or scoped ownership mismatch |
| 429 | Rate limit; back off rather than immediately retrying |
| 500 / 503 | Internal failure or unavailable dependency |

Error bodies differ between legacy and customer handlers (`error`, `message`,
`status`, `code`). Do not parse human-facing prose as a stable machine code.
Apply timeouts and bounded backoff, and never grant a license because a request
failed. Avoid blindly replaying checkout creation or explicit activation after an
ambiguous network outcome. Webhook retries are a separate idempotent flow.
