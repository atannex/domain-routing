# Security policy

Please report vulnerabilities privately through GitHub's "Report a vulnerability" form on this repository, or email atangageihlekecollins@gmail.com. Don't open a public issue. We aim to reply within 72 hours, and we credit reporters unless you ask us not to.

Supported versions: the latest minor release of the current major.

## Design notes

- Unknown hosts get a 404 by default and never become the root of generated links.
- Host parameter values are validated against their pattern before a URL is built.
- Handoff tokens are 256-bit random values. Only their SHA-256 is stored, they're single use (redeemed under an atomic lock), expire after 60 seconds, and are bound to the user, guard and target host. Responses carry `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.
- Hosts are compared in punycode; `domains:doctor` flags internationalised hosts so you can check them for look-alike characters.
