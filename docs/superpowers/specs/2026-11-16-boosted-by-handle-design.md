# "Boosted by" names the booster's handle (#2267)

## Intent

Since #2261, every link into Mastodon on a Mastodon entry opens on the reader's own instance. The one exception is the
"Boosted by …" line that ingest appends to a boosted post's body: its link text is the booster's display name, so the
client's body rule (`ownInstanceBodyUrl`) skips it.

## Goal

A newly ingested boost stores `<p>Boosted by <a href="{profile}">@user@host</a></p>`. The link text starts with `@`
and the profile path is `/@user`, so the existing body rule opens it on the reader's instance. No frontend change.

## Non-goals

- No backfill: boosts stored before this change keep their display-name line.
- Bluesky's "Reposted by" line and `ShareAttribution` stay as they are.

## Approaches

1. **Structured field** (`sharedBy` on the entry, rendered client-side). Fits #2261's design, but needs a new field,
   client rendering and a fallback for stored bodies.
2. **Handle as the link text** *(chosen by Lars)*. One caller changes; the existing body rule does the rest. Only new
   boosts benefit, and the line shows a handle instead of a display name.

## Design

- `MastodonTimelinePage::withBoostAttribution` passes `'@' . MastodonAuthor::handle($booster)` as the name.
  `handle()` already completes a local account's acct with its profile host.
- When the booster has no handle, the line falls back to `MastodonAuthor::name($booster)`, as today.
