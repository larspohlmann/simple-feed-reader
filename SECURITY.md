# Security Policy

## Reporting a vulnerability

Please report vulnerabilities **privately** through GitHub:
[Report a vulnerability](https://github.com/larspohlmann/simple-feed-reader/security/advisories/new)
(Security tab → "Report a vulnerability").

**Do not open a public issue for a security problem.** Public issues are
visible immediately, including to anyone who would exploit the report.

You can expect an initial response within a few days. Please include steps to
reproduce, the affected endpoint or component, and the impact you see.

## Scope

Reports are especially welcome for the security-sensitive surfaces:

- Authentication: JWT bearer tokens, login rate limiting, the ALTCHA
  proof-of-work challenge, password reset mails.
- OAuth/OIDC sign-in (Google and Apple): state binding, token validation,
  account linking.
- Outbound feed fetching and scraping: SSRF protections (redirect
  re-validation, IP pinning, response caps) in the fetch pipeline.
- Multi-user isolation: one user reaching another user's subscriptions,
  entries, or settings.

## Supported versions

Only the latest release (the highest `vX.Y.Z` tag) and the current `develop`
branch receive security fixes.
