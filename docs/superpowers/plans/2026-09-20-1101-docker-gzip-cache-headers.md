# Plan: #1101 — compress and cache-header the Docker stacks

Spec: GitHub issue #1101 (binding authority).

## Context

Neither Docker stack compresses responses, and the Docker production stack sets no
`Cache-Control` for hashed bundles. Strato production already has both
(`deploy/strato/.htaccess`, #254). Self-hosters of the Docker prod stack get the
pre-#254 behaviour, and dev latency/payload measurements don't reflect Strato.

## Decision and design

- **One shared gzip snippet** `docker/nginx/gzip.conf`, used by BOTH stacks (one
  physical file, so the type list can never drift between dev and prod — the
  drift is the bug this issue is closing):
  - dev nginx bind-mounts it (`docker-compose.yml`) and `include`s it in its
    `:443` server;
  - the prod web image COPYs it (`docker/web/Dockerfile`) and both `http.conf`
    and `tls.conf` `include` it in their serving server.
  - Type list mirrors `deploy/strato/.htaccess`, MINUS `text/html` (nginx
    compresses html by default; listing it is wrong).
- **Cache-Control on the prod web stack only** (`http.conf`, `tls.conf`):
  - content-hashed bundles (`-[A-Z0-9]{8}\.(js|mjs|css)$`) → `public, max-age=31536000, immutable`;
  - `index.html` → `no-cache, must-revalidate`.
  - Dev nginx serves the API off `/app/public`; the SPA bundle is served by the
    `:4200` dev server, so cache headers don't apply to the dev stack.
- The backup download is `application/zip` (`BackupDownloadResponseFactory`, already
  compressed) — not in `gzip_types`, so nginx won't touch it; no explicit exclusion.

## Gotchas encountered

- nginx parses `{8}` in an unquoted regex `location` as a config block brace, so the
  bundle location regex must be **double-quoted**: `location ~ "-[A-Z0-9]{8}\.(?:js|mjs|css)$"`.
- `gzip_min_length 1024` means tiny responses (e.g. `/api/version`) are not
  compressed — intended.

## Verification (done)

- `nginx -t` passes for the dev config and for both prod configs (rendered
  `__TLS_PORT_SUFFIX__`, on the compose network so the `php` upstream resolves).
- Dev stack: `/api/entries?tag=80` 141,832 → 20,021 bytes, `content-encoding: gzip`,
  `vary: Accept-Encoding`.
- Prod web image built and run: a hashed bundle returns the immutable
  `Cache-Control` + `Content-Encoding: gzip`; `index.html` returns
  `no-cache, must-revalidate`; a CSS bundle 16,877 → 4,639 bytes.

## Acceptance

- Both stacks gzip the listed types; `?tag=80` < 40 KB (measured 20 KB).
- The type list matches `deploy/strato/.htaccess` (minus html, per nginx defaults).
