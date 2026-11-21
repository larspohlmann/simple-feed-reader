# Story card: one component, with created and last-added dates (#2447)

## Intent
The Trending story card (glyph, headline, counts, synopsis, Follow/Hide) should show when the story
was created and when its last article was added, and look and act the same everywhere it appears:
the Trending list, the magazine, and the story page's lead.

## Goals
- The card's meta line reads "9 sources · 6 shares · created 2 d ago · last article 3 h ago";
  the exact date and time on hover. en/de/fr/es strings.
- One component renders the card in every place, so a future change lands once.

## Non-goals
- No change to how stories are built or when joins are recorded.
- No new columns or migration.

## Anti-goals
- The card drifting between the list and the story page again.

## Constraints
- "Created" is `trending_story.first_joined_at` (the build that formed the story); "last article" is
  `last_joined_at` (the latest build that added a member). Both are build times, so they move in
  build steps, not article arrival minutes. Stored as naive UTC; sent as ATOM.
- Native iOS client stays viable: plain JSON fields, both responses keep their existing keys.

## Approaches
- **A. One card component (picked).** A `StoryCardComponent` takes one card shape; the Trending
  story JSON and the story page JSON both carry that shape from one backend mapper, with
  `createdAt` and `lastAddedAt`. The story page's hand-built copy of the card goes. Touches the
  layer code from #2442 and its specs.
- **B. Dates only.** Add the two fields to both responses and a dates piece to both templates.
  Smaller today, leaves the card duplicated.

Picked A: the card is the same everywhere by construction.

## Left to the builder
- Whether the existing `StoryTitleComponent` becomes the card or is replaced, and the exact field
  names of the shared shape, as long as both responses send the same shape.
- How the Trending list path reaches the stored story's join times.
- Relative-time wording within the existing `format.ts` helpers.
