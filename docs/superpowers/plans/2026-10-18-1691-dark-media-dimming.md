# Dark media dimming (#1691) Implementation Plan

**Goal:** Dark-theme images dim in step with the dimmed text: rest at 0.85, minus 0.2 per dimming step; light unchanged.

**Architecture:** `$ranges` in `frontend/src/app/theme/_brightness.scss` gains per-theme `media-rest` and `media-dim`. A `media-at-rest($theme)` mixin emits `--media-brightness` into the theme's base block (`tokens.scss`) when the rest is below 1; `step()` emits `rest − dim·|step|` on dimming steps. `styles/_base.scss` widens its guard to `:root:is([data-theme='dark'], [data-brightness^='-'])`.

## Task 1: spec first (`frontend/src/app/theme/brightness-steps.spec.ts`)
- `MEDIA` becomes per theme: light `-1…-6` → `0.92 … 0.52`; dark `-1…-3` → `0.65, 0.45, 0.25`.
- "carries no media factor at step 0" becomes per theme: light undefined, dark `0.85`.
- Run `npx jest src/app/theme/brightness-steps.spec.ts` in the frontend container → FAIL on the dark values.

## Task 2: Sass
- `$ranges`: light `media-rest: 1, media-dim: 0.08`; dark `media-rest: 0.85, media-dim: 0.2`; drop `$media-dim`.
- `step()` reads them; new `media-at-rest` mixin included in the dark and light base blocks of `tokens.scss`.
- Spec → PASS.

## Task 3: guard + docs
- `_base.scss` guard and its comment; the audio rule follows the same guard.
- `docs/design-language.md` "Brightness steps": the per-theme numbers and the dark resting factor.

## Task 4: verify
- `npm run check` in the frontend container; visual check of a white image at dark 0 and −3 on :4200.
