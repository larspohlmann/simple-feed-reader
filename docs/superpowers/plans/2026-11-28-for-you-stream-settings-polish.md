# For you stream settings polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Polish the For you stream settings group (#2597): collapsible word list, clearer empty state and info text, minus/plus precision icons, and `ON` instead of the deprecated `WITH` for arbitrary DQL joins.

**Architecture:** Frontend-only changes in `for-you-stream-group.component.*` and the four i18n files; backend changes in two repositories' join conditions only.

**Spec:** issue #2597 (body is the spec; copy below is verbatim from it and from Lars's choices).

## Global Constraints

- Commits `type(#2597): lower-case summary`, no attribution lines.
- Copy in en/de/fr/es; fr *vous*, es *tú*, glossary `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md`. "For you" stays the loanword the existing strings use.
- No hex colours or px literals in `.scss`; styles in the sibling `.scss`.
- Per-task verification only (targeted tests + lint of touched files); the full gate set runs once before the PR.

---

### Task 1: `ON` for arbitrary joins

**Files:** `backend/src/Repository/ForYouStreamSettingsDql.php`, `backend/src/Repository/EntryListRepository.php:138-139`.

- [ ] Replace `'WITH'` with `Join::ON` (`Doctrine\ORM\Query\Expr\Join::ON`) in the three arbitrary entity joins. Behaviour unchanged.
- [ ] Prove the deprecation is gone: run the covering tests (`tests/Repository/InterestTermSetRepositoryTest.php`, the stream sweep test, `tests/Repository/EntryListRepositoryTest.php` or whatever covers `EntryListRepository`, `SubscriptionControllerTest`) with deprecations displayed (`SYMFONY_DEPRECATIONS_HELPER` / PHPUnit `--display-deprecations`), quoting that no "Using WITH for the join condition of arbitrary joins" line appears, and that it did appear before the change.
- [ ] `grep -rn "'WITH'" backend/src` lists no arbitrary entity join (association joins with WITH are fine — check each remaining hit).
- [ ] cs + stan on the two files. Commit `fix(#2597): arbitrary DQL joins use ON instead of the deprecated WITH`.

### Task 2: Word list, copy and icons

**Files:** `frontend/src/app/settings/recommendations/for-you-stream-group.component.{ts,html,scss}`, its spec (the card spec drives it — find where the group's specs live), `frontend/public/i18n/{en,de,fr,es}.json`.

- [ ] **Icons:** `decreaseIcon="remove"`, `increaseIcon="add"`.
- [ ] **Info text** `settings.ai.info.forYouStream`, en verbatim: "The AI reads your profile and picks words and short phrases that describe your interests (listed below). Each new article is checked for them in its headline and text, and if enough of them appear, it lands in your stream. Words that are rare in your feeds count more than common ones. Precision sets how close the match must be: narrow lets in only strong matches, wide lets in more. Changes only affect new articles." Write de/fr/es with the same meaning; use each language's existing word for "article/entry" in the neighbouring stream strings.
- [ ] **Empty state** `…forYouStream.phrasesEmpty`, en: "No word list yet. It appears a few minutes after your profile is updated." de/fr/es to match.
- [ ] **Collapsible list:** the language groups (headings and phrases) sit in one block that, collapsed, shows only its first 5 visual lines (clip with `max-block-size` = 5 × the block's line height via a CSS custom property/`lh` unit, `overflow: hidden`). Below it a text button "More" (expanded: "Less"), keys `…forYouStream.phrasesMore` / `…phrasesLess`, `aria-expanded` set, `aria-controls` pointing at the block. The button shows only when the content actually overflows 5 lines (measure `scrollHeight > clientHeight` with a `ResizeObserver` on the block; disconnect on destroy). Collapsed is the default; the state is per page visit (no persistence). Use an existing shared text-link/button style from `src/app/shared` or docs/design-language.md if one fits; otherwise the smallest local style with tokens.
- [ ] **Specs:** collapsed by default with the button when content overflows (stub the measurement: jsdom has no layout, so make the overflow check a small injectable/overridable method or set `scrollHeight`/`clientHeight` on the element via `Object.defineProperty` in the spec); clicking toggles expanded + `aria-expanded` + label; no button when it does not overflow; icons are `remove`/`add`.
- [ ] Prettier/ESLint/Stylelint/tsc on touched files, the specs, `ng build`. Commit `feat(#2597): …` (one or two commits).
