# Searchable release root post (#2342) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Bluesky/Mastodon release thread's root post carries plain search words, the followed tags and a per-release lead sentence written in the Highlights block.

**Architecture:** `thread.mjs` parses the Highlights block into a lead (an untitled first paragraph) and titled highlights; `buildThread` frames the lead, or falls back to a list of the highlight titles. A node test reads `CHANGELOG.md` and holds the lead under `[Unreleased]` to 150 graphemes.

**Tech Stack:** Node 22 ESM, `node:test`.

**Spec:** [#2342](https://github.com/larspohlmann/simple-feed-reader/issues/2342) (the design is the issue body).

## Global Constraints

- Frame with a lead: `simple-feed-reader {tag}, a self-hosted, open-source RSS reader, is out. {lead}\n\n{HOMEPAGE_URL}\n\n#RSS #SelfHosted #OpenSource`
- Fallback: `New in simple-feed-reader {tag}, a self-hosted, open-source RSS reader:\n\n• {title}\n…\n\n{HOMEPAGE_URL}\n\n#RSS #SelfHosted #OpenSource`; titles that don't fit end the list with `… and N more below`; no titles at all → `simple-feed-reader {tag}, a self-hosted, open-source RSS reader, is out.\n\n{HOMEPAGE_URL}\n\n#RSS #SelfHosted #OpenSource`.
- Lead budget: `LEAD_LIMIT = 150` graphemes.
- A title is a paragraph's bold lead without its closing full stop.
- The lead is never posted as a reply; replies and the closing reply are unchanged.
- The changelog, Release body and homepage renderer are not touched.

## Review Focus

- A Highlights block whose first paragraph is titled has no lead → fallback list, every paragraph still a reply.
- A lead too long for the network (Bluesky, long tag) → fallback list, not a split or overlong root.
- More titles than fit → as many as fit plus `… and N more below`, root ≤ limit.
- A block holding only a lead → still announced (root + closing reply), not "no highlights".
- An untitled paragraph after the first → reply only, adds no title.

---

### Task 1: Lead, titles and the new root

**Files:**
- Modify: `scripts/announce/thread.mjs`, `scripts/announce/announce.mjs`
- Test: `scripts/test/announce/thread.test.mjs`, `scripts/test/announce/announce.test.mjs`

**Interfaces:**
- Produces: `extractHighlights(releaseBody: string) -> { lead: string | null, highlights: { title: string | null, text: string }[] }`; `buildThread({ tag, releaseUrl, lead, highlights, limit }) -> string[]`; `export const LEAD_LIMIT = 150`.

- [ ] **Step 1: Write the failing tests** in `thread.test.mjs`:
  - `extractHighlights` on a block `lead / **One.** First. / **Two.** Second.` → `{ lead: 'Lead.', highlights: [{ title: 'One', text: 'One. First.' }, { title: 'Two', text: 'Two. Second.' }] }`; existing CRLF/link/list-item cases keep their `text` and gain `title`.
  - first paragraph titled → `lead: null`; a later untitled paragraph → `{ title: null, text }`; no block → `{ lead: null, highlights: [] }`.
  - `buildThread` with a lead → root equals the lead frame from Global Constraints; replies are the highlight texts; closing reply unchanged.
  - no lead → title-list root; lead whose root exceeds `limit` → title-list root.
  - ten long titles at `limit: 300` → root ≤ 300 and ends its list with `… and N more below` where N = titles left out.
  - no lead, no titles → the bare frame.
  - `LEAD_LIMIT` fills a lead root within 300 for tag `v10.10.10`.
- [ ] **Step 2:** `node --test scripts/test/announce/` → the new tests FAIL.
- [ ] **Step 3:** Implement in `thread.mjs`; in `announce.mjs` pass `lead` through and announce nothing only when there is neither a lead nor a highlight. Update `announce.test.mjs`'s dry-run expectation to the new root and add a lead-only body test (root + closing reply).
- [ ] **Step 4:** `node --test scripts/test/announce/` → PASS.
- [ ] **Step 5:** Commit `feat(#2342): the release root post names the product and carries the lead or the highlight titles`.

### Task 2: Lead budget check and docs

**Files:**
- Create: `scripts/test/announce/changelog-lead.test.mjs`
- Modify: `docs/releasing.md` (Announcements, Highlights)

- [ ] **Step 1: Write the test** `the lead under [Unreleased] fits LEAD_LIMIT`: read `CHANGELOG.md`, take the section between `## [Unreleased]` and the next `## [`, `extractHighlights(section).lead`; assert it is `null` or `graphemeLength(lead) <= LEAD_LIMIT`, the message naming the length. Prove it by temporarily writing a 151-character lead into the changelog (FAIL quoted), then restoring by hand.
- [ ] **Step 2:** `node --test scripts/test/announce/` → PASS on the real changelog. CI already runs `scripts/test/announce/*.test.mjs`.
- [ ] **Step 3:** Docs: Announcements describes the new root (frame, lead, fallback, tags); Highlights adds the lead sentence and the wording rules from the issue (one sentence ≤ 150, searchable nouns, plain verbs, no praise/emoji/exclamation/hashtags, titles name the feature) and the `--dry-run` preview.
- [ ] **Step 4:** Commit `feat(#2342): hold the Unreleased lead to 150 characters; document lead and title wording`.
