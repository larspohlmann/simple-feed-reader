# Regenerating `docs/screenshots`

The gallery is captured against the dev stack on `http://localhost:4200` with Playwright,
on a demo account that follows a handful of mainstream feeds. Run everything from `frontend/`.

```bash
cat > .env.screenshots <<'EOF'
SHOT_EMAIL=jamie.park@example.com
SHOT_PASSWORD=…
BLUESKY_HANDLE=…
BLUESKY_APP_PASSWORD=…
MASTODON_INSTANCE_URL=…
MASTODON_ACCESS_TOKEN=…
LEMMY_INSTANCE_URL=…
LEMMY_USERNAME=…
LEMMY_PASSWORD=…
EOF

# Sign-in and admin pages: any admin account works (the user list and proxy page are stubbed);
# override SHOT_EMAIL and SHOT_PASSWORD for this one run. Everything else: the demo account.
SHOT_EMAIL=… SHOT_PASSWORD=… node --env-file=.env.screenshots scripts/screenshots/shots.mjs public admin

# The Social shots, and the network sections every sidebar shows, need connected accounts: a
# Bluesky app password (never the account password), a Mastodon access token and a Lemmy sign-in
# in the env file. The handles show in the shots. Connect each, import a curated set of follows,
# communities and feeds, shoot everything with a sidebar, then disconnect; the demo keeps nothing.
node --env-file=.env.screenshots scripts/screenshots/social-setup.mjs bluesky candidates   # what could be imported
BLUESKY_IMPORT_HANDLES=heise.de,ct.de,npr.org,hankgreen.bsky.social,lhn.bsky.social,agreenberg.bsky.social,mattburgess1.bsky.social,macandi.bsky.social \
  BLUESKY_IMPORT_COLLECTIONS=Discover \
  node --env-file=.env.screenshots scripts/screenshots/social-setup.mjs bluesky
node --env-file=.env.screenshots scripts/screenshots/social-setup.mjs mastodon   # MASTODON_IMPORT_HANDLES, …_COLLECTIONS
node --env-file=.env.screenshots scripts/screenshots/social-setup.mjs lemmy      # LEMMY_IMPORT_HANDLES, …_COLLECTIONS
node --env-file=.env.screenshots scripts/screenshots/shots.mjs library social
for network in bluesky mastodon lemmy; do
  node --env-file=.env.screenshots scripts/screenshots/social-setup.mjs "$network" teardown
done

# The Trending shots need news feeds whose stories overlap (trending-feeds.mjs): borrow them, wait
# until `status` exits 0, shoot, give them back. Below its floor (8 stories in the day window, 4
# sources on the lead, a synopsis on the first five) the list is too thin to show. A rebuild runs
# on the worker's schedule and titles follow within five minutes;
# `docker compose exec php bin/console app:trending:rebuild --user=<id> day` builds at once.
node --env-file=.env.screenshots scripts/screenshots/trending-setup.mjs
node --env-file=.env.screenshots scripts/screenshots/trending-setup.mjs status
node --env-file=.env.screenshots scripts/screenshots/shots.mjs trending
node --env-file=.env.screenshots scripts/screenshots/trending-setup.mjs teardown

# The Listening shots need podcasts the demo does not follow: borrow some, shoot, give them back.
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs        # prints PODCAST_TAG=<id>
PODCAST_TAG=<id> node --env-file=.env.screenshots scripts/screenshots/shots.mjs listening
node --env-file=.env.screenshots scripts/screenshots/podcast-setup.mjs teardown
```

The notifications shot shows invented people: its list and the bell's count are stubbed, since a
connected account's real notifications name real ones. The Lemmy shots open the most discussed
post near the top of the Lemmy list; `lemmy-votes-light` and `lemmy-comment-votes-light` are
close-ups, cut to the post's head and to the first comments at twice the pixels.

The post shots open the newest post of one followed account, `BLUESKY_POST_ACCOUNT` (a title
substring, default "Lily Hay Newman"), by its title; check that the post suits a public README.

`shots.mjs` takes set names (`public`, `admin`, `library`, `trending`, `social`, `listening`, `all`) or individual
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
