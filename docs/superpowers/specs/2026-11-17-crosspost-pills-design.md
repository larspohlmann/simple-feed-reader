# Crosspost pills in the share-menu composer (#2287)

## Intent

Sharing a link to Bluesky and Mastodon today means opening the composer twice and writing
the text twice. The share-menu composer gets two pills, Bluesky and Mastodon. The network
picked from the menu starts active and the other starts inactive. Activating the other sends
the same text to both networks in one go: a crosspost.

## Goals

- One shared text, posted to each active network. Bluesky gets its link card, Mastodon gets
  text plus URL.
- A partial failure never posts twice. The network that took the post is locked as sent, and
  Send retries only the failed one.
- The "posted" overlay lists every post that went out.
- The server keeps describing the composer (#1968), so a native Swift client needs no rule of
  its own.

## Non-goals and anti-goals

- No Lemmy, no crossposting of replies or of Repost → Quote, no remembered pill state, no
  merged "Post…" menu entry, no separate text per network, no splitting of a too-long text.
- Anti-goal: a crosspost that publishes on one network twice (a retry resending the network
  that succeeded, or a resend under a new idempotency key).
- Anti-goal: replies and quotes started from Repost → Quote look or behave differently than today.

## Decided (Lars, 2026-11-16 and 2026-11-17)

1. **One shared text.** One textarea. The URL leaves the editable text for every Mastodon link
   post, crosspost or not. It appears as a fixed, non-editable line under the field,
   "🔗 Mastodon adds <url>", shown only while Mastodon is active and the Mastodon lane is a link
   post (not a quote). Bluesky keeps its card.
2. **Every share-menu post gets the pills**, selection quotes included, but only when the reader
   has a connected account on both Bluesky and Mastodon. With one network, the composer looks as
   it does today. A URL that names a post is a quote on its own network and a link post on the
   other. Replies and Repost → Quote stay single-network, without pills.
3. **Menu as today, other pill off.** Both menu entries stay. The picked network starts active,
   the other inactive, every time. At least one pill stays active, and the picked one may be
   switched off once the other is on.
4. **Context loads when a pill is activated.** Activating a pill loads that network's
   `GET /api/social/link-composer`. A failed load (needs reconnect, unreachable) turns the pill
   back off with a warning mark, shows a one-line error under the pill row with a retry, and
   leaves the other network sendable.
5. **Typed text survives a pill toggle.** The starting text comes from the picked network's
   context only. Activating the other never replaces the text. If the text is over the new
   network's limit, that counter shows it and Send is off until it's trimmed.
6. **Per-network controls show only while their pill is active.** An account select per active
   network with more than one account, labelled with its network when both are active.
   Mastodon's visibility and content warning only while Mastodon is active. Post language goes
   to both, as today.
7. **One request per network**, against the existing endpoints, each with its own idempotency
   key minted when the lane is created and kept across retries. No crosspost endpoint.
8. **Partial failure keeps the dialog open.** The succeeded pill turns into a locked "✓ Posted"
   state (green, no longer a button). A named error banner, "Mastodon: <problem text>", sits
   directly under the pill row, and the failed pill gets a red ring. The text is kept. Send
   reads "Retry <Network>" and posts only to the failed network. Cancel closes into the
   "posted" overlay for what went out.
9. **Empty text is allowed for link posts** on both networks (the link is the content). Replies
   and quotes still need text.
10. **Heading and Send label** name the active networks: heading "Post to Bluesky and Mastodon",
    Send "Post to both". With one network active, both read as today.
11. **Pill look:** the existing `theme/_pill-toggle.scss` treatment (`--accent-soft` when on)
    with `<app-network-logo>` and the network name, at least `--tap-target` tall on a coarse
    pointer, `aria-pressed` for assistive tech. Sent: success colours, a check and "Posted".
    Load failure: red ring, warning mark.
12. **Placement (mockup, section 1):** pills directly under the heading, above the subject preview.
13. **Preview when kinds differ (mockup 2A):** one preview, the picked network's subject (for
    example the quoted Bluesky post), plus a one-line note with the other network's logo:
    "Mastodon shares this post as a link". The note appears only while both pills are active
    and the two lanes differ in kind. If the picked pill is switched off, the preview follows
    the remaining active lane.
14. **Counters (mockup 3A):** on the Clear row, one per active network, named when two are
    active ("Bluesky 312 / 300 · Mastodon 50 / 475"), unnamed when one is ("34 / 300"). An
    over-limit counter turns `--danger`.
15. **The "posted" overlay** lists every post that went out, each with its account handle and
    its own "Open".

## Backend

The endpoints and their JSON shape stay the same. No migration.

- **Mastodon link context without the URL.** `ComposerStartingText::sharing` drops
  `LINK_GAP . url` for Mastodon. The text is the title, or the quoted selection, alone (an empty
  title gives empty text, not the URL). The selection is still clipped to leave room for the link.
- **Limit net of the link.** For a Mastodon *link* context (`ComposerContexts::ofLink`),
  `maxCharacters` is the instance limit minus 25 (23 for the link plus the blank line). A
  Mastodon quote context (a shared Mastodon post URL) is unchanged: it carries no URL.
- **`MastodonPostWriter::share` appends the URL**: `text . "\n\n" . url`, or the URL alone when
  the text is blank. `reply` and `quote` are unchanged.
