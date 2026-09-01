# SDD ledger — plan: .superpowers/issue-770-execution-plan.md

## Pre-flight review

| Scope | Producer | Consumer | Finding |
|---|---|---|---|
| Task 1 self-check | Entry author from endpoint and audit sample | Article extractor and engagement cleaner | The issue requires byline preservation when no reader-meta author exists. The current extractor receives only the title. Task 1 explicitly carries the nullable author through both paths. |
| Task 1 self-check | Engagement cleaner | Reader audit marker | Both must use the same position and shape contract. Task 1 requires direct tests plus cleaned-output agreement. |
| Task 2 self-check | Innermost reader-card classification | Ancestor reader-card classification | The current outer-first walk lets ancestors borrow descendant signals. Task 2 requires inside-out evaluation and owned-content signals. |
| Task 1 / Task 2 | Backend cleaned HTML | Frontend post-render card classifier | No interface change is required between tasks. Task 2 must operate correctly whether Task 1 removed leading chrome or received any other sanitized article body. |

Ruling: Preserve the repeated standfirst even though the acceptance text says the article opens on the following Hamburg paragraph — the issue explicitly places near-duplicate paragraph removal out of scope — cost if wrong: the safe but repeated standfirst remains at the top.

Ruling: Let an image-only innermost promo qualify as a card — the required regression test says the promo, not its ancestor, receives the card — cost if wrong: an uncaptioned linked article image in its own candidate container can receive insert styling.

Ruling: Use the hidden SDD plan file even though bounded brainstorming normally has no plan document — the user explicitly requested subagent-driven execution and the SDD workflow requires a task-addressable plan — cost if wrong: process-only scratch work exists until final cleanup; no tracked repository file changes.

## Task 1 status

Completed: leading emoji, counters, time-only blocks, duplicate bylines, and
their empty remnants now clean before the reader's edge trim. The endpoint and
audit sampler pass entry authors into the shared extractor contract. The audit
uses the cleaner's shared prose rule and reports the issue fixture before
cleanup, then no finding after cleanup.

Evidence: focused tests pass (71 tests, 231 assertions); native backend suite
passes (4,064 tests, 19,933 assertions, 44 existing notices); `composer check`
and touched-file PHPMD pass. Docker MySQL full-suite verification twice reached
the same unrelated clock-comparison failure in
`RecommendationRunAdvancerTest::testATickThatStreamsRefreshesItsLock`; its
isolated Docker test passes. This remains a task concern, not a Task 1 change.

## Task 2 implementation

Implemented inside-out reader-card classification with nearest-candidate signal
ownership and a captioned-figure guard. Focused RED failed in the three expected
branches. Focused GREEN passed 12 tests. The full frontend check passed 193
suites and 2,257 tests. The host production build passed outside the sandbox.
The implementation is ready for the Task 2 review; see `task-2-report.md`.

Task 2 implementation commit: `6993c780`.
