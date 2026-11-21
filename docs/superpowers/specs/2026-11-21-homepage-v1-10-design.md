# Homepage, README and feature list for v1.10.0 (#2452)

## Intent
The public homepage (`docs/homepage/index.html`, live at lars-pohlmann.de/simplefeedreader/)
describes the app as of about v1.7. Someone deciding whether to install v1.10.0 should see what
that release holds: Trending stories, Lemmy, social notifications, the feed directory. `README.md`
and `docs/features.md` have the same gaps and are brought in line in the same change.

## Goals
- The homepage names every feature group of v1.10.0, with screenshots that show the current UI.
- The whole `docs/screenshots` set is retaken (current shots: 23 October to 6 November), so no
  page mixes old and new UI. The phone tour is re-recorded with a Trending scene.
- `README.md` and `docs/features.md` list the same features and say nothing that is no longer true.

## Non-goals
- The v1.10.0 changelog highlights (part of the release).
- Any change to the app. A bug a shot reveals gets an issue; a trivial one a commit of its own.
- A new look for the page: tokens, type, section pattern and components stay as they are.

## Anti-goals
- A "what's new" block or release banner on the homepage; `changelog.html` is that page.
- A claim on the page that the app does not keep. Every sentence added is checked against the
  running app or the code before it lands.
- A screenshot that shows a real person's notifications, the instance's proxy host, or a post
  that does not suit a public page.

## Constraints
- The page is one static file with no build step and no dependencies; it shares `base.css`,
  `theme-init.js` and `theme.js` with the generated changelog page.
- Homepage voice: impersonal and descriptive ("The layout can be switched at any time"), no "you",
  no superlatives. README and `docs/features.md` keep their "you" voice.
- Screenshots have one source, `docs/screenshots/`, written by `frontend/scripts/screenshots/shots.mjs`
  against the dev stack on `:4200` with the demo account; `docs/homepage/screenshots` is a symlink.
  Every setup script borrows and gives back: the demo account ends as it started.
- **A merge to `develop` that touches `docs/homepage/` or `docs/screenshots/` deploys the page at
  once**, while the installer on the page installs the latest release. The PR therefore merges
  directly before v1.10.0 is cut, not earlier.
- Sign-off on the look happens on the real page opened from the checkout, in both themes and at
  phone, tablet and desktop width, before the PR is opened.

## Approaches
1. **Copy only, plus the few new shots.** Quickest; leaves October screenshots beside November
   ones and a tour whose sidebar no longer exists.
2. **Copy, a full screenshot retake and a re-recorded tour, inside the page's current structure.**
   Picked (Lars, 2026-11-21: "Retake everything"). The tooling for all of it exists; the cost is
   demo data for Trending, Lemmy and notifications.
3. **Restructure the page into fewer, broader sections.** Rejected: a redesign nobody asked for,
   and the current section pattern takes two more sections without strain.

Generating the homepage and README lists from `docs/features.md` was considered and dropped: three
texts in three voices and lengths, changed a few times a year.

## The page

Order, with what changes:

| # | Section | Change |
|---|---|---|
| 1 | Hero | Lede names Lemmy beside Bluesky and Mastodon, and Trending. Meta description, Open Graph, Twitter and JSON-LD `description` carry the same sentence; `featureList` gains Trending stories, Lemmy, notifications, feed directory. Headline stays. |
| 2 | Tour | Re-recorded. Scenes: magazine, reader, sidebar, **Trending** (new), podcast, theme. The lede states the measured length. |
| 3 | Layouts | Retaken shots only. |
| 4 | **Trending** (new) | `split` section with `trending-light`. Points: stories several feeds cover, last 24 hours or 7 days, ranked by sources; one card per story with its outlets, counts and dates; an optional LLM writes the title and a synopsis, without one the most representative headline stands; Follow and Hide; a story opens as its own list. |
| 5 | Features grid | Six cards. The Bluesky & Mastodon card leaves; **Feed directory** takes its place (about 50,000 news sites and podcasts, narrowed by country and language, subscribe from a row). Reader view gains comments in the reader; Podcasts & YouTube gains transcripts. |
| 6 | **Social** (new) | `split flip` section, "Bluesky, Mastodon and Lemmy", with `lemmy-light`. Bullets: timelines, lists, custom feeds and follows as feeds; Lemmy communities with votes and threaded comments; a search saved as a feed; reply, repost, quote, like and vote from the reader; share a link or a selected passage to the networks; notifications from the connected accounts. |
| 7 | Mobile | One sentence on the floating buttons in one bottom corner, on the chosen side. `mobile-podcast-playlist-light` gives its place to `mobile-trending-light`. |
| 8 | Organise | One sentence: feeds, tags and networks choose where they show (All items, For you, saved searches, Trending). |
| 9 | Self-hosted | Screenshot becomes `admin-users-light`. Bullets gain the in-app update (opt-in updater) and, under AI, the run history with tokens and cost per run. |
| 10 | Email digest | Retaken shot only. |
| 11 | Install | Unchanged unless a container count or RAM figure proves stale. |

