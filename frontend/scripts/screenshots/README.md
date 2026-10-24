# Regenerating `docs/screenshots`

The gallery is captured against the dev stack on `http://localhost:4200` with Playwright,
on a demo account that follows a handful of mainstream feeds. Run everything from `frontend/`.

```bash
cat > .env.screenshots <<'EOF'
SHOT_EMAIL=jamie.park@example.com
SHOT_PASSWORD=…
BLUESKY_HANDLE=…
BLUESKY_APP_PASSWORD=…
EOF

# Sign-in and admin pages: any admin account works (the user list and proxy page are stubbed).
# Everything else: the demo account.
node --env-file=.env.screenshots scripts/screenshots/shots.mjs public admin library

# The Social shots, and the Bluesky section every sidebar shows, need a connected account:
# BLUESKY_HANDLE and BLUESKY_APP_PASSWORD (an app password, never the account password) in the env
# file. The handle shows in the shots. Connect, import a curated set of follows and feeds, shoot
# everything with a sidebar, then disconnect; the demo keeps nothing.
node --env-file=.env.screenshots scripts/screenshots/bluesky-setup.mjs candidates   # what could be imported
BLUESKY_IMPORT_HANDLES=heise.de,hankgreen.bsky.social BLUESKY_IMPORT_COLLECTIONS=Discover \
  node --env-file=.env.screenshots scripts/screenshots/bluesky-setup.mjs
node --env-file=.env.screenshots scripts/screenshots/shots.mjs library social
node --env-file=.env.screenshots scripts/screenshots/bluesky-setup.mjs teardown

# The Listening shots need podcasts the demo does not follow: borrow some, shoot, give them back.
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs        # prints PODCAST_TAG=<id>
PODCAST_TAG=<id> node --env-file=.env.screenshots scripts/screenshots/shots.mjs listening
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs teardown
```

The post shots open the newest post of one followed account, `BLUESKY_POST_ACCOUNT` (a title
substring, default "Lily Hay Newman"), by its title; check that the post suits a public README.

`shots.mjs` takes set names (`public`, `admin`, `library`, `social`, `listening`, `all`) or individual
shot names, writes the WebP files straight into `docs/screenshots/`, and keeps the raw PNGs
under the system temp directory. It needs `cwebp` on the PATH (`brew install webp`).

Desktop shots are 1440 logical pixels at 1.33x, so 1920 px wide; phone shots are 390×760 at 2x
and pass through `frame.mjs`, which wraps them in a phone frame with a transparent outside.

The demo account's password can be reset from the stack:

```bash
docker compose exec php bin/console app:user:reset-password jamie.park@example.com --generate
```
