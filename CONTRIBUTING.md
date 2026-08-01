# Contributing

Thanks for your interest in simple-feed-reader. This page explains how a
change makes it into the project. The short version: **start from an issue,
branch off `develop`, pass the quality gate, open a PR.**

## Before you start: open or claim an issue

Every change starts as a [GitHub issue](https://github.com/larspohlmann/simple-feed-reader/issues)
— a bug report, a feature idea, or a question. Comment on the issue you want
to work on so it can be assigned to you. Please don't open a pull request
without an issue behind it; the project follows written plans and strong
conventions, and a surprise PR is likely to conflict with them.

## Setting up a development environment

The whole stack (MySQL, PHP-FPM, nginx with TLS, Mailpit) runs in Docker.
[docs/local-docker.md](docs/local-docker.md) is the full walkthrough; the
one-line installer in the [README](README.md#quick-start-docker) is the fast
path. The frontend dev server runs natively:

```bash
cd frontend && npm ci && npm start   # http://localhost:4200
```

## Branches and commits

The project uses git-flow:

- Branch off `develop` — never `main`. `main` only fast-forwards from
  `develop` at release time ([docs/releasing.md](docs/releasing.md)).
- Branch names embed the issue number: `feature/67-repo-meta-docs`,
  `fix/112-arbitrary-join-on`.
- Commit messages follow `type(#issue): summary`, for example
  `feat(#206): one-line Docker installer`. Types in use: `feat`, `fix`,
  `test`, `docs`, `refactor`, `chore`.

## The quality gate

A PR must pass everything CI runs. Run it locally first.

Backend (from `backend/`):

```bash
composer check       # PHP_CodeSniffer (PSR-12) + PHPStan level max
composer md          # PHPMD codesize — every touched src file must be clean
php bin/phpunit      # unit/integration suite (SQLite)
```

Also run the MySQL leg against the Docker stack:

```bash
docker compose exec php vendor/bin/phpunit
```

Frontend (from `frontend/`):

```bash
npm run check        # ESLint + Prettier + Stylelint + Jest
```

Style expectations beyond the linters — intention-revealing names, short
single-purpose functions, guard clauses, typed exceptions — are spelled out
in [CLAUDE.md](CLAUDE.md); the linters enforce most of it mechanically.

## Opening the pull request

- Target `develop`.
- Put `Closes #NN` in the PR body so the issue closes on merge.
- Describe what changed and why; link any design doc you followed.

## Code of conduct

Participation is covered by the [code of conduct](CODE_OF_CONDUCT.md).
Security problems go through [private reporting](SECURITY.md), not public
issues.
