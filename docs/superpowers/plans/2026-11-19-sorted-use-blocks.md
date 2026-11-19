# Sorted `use` Blocks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `composer cs` fails on an unsorted `use` block, `composer cs:fix` sorts it, and the whole tree is sorted once.

**Architecture:** One sniff, `SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses`, added to `backend/phpcs.xml.dist` with its default properties; `phpcbf` produces the tree-wide commit. Nothing else from that standard is switched on.

**Tech Stack:** PHP_CodeSniffer 3.13, `slevomat/coding-standard` ^8.22 (pulls `dealerdirect/phpcodesniffer-composer-installer` v1.2, a Composer plugin).

**Spec:** GitHub issue #2378. Lars, 2026-11-19: "imports should be sorted".

## Global Constraints

- No behaviour change. The sort commit changes `use` lines and nothing else: no line is added or removed, only reordered.
- Only the one sniff is referenced, by its full name; never `<rule ref="SlevomatCodingStandard"/>`.
- The sniff keeps its defaults (`psr12Compatible` true: classes, then functions, then constants; `caseSensitive` false). The order `phpcbf` produces is the house order; do not tune properties to shrink the diff.
- `*/tests/PhpStan/data/*` and `*/migrations/*` are excluded from the new rule, as they are from PSR-12.
- The sort is produced by `composer cs:fix` only; no hand-sorting, no PhpStorm "optimize imports" (it also removes and rewrites imports).
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression. No new `src` class.
- Commits `chore(#2378): …`. No attribution lines.
- Start only on a freshly pulled `develop` with no other branch of this repository in flight; the sort touches about 550 files.

**Out of scope:** unused-import removal, any other Slevomat sniff, the frontend.

## Review Focus

- **`phpcbf` changes more than `use` lines.** A comment or attribute sitting between two imports, or a second fixable finding, would move with the sort. Task 2 Step 3 proves the diff is `use` lines only.
- **Composer refuses the plugin non-interactively.** CI and the Docker `php` container run `composer install` without a prompt; without the `allow-plugins` entry the installer is skipped and `phpcs` does not find the standard. Task 1 Step 3 and the CI lint job prove it.
- **`use function` and `use const` blocks.** They stay after the class imports, each sorted in itself. Task 2 Step 4 looks at one such file.
- **Group imports and several namespaces in one file.** If `grep -rnE '^use [^;]*\{' backend/src backend/tests` finds a group import, read that file's diff by hand.
- **The Docker stack's vendor directory.** The MySQL leg runs in the container; it needs `docker compose exec php composer install` after Task 1 or `composer cs` there fails for a missing standard.

---

### Task 1: The dependency

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`

- [ ] **Step 1:** In `backend/composer.json` add `"dealerdirect/phpcodesniffer-composer-installer": true` to `config.allow-plugins`.
- [ ] **Step 2:** Run `composer require --dev slevomat/coding-standard:^8.22` from `backend/`. Expected: two packages installed (`slevomat/coding-standard`, `dealerdirect/phpcodesniffer-composer-installer`), nothing else updated. If anything else moves in `composer.lock`, stop and report.
- [ ] **Step 3:** Run `vendor/bin/phpcs -i`. Expected: the list of installed standards includes `SlevomatCodingStandard`. Run `composer cs`. Expected: green, as on develop.
- [ ] **Step 4:** Run `docker compose exec php composer install` from the repo root. Expected: the same two packages installed.
- [ ] **Step 5:** Commit `chore(#2378): add slevomat/coding-standard for the import-order sniff`.

### Task 2: The rule and the sorted tree

**Files:**
- Modify: `backend/phpcs.xml.dist`
- Modify: every PHP file `phpcbf` sorts (about 550 in `backend/src` and `backend/tests`)

**Interfaces:**
- Produces, in `phpcs.xml.dist` after the `Generic.PHP.RequireStrictTypes` rule:

```xml
    <rule ref="SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses">
        <exclude-pattern>*/tests/PhpStan/data/*</exclude-pattern>
        <exclude-pattern>*/migrations/*</exclude-pattern>
    </rule>
```

- [ ] **Step 1:** Add the rule. Run `composer cs`. Expected: FAIL, every finding is `SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses`; note the file count.
- [ ] **Step 2:** Run `composer cs:fix`, then `composer cs`. Expected: green.
- [ ] **Step 3:** Prove the diff is reordering only. Run `git diff -U0 -- backend/src backend/tests | grep '^[+-]' | grep -v '^[+-][+-]' | grep -v '^[+-]use '`. Expected: no output. Run `git diff --numstat -- backend/src backend/tests | awk '$1!=$2'`. Expected: no output (as many lines added as removed in every file). If either prints, stop and report the files.
- [ ] **Step 4:** Open one sorted file that has `use function` or `use const` (find with `grep -rln '^use function' backend/src | head -1`). Expected: class imports first, then functions, then constants.
- [ ] **Step 5:** Prove the gate. Swap two `use` lines in `backend/src/Http/SubscriptionJson.php`; run `composer cs`. Expected: FAIL on that file with the sniff's name. Swap them back by hand (not `git checkout`); `composer cs` green and `git diff -- backend/src/Http/SubscriptionJson.php` shows only the sort.
- [ ] **Step 6:** Run `composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 7:** Commit `chore(#2378): sort every use block and gate the order`, with the file count in the body.

### Task 3: Documentation and PR

**Files:**
- Modify: `CLAUDE.md` — the `composer cs` command comment becomes `# PHP_CodeSniffer, PSR-12 and sorted imports (composer cs:fix to autofix)`; the bullet `**PSR-12** (`phpcs.xml.dist`), and …` gains: `` `use` blocks are alphabetical (`SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses`); `composer cs:fix` sorts them. ``
- Modify: `.github/workflows/ci.yml` — the step name `PHPCS (PSR-12)` becomes `PHPCS (PSR-12, sorted imports)`.

- [ ] **Step 1:** Make the three edits; commit `docs(#2378): name the import-order rule`.
- [ ] **Step 2:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; the mutation gate has no executable changed line to score.
- [ ] **Step 3:** Push, open the PR into `develop` with `Closes #2378`. The body says: one new dev dependency and one Composer plugin, the file count, and that open branches elsewhere will conflict on `use` lines and resolve with `composer cs:fix`. Merge when CI is green; verify #2378 closed.
