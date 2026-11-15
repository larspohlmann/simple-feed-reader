# Trending French and Spanish audit (#2235) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record that French and Spanish need no language-rule change after #2227, with the measurements that show it,
and confirm on the branch that nothing else moved.

**Architecture:** No code change. The spec measured the tags and two candidate rules and decided against both (D1, D2).
The work is two documentation edits and a confirming measurement:
- the #2227 spec's Outcome gets a *French and Spanish (#2235)* note, including the dev database's fill tags (M2);
- the #2235 spec's Outcome gets the confirming A/B run.

**Tech Stack:** PHP 8.5, Symfony 8.1 (for the measurement only), Markdown.

**Spec:** `docs/superpowers/specs/2026-11-15-2235-trending-french-spanish-design.md`. It is the binding authority. Read
its Measurements M1–M4 and Decisions D1–D5 before Task 1.

## Global Constraints

- **No code change.** Nothing under `backend/`, `frontend/` or `.github/` is modified. If a step seems to need one, stop
  and report to the planner: the spec decided against every rule change (D1, D2, D4, D5).
- **No curation from the window.** No account, word or value is taken from the measured window; the window only
  confirms. The stopword rule (G2) is recorded, not built (D2).
- **Dev database read-only.** No write, no migration, no fill, no `tests/bootstrap.php` against it (D3).
- Commit format `docs(#2235): lower-case imperative summary`, no Claude attribution. Main checkout, branch
  `feature/2235-trending-french-spanish-audit`. Never push.
- Commands run in the foreground, one window per command, none longer than about 3 minutes. No background jobs, no
  polling, no subagents.
- Prose in the house style of the #2227/#2231 Outcomes: short sentences, tables for numbers, no narration of the work.

## Review Focus

1. **The note in #2227 is accurate about the fill.** The stored social fr/es tags on dev were written by the #2227 fill
   with the four-language detector, before 49bf22822; Strato had no fill. The note must not claim that today's rule
   produced them.
2. **Numbers match the spec.** Every number in the #2227 note is one of M1–M4's; none is re-derived differently.
3. **The confirming run is a no-op.** A (develop 3cca6677f) and B (HEAD) share every `backend/src` file, so every
   window must show 0 new and 0 lost pairs. Any other result means the checkout or the swap is wrong, not the code.

---

### Task 1: The #2227 Outcome note

**Files:**
- Modify: `docs/superpowers/specs/2026-11-14-2227-trending-stemming-design.md` (end of the Outcome, after
  *Limitation*)

**Interfaces:** a new `### French and Spanish (#2235)` subsection, at most about 18 lines, holding:
- the French-only and Spanish-only A → B nets from M1 (day −6 / week +3 French, +4 / 0 Spanish), "all moves of real
  stories";
- the fill finding from M2: the dev social tags fr/es are the fill's four-language guesses (30 % / 24 % right by eye),
  not today's rule; the *Limitation* above was measured on them;
- the guess accuracy from M3: below 150 characters fr 8 of 28 and es 3 of 24 right, de and en 40 of 40 sampled; at
  150 and above fr 23 of 24 and es 21 of 24;
- the cost from M4: the wrong fr/es tags move no fr–fr or es–es pair (0 on the French-only and Spanish-only windows);
  G1 loses 102 day pairs; G2 ties (+1 day, −6 week); no rule change (#2235 D1, D2);
- the English side effect (D6): today's guess gives 359 short English posts no language that the fill tagged en,
  −255 week pairs against the fill tags; recorded, not fixed;
- a pointer to the #2235 spec for the tables.

- [ ] **Step 1:** Write the subsection. Cross-check every number against the #2235 spec's M1–M4.
- [ ] **Step 2:** `git diff --stat` shows only that file. Commit `docs(#2235): note french and spanish in the #2227 outcome`.

### Task 2: Confirming measurement, written into the #2235 Outcome

**Files:**
- Modify: `docs/superpowers/specs/2026-11-15-2235-trending-french-spanish-design.md` (the Outcome section)
- Scratch only, never committed:
  `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/5bb3d5ca-28c2-427b-85d0-779b6950f4b5/scratchpad/2235/`
  (`run.sh`, `pairs.py`, `keys.php`, `runs/`).

The kit is `…/scratchpad/measure-kit/` (`README.md`). Export
`DATABASE_URL='mysql://feedreader:feedreader@127.0.0.1:33306/feedreader?serverVersion=8.4&charset=utf8mb4' APP_ENV=dev`.

- [ ] **Step 1: Variants.** A = develop 3cca6677f: `git archive 3cca6677f` of `backend/src/Service/Curation/Trending`,
  the three `Trending*Repository.php` files and `backend/src/Command/TrendingExplainCommand.php`, extracted over the
  working tree and restored from a saved tar afterwards, never with `git checkout` (adapt `2235/run.sh`, which does
  this for a0f702721). B = HEAD. `php bin/console cache:clear` and `cache:warmup` after each swap.
- [ ] **Step 2: Runs.** Pin `T = "2026-11-15 12:00:00"`. For V in A, B and W in day, week:
  `/usr/bin/time -l php $KIT/simp-explain-at.php "$PWD" W "$T"`, on
  - the shared window,
  - the French-only window (`ONLY_LANG=fr`, the `COALESCE(e.language, f.language)` restriction in `run.sh`),
  - the Spanish-only window (`ONLY_LANG=es`).
  A and B back to back; equal entry counts in the header lines, or run A again.
- [ ] **Step 3: Compare.** `compare.py A B` per window, and `pairs.py A B - -` on the shared windows for de–de,
  en–en, fr–fr, es–es and mixed or none (regenerate `keys.json` with `keys.php` first). Expected: **0 new, 0 lost in
  every window and every language pair.** Record groups, grouped pairs, wall time, peak RSS and peak PHP memory.
- [ ] **Step 4: Eye check.** With 0 changed groups there is nothing to inspect; say so. If any group changed, stop and
  report the diff of `backend/src` between A and B instead of writing the Outcome.
- [ ] **Step 5:** Write the Outcome: one table (window, entries, groups, grouped pairs, new / lost / net, wall time,
  peak RSS, peak PHP memory) and one line per language pair, and the sentence that the spec's decision stands on M1–M4.
  Commit `docs(#2235): measure the french and spanish audit`.

### Task 3: Gates

**Files:** none beyond the two specs.

- [ ] **Step 1:** `git diff --name-only 3cca6677f..HEAD`. Expected: only `docs/superpowers/specs/…2227…`,
  `…2235…` and `docs/superpowers/plans/…2235…`. Anything else is reverted.
- [ ] **Step 2:** `git status --short` is clean after the measurement's restore (no swapped file left behind), and
  `git diff 3cca6677f..HEAD -- backend frontend` is empty.
- [ ] **Step 3:** Run `/simplify` on the branch's two spec edits (prose only: cut repetition, keep numbers), commit any
  change as `docs(#2235): tighten the french and spanish record`. Report to the planner: the Outcome's numbers and
  the gate results. The PR is the controller's call; its body says `Closes #2235`.
