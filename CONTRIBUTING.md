# Contributing

Open a focused pull request with a regression test for behavioral changes.
Use PHP 8.2-compatible syntax and the existing service/repository boundaries.
Run `composer lint`, `composer test:unit` and the disposable-database integration
suite described in [testing](docs/TESTING.md). Do not modify production to test a PR.

Keep API compatibility explicit, especially activation responses, signature
claims, tenant boundaries, webhook retries and customer CSRF/session behavior.
Never include real credentials or customer data in a fixture. Use generated
values and `example.invalid` addresses. Changes to SQL migrations need a rollback
or compatibility explanation; avoid destructive schema operations by default.

Contributions to this project are provided under the [MIT License](LICENSE).
Keep third-party copyright and license notices intact and document new dependency
licenses in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