Top nav gains "Changelog" (`changelog.html`), as the footer has it.

## Screenshots
- Retake every existing shot (`shots.mjs all`, with the Bluesky, Trending and podcast setups).
- New shots, all desktop light: `story-light` (a story's own list with its title card),
  `lemmy-light` (a Lemmy post open, with vote pill and threaded comments), `notifications-light`
  (the notifications list), `settings-directory-light`, `settings-visibility-light`.
- `lemmy-setup.mjs`, after `podcast-setup.mjs`: borrows one public community feed, no account.
- The notifications list is stubbed with invented example.com actors, the way the admin user list
  is; the bell needs the Bluesky connection the social set already makes.
- `trending-setup.mjs` stays; `tour.mjs` borrows the same feeds for its Trending scene and gives
  them back, from one shared list.
- `share.png` is re-rendered with `scripts/homepage-share-image.mjs` after `magazine-light`.
- `docs/screenshots.md` shows every file in `docs/screenshots/` and names none that is missing.

- The admin set runs under the e2e admin, as it did in October: the same env file with
  `SHOT_EMAIL` and `SHOT_PASSWORD` overridden for that one run. The demo account stays a plain user.
- The two digest shots (`digest-light`, `mobile-digest-dark`) come from no script and are **not**
  retaken: the mail templates have no commit since the shots were made, so they show the mail as
  it is sent today. (Builder's call.)

## Order of work
1. A sweep first: the changelog highlights from v1.7.0 on and the `feat` pull requests merged
   since v1.9.4, held against the homepage, README and `docs/features.md`. The edits listed in
   this document are the floor; what the sweep adds goes in the same sections and voice.
2. Copy and scripts, with the page checked against the old shots.
3. The retake and the tour last, on a fresh merge of `develop`, so the shots show what ships.
   A UI change that reaches `develop` afterwards means rerunning the set it touches; every set
   is one command from the screenshots README.
4. Sign-off on the real page, then the PR, merged directly before the release.

## README.md and docs/features.md
- README bullets: Lemmy beside Bluesky and Mastodon, with reply, repost, quote and notifications;
  Trending with story titles, synopsis, Follow and Hide; the feed directory.
- `docs/features.md`: Lemmy; the notifications line loses "Backend only for now; the list
  follows"; feed directory; visibility switches; transcripts; comments in the reader; like and
  comment counts; tracking parameters stripped from article URLs; in-app update; AI run history.
  The Trending section drops "No AI is involved" for: grouping needs no AI, an optional LLM
  writes titles and synopses.

## Story titles in the shots
The demo account already holds a verified chat connection (`qwen`, OpenRouter), and story titles
are on by default, so the borrowed Trending feeds get LLM titles and synopses on the next sweep,
at that connection's cost. The Trending shots and the tour are taken only once the cards carry a
synopsis. (Builder's call, not agreed with Lars beforehand.)

## Left to the builder
- The final wording of every sentence, within the voice above.
- Which Lemmy community, which story and which post the shots open, checked for a public page.
- Icons for the new card, the exact tour gestures, and whether the Trending scene opens a story.
- Whether `frontend/scripts/screenshots/README.md` needs more than the new setup's lines.
