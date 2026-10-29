# Third-Party Notices

The MIT license applies to this project's own source and documentation. It does
not relicense third-party libraries, services, fonts or icons. Dependency source
is installed through Composer, not vendored in Git.

| Dependency | Purpose | License in the current lock file |
| --- | --- | --- |
| PHPMailer | SMTP and message delivery | LGPL-2.1-only |
| PHPUnit and its main components | Development/test runner | BSD-3-Clause |
| nikic/php-parser | Development dependency | BSD-3-Clause |
| myclabs/deep-copy | Development dependency | MIT |

Run `composer licenses` for the full installed inventory. Preserve upstream
notices and comply with their terms when distributing dependencies. Dependency
updates may change the inventory.

The administration UI references Montserrat and Material Symbols through Google
Fonts. These assets are not included here; review their upstream licenses and
privacy implications before downloading, self-hosting or redistributing them.
Stripe, SMTP and other service providers retain their own terms. MIT does not
grant accounts or access to third-party APIs.

Canonical license references:
[Open Source Initiative](https://opensource.org/license/mit) and
[SPDX MIT](https://spdx.org/licenses/MIT.html).
