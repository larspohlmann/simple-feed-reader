# Narrow the MetaRefreshTarget pre-check (#1096) Implementation Plan

**Goal:** Stop `MetaRefreshTarget::within()` from doing a full DOM parse on the
~25 % of landed pages that carry a non-`refresh` `http-equiv` meta
(`X-UA-Compatible`, `Content-Type`, …). Replace the `http-equiv` substring guard
with a `refresh`-specific regex. Behaviour for every real refresh form is
unchanged.

**Architecture:** The method already has two layers: a cheap pre-check that
short-circuits before parsing, and a DOM pass that is the source of truth. Only
the pre-check changes. It must stay a **superset** filter — every markup the DOM
pass would accept has to pass the regex, or a real redirect is silently dropped.

**Tech Stack:** PHP 8.4, PHPUnit 12 (SQLite leg natively).

**Spec:** GitHub issue [#1096](https://github.com/larspohlmann/simple-feed-reader/issues/1096).

## Global Constraints

- **Superset only.** The regex may over-match (an unnecessary parse is harmless);
  it must never under-match a form the DOM pass accepts.
- **One accepted gap:** an entity-encoded value (`http-equiv="&#82;efresh"`) is
  decoded by the HTML parser but not by the regex, so it is no longer followed.
  No real page does this. Codify it in a test with a comment, do not widen the
  pattern.
- **The stale comment** above the guard (`skip the DOM parse unless the markup
  can carry one`) is updated to name what the guard now looks for.
- Comments: three lines at most (CLAUDE.md). Prefer none.
- Commit format `type(#NN): summary`. No attribution lines. PR into `develop`
  with `Closes #1096`. Do not `gh pr merge --auto`.
- No behaviour change to `HtmlPageFetcher`; the #892 meta-refresh chain stays green.

---

### Task 1: Narrow the guard and cover every refresh form

**Files:**
- Modify: `backend/src/Service/Reader/MetaRefreshTarget.php:19-23` (the guard and its comment).
- Modify: `backend/tests/Service/Reader/MetaRefreshTargetTest.php` (add form coverage).

**Interfaces:** unchanged — `within(string $html, string $baseUrl): ?string`.

- [ ] **Step 1: Add failing/guarding tests (RED where they can fail).**
  Add cases proving the superset property and the accepted gap. The existing
  double-quoted and capitalised-value cases stay. New cases:
  - single-quoted `http-equiv='refresh'`
  - unquoted `http-equiv=refresh`
  - whitespace around `=` (`http-equiv = "refresh"`)
  - leading whitespace inside the quotes (`http-equiv=" refresh"`)
  - uppercase attribute name (`HTTP-EQUIV="refresh"`)
  - `content` attribute before `http-equiv`
  - a page whose only `http-equiv` metas are non-refresh (`X-UA-Compatible`,
    `Content-Type`) → `null`
  - accepted gap: `http-equiv="&#82;efresh"` → `null`, with a comment saying no
    real page encodes the attribute value and the guard deliberately does not.

  All the refresh-form cases pass under the **old** guard already (it parses
  everything with `http-equiv`); the entity-encoded case is the one that
  **changes** to `null`. Run the suite first to confirm the entity case is the
  only new red, if added before the code change.

- [ ] **Step 2: Narrow the guard (GREEN).**
  Replace lines 19-23 of `MetaRefreshTarget.php`:

  ```php
          // Almost every landed page is an ordinary article with no refresh meta;
          // skip the DOM parse unless a refresh meta can be present.
          if (preg_match('~http-equiv\s*=\s*["\']?\s*refresh~i', $html) !== 1) {
              return null;
          }
  ```

- [ ] **Step 3: Verify.**
  ```bash
  cd backend && php bin/phpunit tests/Service/Reader/MetaRefreshTargetTest.php
  ```
  Then the backend gates on the touched files:
  ```bash
  cd backend && composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp
  ```
  Mutation gate on the diff:
  ```bash
  cd backend && composer infection:diff
  ```
  Scan today's dev log for new ERROR/CRITICAL lines.

- [ ] **Step 4: Commit + PR.** Rationale (why a superset filter, why the
  entity-encoded gap is accepted) goes in the commit body.

---

## Self-Review

- **Acceptance 1** (non-refresh `http-equiv` does not reach the parser) → the
  narrowed regex; behaviourally proven by the non-refresh→null test (the
  short-circuit itself is a performance property, unobservable in a unit test, so
  it is verified by inspecting the one-line change, not a separate test).
- **Acceptance 2** (every refresh form still followed, #892 green) → the form
  cases in Step 1 plus the existing suite.
- **Acceptance 3** (stale comment updated) → Step 2.
