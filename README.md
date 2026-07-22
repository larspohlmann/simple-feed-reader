# simple-feed-reader

A multi-user RSS/Atom feed reader. Symfony 7.4 LTS JSON API in `backend/`, with
an Angular SPA to follow.

## Documentation

- [Architecture: client contract and native-client readiness](docs/architecture.md)
  — the cross-cutting rules for how clients talk to the backend, and the standing
  constraint that keeps a future native iOS app viable.
- [OAuth sign-in (Google and Apple)](docs/oauth-sign-in.md) — provider setup for
  operators, and the redirect/exchange contract for the SPA.
- [Local Docker environment](docs/local-docker.md) — run the whole stack
  (MySQL, PHP, nginx with TLS, Mailpit) in Docker.
- [Design spec](docs/superpowers/specs/2026-07-21-simple-feed-reader-design.md)
- [Implementation plans](docs/superpowers/plans/)
