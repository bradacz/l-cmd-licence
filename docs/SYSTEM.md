# System Guide

## Purpose

L-CMD License Server is a self-hosted central authority for software access: which
customer has a license, which application it belongs to, how many installations
may use it, when it expires and which features are enabled. Applications call the
API instead of duplicating this business logic in every product.

You operate the PHP backend and MariaDB database, configure your payment/mail
providers and decide how each client enforces licensing decisions. This is
software you can run yourself, not a hosted licensing-service subscription.

## Main Concepts

| Entity | Meaning |
| --- | --- |
| Tenant | Organizational account owning applications, users and integration settings |
| Membership | A customer's relationship to a tenant and its role |
| Application | A registered integration identified by a unique slug |
| Product | A sellable item assigned to one or more applications |
| Tariff | Product license type, currency, amount and Stripe price reference |
| License | Issued entitlement with customer, status, expiry and installation limit |
| Activation | Association of a license with an installation identifier |
| Verification | Recorded license check and result |
| Policy | Activation, platform, feature and token-lifetime configuration |
| Webhook receipt | Event identity used to make delivery retries safe |

Tenant service plans control platform capabilities such as offline licenses.
They are distinct from product tariffs sold to customers, and both are distinct
from the repository's MIT software license.

## Interfaces

The application API serves catalogs, checkout, activation, verification and
deactivation. The customer API handles account onboarding, sessions, memberships
and tenant-owned settings. The administration dashboard provides operator views
for products, applications, licenses, policies, integrations and audit history.

The admin token is a privileged operator credential. Customer passwords and
sessions are a separate identity model. Never expose an admin token to customers
as a shortcut for building a portal.

## License Lifecycle

Licenses are issued administratively or through successful paid-checkout events.
Common models are monthly, annual and lifetime access. Legacy project/white-label
identifiers remain for compatibility. A lifetime license with an explicit expiry
is still subject to that expiry.

An active license can activate an installation. Verification checks status,
application ownership, platform policy, expiry and activation capacity. It may
automatically activate an unknown installation, so it is not a read-only request.
Pending licenses cannot become valid just by calling verification.

Deactivation releases an installation slot. Expired, revoked and refunded
licenses are rejected. Subscription events can renew paid access or cancel it.
Clients must inspect the returned license state rather than treating an HTTP
connection as proof of valid access.

## Online and Offline Access

Online verification returns an expiring HS256 token bound to the license,
application and installation. Its signing secret stays on the server. Never
distribute APP_KEY to desktop/mobile/browser clients to validate this token.

For public-key validation, request the separate offline token when allowed by
the tenant plan. It uses RS256. Clients verify the signature with a trusted public
key and also validate expiry, license/application identity and installation
binding. Signature validity alone is insufficient.

Offline access trades immediate revocation for availability. Choose an appropriate
TTL and reconnection policy: a disconnected device cannot instantly observe a
new revocation. Offline tokens include license information and must not be logged
or published casually.

## Policies and Device Identity

The verification service currently resolves application policies before system
policies. It consumes activation limits, token TTL, features, allowed platforms
and grace days. Product/tariff policy editors also exist, but their stored values
are not all consumed by this verification path.

The installation hash is a stable client-generated identifier, not hardware
attestation. Persist it instead of generating it on every startup. Avoid raw
hardware serials or unnecessary personal data. Clients enforce feature access.

## Operations

The dashboard shows counts, activation activity, products, applications and
history. Webhook records aid payment troubleshooting; audit records track
administrative actions. These records can contain sensitive licensing/customer
information and need access control and retention policies.

Revenue cards are an operational overview, not accounting or currency conversion.
Reconcile actual payments and currency-specific totals with the payment provider.

## FAQ

**Can a non-PHP product connect?** Yes. Only the backend requires PHP. Clients use
HTTPS/JSON and optionally an RS256 verifier.

**Can I use the project commercially?** Yes, under the MIT license terms. Your own
customer licenses and third-party service/dependency terms are separate.

**Does it replace an app store?** No. Native App Store/Google Play purchase,
receipt validation and storefront pricing are not implemented.

**Does it prevent all piracy?** No. Client software can be modified; this is not
unbreakable DRM. Combine licensing with server-side access enforcement where
appropriate.

**Do AI modules call external models?** Not currently. They generate deterministic
local summaries and templates. Provider settings are an extension point.

**Is the newsletter endpoint a campaign service?** No. It accepts interest-form
submissions and sends notifications, not bulk campaigns.

Continue with [integrations](INTEGRATIONS.md), [API](API.md) and
[operations](OPERATIONS.md).