- **Blank text on link posts.** `PostDraftRequest` keeps `NotBlank` for replies and quotes. The
  link endpoint (`SocialLinkPostController`) accepts blank text when the post goes out as a link
  post, and still refuses it (422, as today) when the shared URL resolves to a quote. How
  validation is split (a separate request DTO, validation groups, or a domain check that throws
  a typed exception mapped in the module's `*Problems`) is left to the builder, within the
  CLAUDE.md rules. The Bluesky writer has to accept empty text with an embed. Verify that
  against the Bluesky lexicon rather than assuming it.
- **Bluesky honours the idempotency key (Lars, 2026-11-17).** Today `BlueskyPostWriter`
  ignores `$draft->idempotencyKey`, and `BlueskyRecords::createPost` lets the PDS pick the
  record key. So a retry after a lost success answer posts twice. Required behaviour: two
  requests carrying the same key create at most one Bluesky post. The second answers with the
  first one's address, as a success. This covers replies, quotes and link posts alike, since
  they share the writer.
  - The record key of `app.bsky.feed.post` must be a valid TID. A key that the same idempotency
    key always yields, sent as `rkey` to `createRecord`, makes the PDS refuse the duplicate. Map
    that refusal to success with `at://<did>/app.bsky.feed.post/<rkey>`, and look up its web URL
    as a normal success would.
  - Whether that TID is derived from the idempotency key or minted on first use and remembered
    (a cache pool entry keyed by account + idempotency key) is the builder's call. Check two
    things against the lexicon and a real PDS first: which TIDs it accepts (a derived TID with a
    far-off timestamp may be refused or sort oddly in feeds), and the exact error a duplicate
    rkey returns. A request without a key behaves as today.
- Each change gets its tests. Infection gates the touched files.

## Frontend

**Approach chosen: per-network lanes (A).**

- A composer-scoped state class (`ComposerLanes` or similar, `providers` on the dialog). It
  holds one lane per network that the composer can post to. A lane owns:
  - its context load state (loading / ready / failed), with a load that cancels the one still
    on the wire, as `load()` does today
  - its account id and Mastodon visibility, with the "visibility chosen" memory
  - its idempotency key
  - whether it is active
  - its sent result (`SentPost` or none)
  - its send error
- The dialog keeps the shared text (with the `textTouched` and caret rules), the content
  warning, and the layout. `canSend`: text non-blank, or every active unsent lane is a link
  lane; no active unsent lane is over its limit or has `canSend: false`; at least one active
  unsent lane is ready; nothing is on the wire.
- **Send** fires one request per active, unsent, ready lane, in parallel. Each lane records its
  own success or error. Once all have answered, the dialog closes with the full list if every
  active lane is sent. Otherwise it stays open in the partial-failure state (8).
- **Replies and Repost → Quote** run as a single lane with no pills, through the same code, and
  must look and behave exactly as today. Their specs are the regression net.
- **Which lanes exist:** a link target gets a lane for each of Bluesky and Mastodon that
  `PostNetworks.connected` lists. Pills render only when there are two lanes.
- `DialogRef<SentPost[]>`. `PostActions.postLink` emits `SentPost[]`. Its two callers
  (`share-button`, `selection-actions`) only record the share, so they record once per
  composer close. `PostSentDialogComponent` takes a list. `quote()` and `reply()` adapt to a
  one-item list.
- `postLength` for a Mastodon link lane counts the text alone (the URL is outside it, and the
  limit is already net of it).
- i18n in en, de, fr, es: heading and Send label for both networks, "Post to both",
  "Retry <Network>", "Posted", the URL line, the kind note, the named counters, the per-network
  error prefix, and the posted overlay list.
- If the pill becomes a shared component under `src/app/shared/`, it goes into
  `docs/design-language.md`.

## Constraints

- CLAUDE.md frontend rules: standalone components, signals, sibling `.scss`, no hex or `px`
  literals outside the theme.
- CLAUDE.md PHP rules: thin controllers, typed exceptions, PHPMD clean on touched files, no
  PHPStan rule edits.
- The native iOS constraint: nothing browser-only. The server states the text and limit, so a
  native client can crosspost with the same two requests.

## Calls made after the builder check (mine, for Lars to check)

- **The picked network's own load fails.** With two lanes: the pill row shows, the picked pill
  stays active with a warning mark, and the one-line error with retry sits under the pills. The
  reader can activate the other pill, and then switch the failed one off, so "at least one
  active" holds. With one lane: today's full-dialog load error with retry, unchanged.
- **The URL is appended only when it's missing.** `MastodonPostWriter::share` doesn't append the
  URL when the text already contains it. A tab still on the pre-deploy bundle, which sends
  title + URL, then gets no doubled link, and the backend and frontend need not ship in lockstep.
- **Send stays off while any active lane is still loading.**

## Left to the builder

- The names and file split of the lane state class and the pill component.
- How the validation for blank link text is split on the backend (see Backend).
- Whether the two sends start in parallel or in sequence. Parallel is assumed; either satisfies
  "no network twice".
- How the e2e smoke, if any, stubs the two networks (CLAUDE.md: an e2e spec owns its data).

## Approaches considered

- **A. Per-network lanes in a composer-scoped state class. Chosen.** Partial failure, locked
  pills and retry-only-failed become per-lane state, and reply and quote become the one-lane
  case. Cost: a medium refactor of the composer and its spec.
- **B. A second context bolted onto the existing component.** Smallest diff, but every computed
  value branches on "the other one" and doesn't extend past two networks.
- **C. A separate link composer.** Simple per dialog, but duplicates the textarea, counter,
  account, visibility and warning controls a third time.

## Acceptance

As in issue #2287. In addition:

- A Mastodon link post sends its text without the URL, and the posted status carries
  text + blank line + URL, or the URL alone for blank text.
- A blank-text link post goes out on both networks. A blank-text reply or quote is still refused.
- Two Bluesky sends with the same idempotency key publish one post, and both answer with its
  address.
