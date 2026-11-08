# Documentation

## Using it

- [Features](features.md) — everything the reader does, by area.
- [Screenshot gallery](screenshots.md)
- [Running in production (Docker)](docker-production.md) — the installer and its
  packages, the everyday scripts, TLS or reverse proxy, mail, updates, backups.
- [First-run setup](first-run-setup.md) — creating the initial admin.
- [OAuth sign-in (Google and Apple)](oauth-sign-in.md) — provider setup for
  operators, and the redirect/exchange contract for the SPA.
- [How a "For you" run works](recommendations-runs.md) — what happens after
  pressing Refresh, closing the browser, stopping, resuming.
- [Account backup and restore](backup.md) — what a backup file carries, what it
  deliberately drops, and what a restore does to the account.

## Developing

A multi-user RSS/Atom feed reader: a Symfony 7.4 LTS JSON API in `backend/`,
with the Angular 20 SPA that delivers the reader UI and auth in `frontend/`.

For the development stack — live-reloading frontend, xdebug, and
[Mailpit](https://mailpit.axllent.org/) catching all outgoing mail locally —
use the dev installer (it additionally needs
[mkcert](https://github.com/FiloSottile/mkcert#installation)):

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install-dev.sh | bash
```

- [Local Docker environment](local-docker.md) — the manual walkthrough: the
  whole stack (MySQL, PHP, nginx with TLS, Mailpit) in Docker.
- [Frontend workspace](../frontend/README.md) — the Angular 20 SPA: dev server,
  the quality gate, theming, and the bearer-token auth model.
- [Architecture: client contract and native-client readiness](architecture.md)
  — the cross-cutting rules for how clients talk to the backend, and the
  standing constraint that keeps a future native iOS app viable.
- [Design language](design-language.md) — tokens, the shared component catalog
  and the recorded exceptions.
- [The project homepage](homepage/README.md) — the static page in
  `docs/homepage/`, its screenshots shared with the gallery, and how it deploys.
- [Cutting a release](releasing.md) — how a `vX.Y.Z` tag on `main` becomes the
  version the install and update scripts hand to users.
- [Design spec](superpowers/specs/2026-07-21-simple-feed-reader-design.md) and
  [implementation plans](superpowers/plans/)
- [Contributing](../CONTRIBUTING.md) — issue-first workflow, branch conventions,
  and the quality gate.
