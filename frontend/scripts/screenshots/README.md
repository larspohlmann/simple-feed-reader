# Regenerating `docs/screenshots`

The gallery is captured against the dev stack on `http://localhost:4200` with Playwright,
on a demo account that follows a handful of mainstream feeds. Run everything from `frontend/`.

```bash
cat > .env.screenshots <<'EOF'
SHOT_EMAIL=jamie.park@example.com
SHOT_PASSWORD=…
EOF

# Sign-in and admin pages: any admin account works (the user list and proxy page are stubbed).
# Everything else: the demo account.
node --env-file=.env.screenshots scripts/screenshots/shots.mjs public admin library

# The Listening shots need podcasts the demo does not follow: borrow some, shoot, give them back.
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs        # prints PODCAST_TAG=<id>
PODCAST_TAG=<id> node --env-file=.env.screenshots scripts/screenshots/shots.mjs listening
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs teardown
```

`shots.mjs` takes set names (`public`, `admin`, `library`, `listening`, `all`) or individual
shot names, writes the WebP files straight into `docs/screenshots/`, and keeps the raw PNGs
under the system temp directory. It needs `cwebp` on the PATH (`brew install webp`).

Desktop shots are 1440 logical pixels at 1.33x, so 1920 px wide; phone shots are 390×760 at 2x
and pass through `frame.mjs`, which wraps them in a phone frame with a transparent outside.

The demo account's password can be reset from the stack:

```bash
docker compose exec php bin/console app:user:reset-password jamie.park@example.com --generate
```
