# Like button muted when off (#1693) Implementation Plan

**Goal:** In the social article view the like button is `--text-muted` like the counters beside it, and `--accent` only when liked; the pill ring stays in both states.

## Task 1: style (`frontend/src/app/reader/article/social-counts/social-counts.component.scss`)
- `.like`: `color: var(--accent)` → `color: inherit` (the row's `--text-muted`).
- `.like.on`: add `color: var(--accent)` beside its accent rim and soft background.

## Task 2: verify
- `npm run check` in the frontend container.
- Visual check of an unliked and a liked social post in the reader on :4200.
