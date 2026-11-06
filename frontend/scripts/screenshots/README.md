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

# The Trending shots need news feeds whose stories overlap: borrow some, shoot, give them back.
node --env-file=.env.screenshots scripts/screenshots/trending-setup.mjs
node --env-file=.env.screenshots scripts/screenshots/shots.mjs trending-light mobile-trending-light
node --env-file=.env.screenshots scripts/screenshots/trending-setup.mjs teardown

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

## Tour video

The homepage's phone tour (`docs/homepage/tour-*`) is recorded by `tour.mjs`, both themes in one
run; it borrows and returns the podcasts itself:

```bash
node --env-file=.env.screenshots scripts/screenshots/tour.mjs          # or: … tour.mjs light
```

It writes `tour-<theme>.mp4` and `tour-<theme>-poster.webp` into
`docs/homepage/`, and each scene's start time into the `data-light` / `data-dark` attribute of the
page's scene list. `TOUR_OUT` points a trial run elsewhere; it updates a page only if that folder
has an `index.html`. It needs `ffmpeg` and `cwebp` on the PATH. Check each video before committing,
for example with a contact sheet:
`ffmpeg -i tour-light.mp4 -vf "fps=1,scale=200:-1,tile=9x5" -frames:v 1 sheet.png`.
An aborted run can leave the podcasts borrowed; `podcast-setup.mjs teardown` returns them.

The demo account's password can be reset from the stack:

```bash
docker compose exec php bin/console app:user:reset-password jamie.park@example.com --generate
```
