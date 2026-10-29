# Security Policy

Please use GitHub's **Security > Report a vulnerability** for sensitive reports.
Do not put access tokens, signing keys, customer records, production dumps or
exploitable credentials in public issues, comments or pull requests.

This repository never needs production credentials for CI. Keep `.env`, Composer
authentication, private keys, archives, database dumps and storage outside Git.
All example addresses use reserved example domains; test secrets are generated
locally. If a real credential is exposed, revoke/rotate it first: deleting a file
or rewriting Git history is not sufficient remediation.

Before deployment, use a supported patched PHP runtime, HTTPS, restricted
administrative access, explicit CORS origins and tested backups. Preserve APP_KEY
when upgrading; losing it can make existing encrypted credentials unreadable.
Restore damaged offline signing keys rather than generating replacements silently.

Automated checks cover tests, syntax, known dependency advisories, secrets and
repository hygiene. They do not constitute an independent security certification.
Known engineering limits and deployment compatibility are recorded in
[review notes](docs/REVIEW.md).
