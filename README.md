# simple-feed-reader

[![CI](https://github.com/larspohlmann/simple-feed-reader/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/larspohlmann/simple-feed-reader/actions/workflows/ci.yml)

A multi-user RSS/Atom feed reader. Symfony 7.4 LTS JSON API in `backend/`, with
the Angular 20 SPA that delivers the reader UI and auth in `frontend/`.

![The reader: entry list and reader pane side by side](docs/screenshots/desktop-reader.png)

<p>
  <img src="docs/screenshots/desktop-cards.png" alt="Card view on desktop" width="66%">
  <img src="docs/screenshots/mobile.png" alt="Mobile view" width="29%">
</p>

## Quick start (Docker)

Run the whole app on your own machine with one command. You need three tools
first: [Docker](https://docs.docker.com/get-docker/) (running),
[git](https://git-scm.com/downloads), and
[mkcert](https://github.com/FiloSottile/mkcert#installation) (`brew install
mkcert` on macOS). Then:

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
```

The installer clones the project into `./simple-feed-reader`, checks out the
latest release, creates a local HTTPS certificate, and starts every service.
When it finishes, open these:

| What | Where |
|---|---|
| Reader app (dev server) | http://localhost:4200 |
| API health check | https://localhost:8443/api/health |
| Mailpit inbox (sent mail) | http://localhost:8025 |

> **Read before you pipe to bash.** You can inspect exactly what runs at
> [scripts/install.sh](scripts/install.sh). The installer never deletes data and
> asks before it changes your system trust store.

### Everyday scripts

Run these from inside the `simple-feed-reader` directory:

| Task | Command |
|---|---|
| Update to the latest release | `./scripts/update.sh` |
| Start the dev frontend (:4200) | `./scripts/frontend-start.sh` |
| Stop the dev frontend | `./scripts/frontend-stop.sh` |
| Start the production preview (:8444) | `./scripts/frontend-prod-start.sh` |
| Stop the production preview | `./scripts/frontend-prod-stop.sh` |
| Stop everything (keeps your data) | `docker compose down` |

The full manual walkthrough — what each service is, step debugging, and the
gotchas — lives in [docs/local-docker.md](docs/local-docker.md).

## Documentation

- [Frontend workspace](frontend/README.md) — the Angular 20 SPA: dev server, the
  quality gate, theming, and the bearer-token auth model.
- [Architecture: client contract and native-client readiness](docs/architecture.md)
  — the cross-cutting rules for how clients talk to the backend, and the standing
  constraint that keeps a future native iOS app viable.
- [OAuth sign-in (Google and Apple)](docs/oauth-sign-in.md) — provider setup for
  operators, and the redirect/exchange contract for the SPA.
- [Local Docker environment](docs/local-docker.md) — run the whole stack
  (MySQL, PHP, nginx with TLS, Mailpit) in Docker.
- **First-run setup:** creating the initial admin — see [docs/first-run-setup.md](docs/first-run-setup.md).
- [Cutting a release](docs/releasing.md) — how a `vX.Y.Z` tag on `main` becomes
  the version the install and update scripts hand to users.
- [Design spec](docs/superpowers/specs/2026-07-21-simple-feed-reader-design.md)
- [Implementation plans](docs/superpowers/plans/)
- [Contributing](CONTRIBUTING.md) — issue-first workflow, branch conventions,
  and the quality gate. Licensed under the [MIT license](LICENSE); notable
  changes land in the [changelog](CHANGELOG.md).
