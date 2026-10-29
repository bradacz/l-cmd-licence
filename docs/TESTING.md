# Testing

## Fast Checks

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer lint
composer test:unit
composer audit --locked
```

## Integration Suite

Create a disposable MariaDB database such as `lcmd_test` and a user with full
privileges on that database only. Tests import schema and write/delete fixtures.
They are not read-only and must never use real customer data.

Provide `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` through
your local environment, or `DB_SOCKET` for a Unix socket. Then run:

```sh
LCMD_TEST_DB=1 DB_DATABASE=lcmd_test composer test
```

The database name must end in `_test`. Tests do not load the application's `.env`.
APP_KEY and LICENSE_SALT are generated per run; outbound mail is disabled. The
concurrency tests launch child PHP processes against the same disposable database.

CI runs all tests, not just unit tests, on PHP 8.2 through 8.5 with MariaDB 11.8.
Its database password is randomly generated at runtime, masked in logs and never
stored in source. No production GitHub secrets are needed.

## Coverage Limits

The suite checks application logic, concurrency and persistence. It does not
execute real purchases, send real email, exercise every browser workflow or audit
the server operating system. Stripe test-mode and SMTP sink tests should precede
changes to provider contracts. Historical migrations must be selected against the
actual schema state; the suite does not claim every historical upgrade path works.

For an offline secret check, install Gitleaks and run
`gitleaks git --redact --log-opts=--all`. CI also rejects runtime dumps, private-key
files and local machine paths using `scripts/repository_hygiene.php`.
