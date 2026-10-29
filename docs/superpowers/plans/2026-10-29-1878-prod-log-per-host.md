# #1878 Prod log per host — implementation plan

**Goal:** Strato's prod log keeps every record whole.

**Root cause (measured on the host, 2026-10-29):** appends to one file from several machines on
Strato's network storage are not atomic across clients.
- 3 shell hosts × 4 writers × 300 lines into one file: 1963 of 4800 records intact, 246 broken
  lines, 177,623 NUL bytes. That is the same damage as in `prod-2026-10-28.log`.
- 8 writers on one host into one file: 4000 of 4000 intact.
- `flock` is no fix: with `LOCK_EX`, 8 writers on one host managed about one line every 30 s.

## Design

- The prod `main` handler writes `prod-<host>-YYYY-MM-DD.log` instead of `prod-YYYY-MM-DD.log`, so
  each machine appends only to its own file. `prod-*.log` globs (`check-authorization-header.sh`,
  `docs/docker-production.md`) still match.
- `App\Service\Logging\Factory\HostLogFileHandlerFactory` builds the `RotatingFileHandler`
  (7 daily files, level info, JSON formatter). It falls back to the plain `prod.log` name when
  `gethostname()` fails. It is wired as a `type: service` handler in `when@prod`.
- `docker-compose.prod.yml` pins `hostname: php` and `hostname: worker`. Otherwise every container
  recreate starts a new host name and leaves the old files unrotated.

## Tests

- Factory unit test: the handler writes to `<dir>/prod-<host>-<date>.log` in a temp directory, and
  the fallback name is used when there is no host.

## Docs

- `deploy/strato/README.md`: one line on where the logs are and how to read them together.
