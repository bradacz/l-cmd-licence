# Integration Guide

## Choose a Connection Pattern

Any HTTPS/JSON client can use the server. Native desktop apps can use their
platform networking library; web backends can use an HTTP client; Unity can use a
C# networking service; Unreal can use its HTTP module. The same API is usable
from Swift, Kotlin, Python, Go and JavaScript. These are connection patterns, not
a claim that language-specific SDKs or engine plugins are bundled.

A product website normally loads the catalog and starts checkout. The installed
application activates and verifies licenses. A customer portal uses customer
sessions and CSRF protection. Privileged automation uses the admin API only from
a trusted backend.

## Application Setup

1. Create an active application and record its unique slug.
2. Create products/tariffs and explicitly bind the intended products to the app.
3. Configure activation capacity, features, platforms and token lifetime.
4. Issue a test license for the app or complete a Stripe test-mode checkout.
5. Generate one installation identifier and persist it across restarts.
6. Call the app-scoped endpoints shown in [API.md](API.md).
7. Handle valid, invalid, expired, revoked and capacity-exceeded states.
8. Test deactivation/reinstallation and loss of connectivity before release.

Keep secrets out of distributed clients. A license key is an access credential;
avoid logging it. Never embed the admin token, APP_KEY, Stripe secret, SMTP
password or an offline private key in an app or website bundle.

### Minimal Verification Example

This example takes values supplied by your application. It does not contain a
working license or generate new installations on every call.

```js
async function verifyLicense(baseUrl, appSlug, licenseKey, installationHash) {
  const response = await fetch(
    `${baseUrl}/api/v1/apps/${encodeURIComponent(appSlug)}/license/verify`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        license_key: licenseKey,
        installation_hash: installationHash,
        metadata: { platform: 'desktop', app_version: '1.0.0' },
      }),
    },
  );
  const result = await response.json();
  if (!response.ok || result.status !== 'valid') {
    throw new Error(result.message || result.error || 'License verification failed');
  }
  return result;
}
```

For production, add request timeouts, bounded retries/backoff, secure local
storage and an explicit offline policy. Retain structured error/state information
instead of granting access on network errors. Do not trust decoded JWT claims
without an appropriate authenticity check.

## Product Website and Checkout

Use `GET /api/v1/apps/{app_slug}/products?currency=EUR` to retrieve the visible
catalog. Currency selection chooses configured tariffs; it does not convert
prices. Bind products to applications explicitly, even on single-product sites.

Configure each app's public base URL and checkout success/cancel URLs. Create a
checkout session with `priceId`, `licenseType` and `customerEmail`, then redirect
to the returned Stripe URL. The success-page redirect is not evidence of payment:
license issuance depends on validated payment events. Poll checkout status using
the returned session ID when needed; treat that ID as sensitive.

Browser clients need an exact origin in `CORS_ALLOWED_ORIGINS`. This is independent
of authentication. Do not use wildcard credentialed access or forward admin
credentials into browser code.

## Stripe

Configure global platform credentials and, when required, tenant-specific Stripe
connections. Use test-mode credentials and matching prices while developing.
The configured price must correspond to the product tariff and license type.
Tenant-plan billing uses platform credentials, not an arbitrary tenant's key.

The incoming endpoint is `POST /api/v1/webhook/stripe`. Preserve the raw request
body and `Stripe-Signature` header through the reverse proxy. Implemented events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `invoice.payment_succeeded`
- `charge.refunded`
- `customer.subscription.deleted`

An unpaid checkout completion must not issue a license. Retries are deduplicated;
signing scope and tenant ownership are checked. Validate subscription invoice-line
shapes with the Stripe API version used by your account. Local unit/integration
tests do not replace real provider sandbox tests. There is no generic PayPal,
Paddle, native-store or arbitrary-payment-provider adapter.

## SMTP and Website Forms

Configure global SMTP settings or the appropriate tenant connection. App-specific
notification settings and templates support licensing messages and customization.
Test with a mail sink before using real recipients. `MAIL_ENABLED=0` is the
development default; also check stored database settings before enabling delivery.

See [email, templates and replies](../README.md#email-templates-and-replies) for
the SMTP UI, per-app Reply-To routing, notification switches, template fallback
rules and the distinct placeholder formats. Reply-To directs replies to your mail
provider; it is not an inbound-email processor or automatic support responder.

Contact, booking-request and newsletter-interest forms use the public form API.
They validate submissions, apply spam/rate controls and send notification emails.
Optional Turnstile site/secret settings enable CAPTCHA verification. A booking
request is not a calendar reservation; newsletter interest is not campaign
management, double-opt-in subscription handling or an unsubscribe service.

## Offline Licensing

Ask for `metadata.request_offline_token=true` on verification. If enabled by the
tenant plan, the response includes an `offline` object with token, expiry,
algorithm and key ID. The default lifetime is 30 days, configurable from 1 to 90
days through the tenant setting, and capped by the license's explicit expiry.

An authenticated tenant operator obtains the public key through
`GET /customer-api/apps/{id}/offline-public-key`. This is not an anonymous key
distribution endpoint. Export the public key through a trusted release/provisioning
process, pin the expected key ID and plan rotation deliberately.

Use a maintained JWT/cryptography library restricted to RS256. Check the signature,
expiry, token type, expected tenant/application/license and installation hash.
Allow only a deliberate small clock tolerance. Never accept an HS256 token with
the public key as its secret. Never ship the server's private key or APP_KEY.

## Automation and Extensions

n8n, Make, cron jobs and internal systems can make ordinary HTTP requests. Admin
API tokens are globally privileged, not fine-grained per-app service accounts;
keep them on trusted infrastructure and limit access at the network boundary.
There is no bundled connector or general outgoing webhook subscription engine.

Google Analytics currently stores configuration and links to the provider; it
does not import reporting data or install tags on your website. AI insights and
assistant modules currently generate local deterministic text. Adding an external
model needs an explicit adapter, authorization, privacy, cost and retry design.
The [AI module reference](../README.md#ai-modules-and-integration-settings) lists
the supported reports/drafts and explains which provider/privacy settings are
stored but not enforced. Do not supply sensitive input to the draft history.

Mobile applications can use HTTPS, but App Store/Google Play purchase validation
and native storefront prices require a separate integration. This server does
not automatically receive or replace those purchases.
