# For you stream (#2582) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A second per-user list beside For you, filled every minute from newly ingested entries whose stems match a weighted, AI-derived term list, readable and mark-readable like the combined saved-search list, with a sidebar badge.

**Architecture:** A follow-up AI call (its own `InterestTermRun`, on the distillation's connection and call path) turns the stored prose profile into weighted terms per language; they are stemmed with `Foundation/Stemming` and stored with a 30-day rarity. A budgeted sweep modelled on `SavedSearchMembershipSweep` walks settled entries above a per-user mark, stems each once, scores it against every waiting user's terms, and writes hits to `for_you_stream_entry`. Reads, mark-read and tallies go over that table the way the saved-search ones go over `saved_search_entry`.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Doctrine ORM + DBAL (MySQL and SQLite), Symfony Scheduler/Messenger, Angular 20 standalone components with signals, Jest, Transloco.

**Spec:** `docs/superpowers/specs/2026-11-27-for-you-stream-design.md` (binding; every "my call"/"(Lars)" point is decided).

## Rulings

Decisions the spec and the code left open. Each one gives what was decided, why, and what it costs if it is wrong.

1. **The term call keeps its own run record, `InterestTermRun`, and does not use `ProfileRun`.** Why: a `ProfileRun` opens with a fingerprint check and may end `Unchanged` without a call. Accounts with a profile at deploy and manual-only schedules may never get a profile run at all, and the spec rules out changing that state machine. This follows `StoryAiRun`: a table, a `CallingRun`, and an owner column on `recommendation_run_log`. If wrong: we have one table and one log column we did not need.
2. **The term refresh gets its own 5-minute worker entry (`RefreshDueInterestTerms`) and its own maintenance sweep (`interestTerms`).** It does not run inside `StartDueProfileRuns` or `ForYouSweep`. Why: in this code no single "profile-run tick" exists (see the contradictions below). `StartDueProfileRuns` only starts runs, and a slow model call does not belong in the recommendation cron pass. The cadence is the same and the due rule is the spec's. If wrong: we have one schedule entry more than we needed.
3. **The call path matches distillation:** `RateLimitedCompletion` through `RecommendationProviderCall`, recorded with `CompletionCallRecorder`.
   - The refresh holds the account's `UserTickLock`, so a worker firing and a cron firing never both call for one user.
   - It uses `RetryPlanModel::deferring()`.
   - A rate limit skips the user with no strike.
   - A rejected request fails the run. There is no `RejectedRequestRecovery`.
   - If wrong: a provider that needs parameter fallback never builds terms until we add recovery.
4. **A run belongs to one profile version (`profileGeneratedAt`).** After 3 unusable replies or 3 transport failures it fails, the previous term set stays in force, and no new run opens for that profile version. The next profile regeneration retries. If the profile changes while a run is still retrying, a new run opens for the new version. If wrong: a provider that was down for 15 minutes leaves an account without terms until its next profile.
5. **The reply format.**
   - The reply is a flat `{"terms": [{"language", "term", "weight"}]}` list. `weight` is an integer from 1 to 10, clamped, and stored as `weight / 10`.
   - Each requested language keeps at most 60 terms, in reply order. Terms in a language that was not requested are dropped.
   - A phrase splits into its words, and each word carries the term's weight. A German compound's parts carry half the weight (`PART_WEIGHT_SHARE = 0.5`).
   - Stop-word stems are dropped. When a stem appears more than once, it keeps the highest weight.
   - If wrong: the scores shift. All of these are constants.
6. **The reader's languages are the distinct non-null `feed.language` values of the user's For-you-included subscriptions. When there are none, all four are used.** If wrong: someone who reads a language none of their feeds declares gets no terms in it.
7. **An entry's language is `COALESCE(entry.language, feed.language)`** (the rule `TrendingEntryRepository` uses). The body is the first 600 plain-text characters of the summary (`EntryPlainText::cut`). If wrong: a little recall is lost on long summaries.
8. **Rarity is `log((max(N, 500) + 2) / (df + 1))`.**
   - It is computed per language key over the newest N (`InterestTermRarity::SAMPLE_ENTRIES`, 5000) entries of the last 30 days of the user's For-you entries; N counts that sample. Entries with no language use the cut key. The cap bounds one account's walk, so a refresh always finishes within a tick.
   - The interest-term sweep computes it in the run that stores a term set (never-computed sets go first), and again once a day. Matching reads the stored value. Rarity is 1.0 until it is first computed.
   - One sweep run reads the window's first entry id once and shares one word-stem cache across its accounts, capped at `EntryStemming::CACHED_WORDS`.
   - If wrong: the first sweep after a store sees one tick of unrarified scores (they all stay under the threshold).
9. **Scoring.** A stem counts once, at its best position (headline 1.0, body 0.5). An entry enters at `MIN_SCORE = 1.5`. If wrong: the stream is too loud or too quiet. The stored scores tell us which, and the fix is one constant.
10. **The 48-hour window reads `entry.effective_date`** (published, else created) at sweep time. The settle delay is 60 s, as for saved searches. The first mark is the settled ceiling when the user's first term set is stored, and replacing the set keeps the mark. If wrong: an entry with no publish date that is created late enters anyway. That is accepted.
11. **Read-time scope.** Stream rows are visible only while the user still subscribes to the entry's feed and the subscription, its network and its tags are switched in for For you (`InclusionScopeDql::restrict(…, ForYou)`). The same scope applies to the list, mark-read and counts, as saved searches do with `inSavedSearches`. If wrong: a feed switched back in reveals its old matches again. That is intended.
12. **The list reads the repository straight from the controller**, as `SavedSearchEntriesController` reads `SavedSearchRepository`, with no pass-through service. Mark-read lives in `Service/Library/Reading/ForYouStreamMarkReadService`, because the boundary rule puts mark-read in Reading. Reading names only repositories, so `Reading → Recommendation` never appears.
13. **One new mark-read endpoint, `POST /api/entries/for-you-stream/mark-read {until}`.** Marking one entry and mark-above-read already write per-entry read state through `/api/entries/{id}/state` and `/api/entries/mark-read-batch`. If wrong: we add a no-op duplicate endpoint later.
14. **The stream's tally is `{available, unreadCount, entryCount}` in the surface totals.** `available` is "has a term set". The sidebar row shows whenever `available` is true, even after the AI connection is gone, because matching is local. The badge follows the server totals and gets no optimistic patch, as For you does.
15. **Dropped: no digest section.** Lars, 2026-11-27: "For you stream should not be in email digest. It's a stream".
16. **Term runs get no debug-log UI and no `AiRunKind` case.** Their call logs are kept for the newest `RunLogRetention::RUNS` term runs, as `StoryAiRuns` keeps them. If wrong: we add one enum case and one loader arm.
17. **Matched stems are stored space-joined, highest contribution first, and cut at 255 characters.**
18. **The list takes the unread switch and the newest/oldest order**, as the combined saved-search list does. Newest is the default.
19. **Dropped** with Ruling 15.
20. **The term refresh and the sweep skip inactive accounts** (`ActiveAccountDql`).

### Where the spec and the code disagree

- **§1 names `Content/Stemming` with `HeadlineStemmer`, `HeadlineEquivalents` and `HeadlineFiller`.** It landed in #2583 as `Foundation/Stemming` with `WordStemmer`, `WordEquivalents::canonicalizeWords()/stemWordAndParts()` and `StopWordStems::isStopWordStem()`. This plan uses the names that landed.
- **"The check runs on the profile-run tick".** No such single tick exists. The worker's `StartDueProfileRuns` only starts runs, `WorkerRunSweep` advances active runs every 10 s, and on cron `ForYouSweep::sweepOnce()` does both inside a 240 s request. See Ruling 2.
- **"Stemmed in the feed's language … as Trending does".** Trending stems in the entry's own language first and falls back to the feed's. The plan follows Trending (Ruling 7).
- **"Its own mark-read and mark-all-read endpoints".** Per-entry read already goes through the generic endpoints, which write per-entry state. Only mark-all-read needs a stream endpoint (Ruling 13).

## Global Constraints

- **Gate cadence.** Each task runs only its covering tests, plus `composer cs -- <changed files>` and `composer stan` (always the whole tree; warm the cache first with `bin/console cache:warmup`). A frontend task runs its spec files in the frontend image (see below).
  - **The full gate set runs once, before the PR**, after the final review and `/simplify`, started concurrently: `composer cs`, `stan`, `md`, `tramp`, both test legs, `infection:diff`, PhpStorm lint, `npm run check` and `npm run build`. Jest never runs alongside the test legs.
  - That run is `finishing-a-development-branch`'s "verify tests" step.
- **The worktree caveat.** The Docker `frontend` and `php` services bind-mount the main checkout, not this worktree. Run a frontend spec from the worktree with:
  `docker run --rm -t -v "$PWD/frontend:/app" -v simple-feed-reader_frontend-node-modules:/app/node_modules -w /app node:22-slim npx jest <spec path>`.
  Backend tests run natively (`php bin/phpunit`, SQLite), against the worktree's own `vendor/`.
- **Commits** use `feat(#2582): lower-case summary`, or `refactor(#2582)`/`test(#2582)` where that fits. No attribution lines. The PHPMD commit hook blocks new `backend/src` classes while a finding is open. Fix the design and do not split a class because of a count.
- **HTTP statuses** are `Response::HTTP_*` in `src` and tests, never a literal. Add no PHPStan ignores, no baselines and no `tests/PhpStan` rule changes.
- **Every migration** has a MySQL and a SQLite branch in the house pattern (see `migrations/Version20261118200000.php`):
  - `skipIf` guards in `up` and `down`, `isTransactional(): false`, and an abort on any other platform.
  - Take the FK and index names from `bin/console doctrine:migrations:diff` on each platform.
  - The suite never executes migrations, so verify each one by migrating an empty SQLite file and an empty MySQL database, then running `doctrine:schema:validate`. The CI leg does the same.
  - No migration here rebuilds `entry` or `entry_transcript`, so the FTS5 trigger gotcha does not apply. Adding tables with an FK to `entry` and `ALTER TABLE … ADD COLUMN` on SQLite rebuild nothing.
- **Naming.** Use the house verbs (CLAUDE.md table) and role folders (§10). No `…Of`/`of…` names. Every new class is `final readonly` unless it is an entity or a `Pass/`.
- **Where things live.** New stream services live in `backend/src/Service/Curation/Recommendation/Stream/`, which is part of the `Curation/Recommendation` module. LLM-specific classes live in the `Recommendation/Llm` sub-module, which may depend on its parent and never the reverse. `Stream/` must not name `Llm`.
- **Datetimes are naive UTC.** Use `NaiveUtcClock` or `ClockInterface` as the neighbouring code does.
- **Every new UI string** is added to `de`, `en`, `fr` and `es`. French uses *vous* and Spanish uses *tú*. The glossary is `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md`.
- **The frontend** uses no hex colours and no ad-hoc px, keeps styles in sibling `.scss` files, and reuses the existing `.nav` row (`docs/design-language.md`).

## Review Focus

1. **A reader whose feeds declare no language.** The term call asks for all four languages, and their entries (no language, cut stems) still match through the cut-form terms. Pinned in Task 4 (`testAReaderWithNoFeedLanguageIsAskedForAllFour`) and Task 6 (`testAnEntryWithoutALanguageMatchesThroughTheCutForm`).
2. **A sloppy model reply:** an unrequested language, a weight of 0 or 42, a weight given as `"7"`, 80 terms in one language, empty terms. The parser drops or clamps each case and still yields what is usable. Pinned in Task 2.
3. **The profile regenerates while a term run for the old version is retrying.** The old run is abandoned, a fresh run asks with the new profile, and the attempt counter restarts. Pinned in Task 4 (`testANewProfileOpensAFreshRunInsteadOfContinuingTheOldOne`).
4. **A feed is switched out of For you after its entries matched.** Its rows leave the list, the unread count and mark-read. Pinned in Task 8 (`testAFeedSwitchedOutOfForYouHidesItsMatches`) and Task 9.
5. **A newly subscribed feed's back catalogue lands above the mark.** Entries older than 48 h are not matched, and the mark still passes them. Pinned in Task 6 (`testAnEntryPublishedMoreThan48HoursBeforeTheSweepIsSkippedAndPassed`).

---

### Task 1: Interest-term storage and term stems

**Files:**
- Create: `backend/src/Entity/InterestTermSet.php`, `backend/src/Entity/InterestTerm.php`
- Create: `backend/src/Repository/InterestTermSetRepository.php`, `backend/src/Repository/InterestTermRepository.php`
- Create: `backend/src/Service/Curation/Recommendation/Stream/Model/InterestTermModel.php`, `…/Stream/Model/TermStemModel.php`, `…/Stream/Model/InterestTermsModel.php`
- Create: `…/Stream/InterestTermStems.php`, `…/Stream/InterestTermStore.php`, `…/Stream/StreamCeiling.php`
- Create: `backend/migrations/Version20261127120000.php`
- Test: `backend/tests/Service/Curation/Recommendation/Stream/InterestTermStemsTest.php`, `…/Stream/InterestTermStoreTest.php`, `backend/tests/Repository/InterestTermRepositoryTest.php`

**Interfaces:**
- Consumes: `WordEquivalents::canonicalizeWords(string): list<array{string,string}>`, `WordEquivalents::stemWordAndParts(string $word, string $bare, ?FeedLanguage): list<string>`, `StopWordStems::isStopWordStem(string, ?FeedLanguage): bool`, `EntryMembershipSweepRepository::settledCeilingId(\DateTimeImmutable): int`.
- Produces:
  - `InterestTermModel(FeedLanguage $language, string $phrase, float $weight)`: one term from the reply, with its weight in (0, 1].
  - `TermStemModel(?FeedLanguage $language, string $stem, float $weight)`: a null language is the cut form.
  - `InterestTermsModel`:
    - built through `fromStrengths(array<string, array<string, float>> $strengthsByLanguageKey)`;
    - `static languageKey(?FeedLanguage): string`, which is the language's value or `''` for the cut form;
    - `findStrength(?FeedLanguage, string $stem): ?float`, the weight × rarity;
    - `isEmpty(): bool`.
  - `InterestTermStems::buildTermStems(list<InterestTermModel>): list<TermStemModel>`.
  - `InterestTermStore::replaceTerms(User $user, \DateTimeImmutable $profileGeneratedAt, list<InterestTermModel> $terms): InterestTermSet`.
  - `StreamCeiling::readSettledCeiling(): int`, with `StreamCeiling::SETTLE_SECONDS = 60`.
  - `InterestTermSet`:
    - columns `profile_generated_at`, `stored_at`, `rarities_refreshed_at` (nullable) and `matched_up_to_entry_id`, keyed by user (`#[ORM\Id] #[ORM\OneToOne]`, cascade);
    - `getProfileGeneratedAt()`, `getStoredAt()`, `getRaritiesRefreshedAt()`, `matchedUpToEntryId(): int`;
    - `replaceTerms(\DateTimeImmutable $profileGeneratedAt, \DateTimeImmutable $storedAt): void`, which clears `raritiesRefreshedAt`;
    - `recordRaritiesRefreshed(\DateTimeImmutable): void`.
  - `InterestTerm`:
    - columns `user_id` (FK, cascade), `stem` VARCHAR(64), `language` VARCHAR(2) (`enumType: FeedLanguage`, nullable), `weight` FLOAT and `rarity` FLOAT default 1.0, with index `idx_interest_term_user`;
    - `getStem()`, `getLanguage()`, `getWeight()`, `getRarity()`, `recordRarity(float): void`.
  - `InterestTermSetRepository`:
    - `findForUser(User): ?InterestTermSet`;
    - `hasForUser(int $userId): bool`.
  - `InterestTermRepository`:
    - `deleteForUser(int $userId): void`;
    - `findForUser(int $userId): list<InterestTerm>`;
    - `findTermsForUsers(list<int> $userIds): array<int, InterestTermsModel>`, where every requested id keeps its key, with an empty model for none.

- [ ] **Step 1: Write the failing tests**

`InterestTermStemsTest` builds `InterestTermStems` from `StemmingWiring::equivalents()` and `StemmingWiring::stopWordStems()`. Expected stems are computed with `StemmingWiring::stemmer()`, never typed in.

```php
public function testATermIsStoredInItsLanguageAndInTheCutForm(): void
{
    $stems = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::English, 'climate', 0.8)]);
    self::assertEqualsCanonicalizing([
        [FeedLanguage::English, $this->stemmer->stem('climate', FeedLanguage::English), 0.8],
        [null, WordStems::cut('climate'), 0.8],
    ], self::rows($stems));
}

public function testAPhraseSplitsIntoWordsThatEachCarryTheTermsWeight(): void
// 'heat pump policy' (en, 0.6) yields en stems of heat, pump and polici at 0.6, plus their cut forms.

public function testAGermanCompoundsPartsCarryHalfTheWeight(): void
// 'Wärmepumpe' (de, 1.0): the compound's de stem at 1.0, each part's de stem at 0.5, and the cut form at 1.0.

public function testStopWordStemsAreDropped(): void
// 'the energy of the future' (en) holds no stem for which StopWordStems::isStopWordStem() is true.

public function testADuplicateStemKeepsItsHighestWeight(): void
// 'solar' at 0.4 and 'solar power' at 0.9 leave exactly one en row for solar, at 0.9.

public function testAnInstitutionsSpellingsShareTheCanonicalStem(): void
// 'European Union' (en) and 'Europäische Union' (de) both yield 'eunion' in their language.
```

`InterestTermStoreTest` (a `DbTestCase` with a `MockClock`):

```php
public function testTheFirstTermSetStartsTheMarkAtTheSettledCeiling(): void
{
    $old = $this->entry(createdAt: '2026-11-27T09:00:00');
    $this->entry(createdAt: '2026-11-27T09:59:30'); // younger than SETTLE_SECONDS
    $set = $this->store->replaceTerms($this->user, new \DateTimeImmutable('2026-11-27T08:00:00'), [$this->term('solar')]);
    self::assertSame($old->requireId(), $set->matchedUpToEntryId());
}

public function testReplacingTheTermsKeepsTheMarkAndClearsTheRarities(): void
// Store, advance the mark to 42 via the repository and record rarities, then store again:
// the mark is still 42, getRaritiesRefreshedAt() is null, and only the second set's stems remain.

public function testAnotherUsersTermsAreUntouched(): void
```

`InterestTermRepositoryTest::testFindTermsForUsersCombinesWeightAndRarityPerLanguageKey` checks that `findStrength(English, 'solar')` returns 0.8 × 2.5, `findStrength(null, 'solar')` reads the cut row, and a user with no terms gets `isEmpty()`.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Stream tests/Repository/InterestTermRepositoryTest.php`. They fail because the classes are missing.
- [ ] **Step 3: Implement the entities and the migration** for `interest_term_set` and `interest_term`, with both platform branches.
- [ ] **Step 4: Implement the models, the repositories, `InterestTermStems` and `StreamCeiling`.**
  - `InterestTermStems::PART_WEIGHT_SHARE = 0.5`.
  - The cut form comes from `stemWordAndParts($word, $bare, null)`. The first stem is the word's own and the rest are its parts.
  - `StreamCeiling` returns `settledCeilingId(now − SETTLE_SECONDS)`.
- [ ] **Step 5: Implement `InterestTermStore::replaceTerms()`.**
  - It runs in one `wrapInTransaction`: `deleteForUser`, then one `new InterestTerm(...)` per stem, then either the set's `replaceTerms(...)` or a new set with mark = `readSettledCeiling()`.
  - It returns the set.
- [ ] **Step 6: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`. Verify the migration on empty SQLite and MySQL databases with `doctrine:schema:validate`.
- [ ] **Step 7: Commit** with `feat(#2582): interest-term storage with stems per language and a cut form`.

### Task 2: The term prompt and its reply parser

**Files:**
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/Model/RecommendationResponseSchema.php`, `…/Llm/Prompt/Model/CallPromptModel.php`, `…/Llm/Prompt/RecommendationAnswerBudget.php`, `…/Llm/Prompt/Support/RecommendationPromptText.php`, `…/Llm/Prompt/RecommendationPromptBuilder.php`
- Create: `…/Llm/Prompt/InterestTermParser.php`, `…/Llm/Prompt/Model/InterestTermParseResultModel.php`
- Test: `backend/tests/Service/Curation/Recommendation/Llm/Prompt/InterestTermParserTest.php`, and extend `RecommendationPromptBuilderTest`, `RecommendationAnswerBudgetTest` and the schema test

**Interfaces:**
- Consumes: `InterestTermModel` (Task 1) and `ModelReplyJsonDecoder::decode(string): ?array`.
- Produces:
  - `RecommendationResponseSchema::InterestTerms`, with JSON schema name `interest_terms` and shape `{terms: [{language: string, term: string, weight: integer}]}`, all fields required and `additionalProperties: false`.
  - `CallPromptModel::interestTerms(array $messages): self`.
  - `RecommendationAnswerBudget` arm `InterestTerms => INTEREST_TERMS_ANSWER_TOKENS = 3000`.
  - `RecommendationPromptText::MAX_TERMS_PER_LANGUAGE = 60`, `INTEREST_TERMS_ROLE`, `INTEREST_TERMS_OUTPUT_CONTRACT` and `INTEREST_TERMS_CORRECTIVE`.
  - `RecommendationPromptBuilder::interestTermMessages(string $profileText, list<FeedLanguage> $languages): list<array{role: string, content: string}>`.
  - `InterestTermParser::parse(string $content, list<FeedLanguage> $languages): InterestTermParseResultModel`.
  - `InterestTermParseResultModel` is built through `usable(list<InterestTermModel>)` or `unusable()`, and exposes `bool $usable` and `list<InterestTermModel> $terms`.

The prompt copy is fixed here:

```php
public const string INTEREST_TERMS_ROLE = 'You turn one reader\'s interest profile from an RSS reader into search '
    . 'terms. The user message holds LANGUAGES, the languages the reader reads, and PROFILE, the reader\'s '
    . 'interests in prose. For each language, list the specific interests and named things in the profile — '
    . 'subjects, places, people, companies, products, technologies — as the words a headline in that language '
    . 'would use. A term is one word or a short phrase of at most three words, never a broad category such as '
    . '"news" or "technology". Weigh each term from 1 to 10 by how central it is to the reader. At most '
    . self::MAX_TERMS_PER_LANGUAGE . ' terms per language.';
public const string INTEREST_TERMS_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"terms": [{"language": '
    . '"<language code>", "term": "<term>", "weight": <1-10>}]}. Use only the language codes listed in LANGUAGES.';
public const string INTEREST_TERMS_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
    . 'exactly in the required shape: {"terms": [{"language": "<language code>", "term": "<term>", "weight": <1-10>}]}.';
```

The user message is `"LANGUAGES: de (German), en (English)\n\nPROFILE:\n" . $profileText`. Each language is written as its code followed by `englishName()` in brackets, in the order given.

- [ ] **Step 1: Write the failing tests.**

```php
public function testATermInARequestedLanguageIsKeptWithItsWeightOverTen(): void
{
    $result = $this->parser->parse('{"terms":[{"language":"de","term":"Wärmepumpe","weight":7}]}', [FeedLanguage::German]);
    self::assertTrue($result->usable);
    self::assertEquals([new InterestTermModel(FeedLanguage::German, 'Wärmepumpe', 0.7)], $result->terms);
}

public function testATermInALanguageNotAskedForIsDropped(): void      // "fr" asked [de] → unusable when it was the only one
public function testWeightsAreClampedToOneToTen(): void               // 0 → 0.1, 42 → 1.0
public function testANumericStringWeightIsRead(): void                // "7" → 0.7; "high" drops the term
public function testEmptyAndOverlongTermsAreDropped(): void           // "" and "  " dropped; an 81-char term dropped
public function testEachLanguageKeepsItsFirstSixtyTerms(): void       // 80 de terms → the first 60, in order
public function testAReplyThatIsNotJsonOrHasNoTermsListIsUnusable(): void
public function testAReplyWhoseTermsAllDropIsUnusable(): void
```

Extend the existing tests with these:
- `RecommendationPromptBuilderTest::testInterestTermMessagesNameTheLanguagesThenTheProfile`. The system message is ROLE + "\n\n" + CONTRACT. The user message is exactly `"LANGUAGES: en (English), fr (French)\n\nPROFILE:\nself-hosting"`.
- `RecommendationAnswerBudgetTest::testInterestTermsReserveThreeThousandTokensPlusTheBound`. It asserts `answerBoundTokens(1, InterestTerms) === intdiv(3000 * 150, 100)`.
- The schema test asserts the `interest_terms` name and the required keys.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Llm/Prompt`. They fail because the case and the classes are missing.
- [ ] **Step 3: Implement the enum case, the budget arm, `CallPromptModel::interestTerms()`, the prompt text, the builder method and the parser.** The parser keeps a term when its trimmed length is between 1 and 80 characters (`MAX_TERM_LENGTH = 80`).
- [ ] **Step 4: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 5: Commit** with `feat(#2582): the interest-term prompt and its reply parser`.

### Task 3: The term run record and the LLM extractor

**Files:**
- Create: `backend/src/Entity/InterestTermRun.php`, `backend/src/Repository/InterestTermRunRepository.php`
- Modify: `backend/src/Enum/CallPhase.php`, `backend/src/Repository/CallingRun.php`, `backend/src/Entity/RecommendationRunLog.php`, `backend/src/Repository/RecommendationRunLogRepository.php`
- Modify: `backend/src/Service/Curation/Recommendation/Run/Factory/RecommendationRunLogFactory.php`, `…/Run/RecommendationCallRecorder.php`, `…/Llm/Run/CompletionCallRecorder.php`
- Create: `…/Stream/InterestTermExtractor/InterestTermExtractorInterface.php`, `…/Stream/Pass/InterestTermPass.php`, `…/Stream/Model/InterestTermOutcomeModel.php`
- Create: `…/Llm/Run/LlmInterestTermExtractor.php`
- Create: `backend/migrations/Version20261127130000.php`
- Test: `backend/tests/Service/Curation/Recommendation/Llm/Run/LlmInterestTermExtractorTest.php`, `backend/tests/Repository/RecommendationRunLogRepositoryTest.php` (extend)

**Interfaces:**
- Consumes: Task 2's prompt, parser and schema; `RecommendationProviderCall::complete(ProviderCallRouteModel, CompletionRequestModel, RecordedCall): string`; `RecommendationCompletionRequestFactory::create(AiProviderSettings, CallPromptModel)`; `RecommendationPromptBuilder::messagesWithCorrectiveTail(array, ?string, string)`.
- Produces:
  - The `InterestTermRun` entity, table `interest_term_run`:
    - columns `id`, `user_id` (FK, cascade; index `idx_interest_term_run_user`), `profile_generated_at`, `status` (`RunStatus`, length 16), `created_at`, `completed_at` (nullable), `error` (TEXT, nullable) and `streamed_chars` (default 0), plus the embedded `RunCallAttempts` and `ProviderUsage`;
    - `MAX_ATTEMPTS = 3` and `MAX_TRANSPORT_FAILURES = 3`;
    - `__construct(User $user, \DateTimeImmutable $profileGeneratedAt, \DateTimeImmutable $createdAt, ?string $providerHost, ?string $model)`, which starts the run as `Running`;
    - getters, and `isForProfile(\DateTimeImmutable): bool`;
    - `recordInvalidReply(string)` and `hasExhaustedAttempts()`;
    - `recordTransportFailure()` and `hasExhaustedTransportRetries()`;
    - `complete(\DateTimeImmutable)` and `fail(string $error, \DateTimeImmutable)`, both guarded to `Running` (`InvalidRunStatusException`).
  - `InterestTermRunRepository::findLatestForUser(User): ?InterestTermRun` and `findNewestIdsForUser(User, int $limit): list<int>`.
  - `CallPhase::InterestTerms = 'interest_terms'`, `CallingRun::interestTermRun(int $id)` (`interest_term_run`, `interest_term_run_id`), and `RecommendationRunLog::forInterestTermRun(InterestTermRun, int $attempt, string $requestBody, \DateTimeImmutable)`. The run log gets a nullable `interest_term_run_id` FK (cascade) with index `idx_recommendation_run_log_interest_term_run`.
  - `RecommendationRunLogFactory::createForInterestTermRun(InterestTermRun, string): RecommendationRunLog`, where the attempt is `attempts + transportFailures + 1`.
  - `RecommendationCallRecorder::beginForInterestTermRun(InterestTermRun, string): RecordedCall` and `CompletionCallRecorder::beginForInterestTermRun(InterestTermRun, CompletionRequestModel): RecordedCall`.
  - `RecommendationRunLogRepository::deleteForUserOutsideInterestTermRuns(User, list<int>): void`.
  - `InterestTermPass(User $user, AiProviderSettings $connection, InterestTermRun $run, string $profileText, list<FeedLanguage> $languages)`, with `callRoute(): ProviderCallRouteModel` that uses `RetryPlanModel::deferring()`.
  - `InterestTermOutcomeModel`, built through `usable(list<InterestTermModel>)` or `unusable(string $reply)`, with `bool $usable`, `requireTerms(): list<InterestTermModel>` and `requireUnusableReply(): string`.
  - `InterestTermExtractorInterface::extractTerms(InterestTermPass): InterestTermOutcomeModel`, implemented by `LlmInterestTermExtractor`.

- [ ] **Step 1: Write the failing tests.** These are `DbTestCase` tests with `StubChatClient` from the test container, following `ProfileRunTickTest`'s setup.

```php
public function testAUsableReplyIsRecordedAsAUsableCallAndReturnsTheTerms(): void
{
    $this->chat->queueContent('{"terms":[{"language":"en","term":"solar","weight":9}]}');
    $outcome = $this->extractor->extractTerms($this->pass([FeedLanguage::English]));
    self::assertEquals([new InterestTermModel(FeedLanguage::English, 'solar', 0.9)], $outcome->requireTerms());
    $log = $this->onlyLogRow();
    self::assertSame(CallPhase::InterestTerms, $log->getPhase());
    self::assertSame(CallVerdict::Usable, $log->getVerdict());
    self::assertSame('interest_terms', $this->chat->calls()[0]['responseSchemaName']);
}

public function testAnUnusableReplyIsRecordedUnusableAndReturnedForTheRetry(): void
public function testTheRetryCarriesTheLastInvalidReplyAndTheCorrective(): void
// The run already holds an invalid reply. The call's messages end with the assistant turn and then INTEREST_TERMS_CORRECTIVE.
public function testATransportFailureSettlesTheLogRowAndPropagates(): void
// The client throws ProviderUnreachableException. It propagates, and the log row's verdict is TransportFailed.
```

Extend `RecommendationRunLogRepositoryTest` with `testDeleteOutsideInterestTermRunsKeepsTheNamedRunsAndOtherOwners`.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Llm/Run/LlmInterestTermExtractorTest.php tests/Repository/RecommendationRunLogRepositoryTest.php`.
- [ ] **Step 3: Implement the entity, the repository, the `CallPhase` case, `CallingRun`, the log owner, the factory and recorder methods, and the migration.**
  - The migration creates `interest_term_run` and adds the log column, following `Version20261118150000`'s `story_ai_run` steps on both platforms.
  - `RecommendationRunLog`'s private constructor gains the owner. Keep it PHPMD-clean. If the parameter count trips, give the four owners one value instead of adding a ninth parameter.
- [ ] **Step 4: Implement `InterestTermPass`, `InterestTermOutcomeModel`, the interface and `LlmInterestTermExtractor`**, mirroring `LlmProfileRunDistiller`. The corrective tail comes from `$pass->run->getLastInvalidReply()`.
- [ ] **Step 5: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`. Verify the migration on both platforms.
- [ ] **Step 6: Commit** with `feat(#2582): a recorded interest-term call with its own run record`.

### Task 4: The term refresh and its scheduling

**Files:**
- Modify: `backend/src/Repository/RecommendationSettingsRepository.php`
- Create: `backend/src/Repository/ForYouStreamSourceRepository.php`, which starts with `findFeedLanguages`
- Create: `…/Stream/DueInterestTermFinder.php`, `…/Stream/InterestTermRuns.php`, `…/Stream/InterestTermRefresh.php`, `…/Stream/InterestTermSweep.php`
- Create: `…/Stream/Model/DueInterestTermsModel.php`, `…/Stream/Model/InterestTermRefreshResult.php` (an enum), `…/Stream/Model/InterestTermSweepReportModel.php`
- Create: `backend/src/Service/Operations/Worker/Message/RefreshDueInterestTerms.php`, `…/Worker/Handler/RefreshDueInterestTermsHandler.php`
- Modify: `backend/src/Service/Operations/Worker/WorkerSchedule.php`
- Create: `backend/src/Service/Operations/Maintenance/MaintenanceSweep/InterestTermsTickSweep.php`, `backend/src/Http/SweepReportJson/InterestTermSweepJson.php`
- Test: `…/tests/Service/Curation/Recommendation/Stream/DueInterestTermFinderTest.php`, `InterestTermRefreshTest.php`, `InterestTermSweepTest.php`; update `WorkerScheduleWiringTest`, `MaintenanceSweepWiringTest`, `MaintenanceTickJsonTest` and `MaintenanceControllerTest`

**Interfaces:**
- Consumes: Tasks 1–3, `ProfileConnections::findUsableConnectionGiven(User, ?RecommendationSettings)`, `UserTickLock::acquire(User, float): ?HeldTickLock`, `TickLockTtl::computeSecondsForConnection(?AiProviderSettings)` and `RunLogRetention::RUNS`.
- Produces:
  - `RecommendationSettingsRepository::findWithStoredProfile(\DateTimeImmutable $now): list<RecommendationSettings>`: active owners whose `profile_text` is not null.
  - `ForYouStreamSourceRepository::findFeedLanguages(int $userId): list<FeedLanguage>`: distinct non-null `feed.language` over subscriptions passing `InclusionScopeDql::restrict(…, ForYou)`, in `FeedLanguage::cases()` order.
  - `DueInterestTermsModel(User $user, AiProviderSettings $connection, string $profileText, \DateTimeImmutable $profileGeneratedAt)`.
  - `DueInterestTermFinder::findDue(): list<DueInterestTermsModel>`.
  - `InterestTermRuns::openFor(DueInterestTermsModel): InterestTermRun`: it reuses the latest run when that run is `Running` and `isForProfile(...)`. Otherwise it persists a new run and prunes the call logs outside the newest `RunLogRetention::RUNS` term runs.
  - The `InterestTermRefreshResult` enum: `Stored`, `Retrying`, `Failed`, `Skipped`.
  - `InterestTermRefresh::refresh(DueInterestTermsModel): InterestTermRefreshResult`.
  - `InterestTermSweep::refreshDue(): InterestTermSweepReportModel` and `refreshDueBefore(\DateTimeImmutable $deadline): InterestTermSweepReportModel`.
  - `InterestTermSweepReportModel(int $due, int $stored, int $failed, int $raritiesRefreshed)`, with `none()` and `toLogContext()`. `raritiesRefreshed` stays 0 until Task 5.
  - The tick sweep is `#[AsTaggedItem(index: 'interestTerms', priority: 7)]`, and the JSON keys follow `toLogContext()`.

The due rule: a user is due when all of these hold:
- the account is active and has a stored profile;
- it has a usable profile connection;
- its term set is missing or older than the profile (`set.profileGeneratedAt < profile.generatedAt`);
- its latest run is not a `Failed` run `isForProfile(profile.generatedAt)`.

How `refresh()` ends:
- If the lock is held by someone else, it returns `Skipped` and makes no call.
- If the user's For-you feeds declare no language, it asks for all four.
- A usable reply calls `InterestTermStore::replaceTerms`, then `run->complete`, and returns `Stored`.
- An unusable reply calls `recordInvalidReply`. Once attempts are exhausted the run fails with `'The model gave no usable terms in %d attempts.'` and the result is `Failed`; before that it is `Retrying`.
- `ProviderUnreachableException`, `CredentialsRejectedException` and `RetryableProviderException` each count one strike. Once the strikes are exhausted the run fails with `ProviderFailedMessage::formatMessage(baseUrl, detail)` and the result is `Failed`; before that it is `Retrying`.
- `ProviderRejectedRequestException` fails the run with `ProviderFailedMessage` and returns `Failed`.
- `AiKeyUnreadableException` fails the run with `RecommendationRunAdvancer::KEY_UNREADABLE` and returns `Failed`.
- `ProviderRateLimitedException` returns `Skipped`, records no strike and leaves the run `Running`.

The sweep catches and logs any other `\Throwable` per user, as `ProfileRunSweep::startOne` does.

- [ ] **Step 1: Write the failing tests.**

```php
// DueInterestTermFinderTest
public function testAnAccountWithAProfileAndNoTermsIsDue(): void
public function testTermsAsNewAsTheProfileAreNotDue(): void
public function testTermsOlderThanTheProfileAreDue(): void
public function testAFailedRunForThisProfileStopsItUntilTheProfileChanges(): void
public function testAnAccountWithoutAUsableConnectionIsNotDue(): void
public function testAnInactiveAccountIsNotDue(): void

// InterestTermRefreshTest (StubChatClient, MockClock)
public function testAUsableReplyStoresTheTermsAndCompletesTheRun(): void
{
    $this->chat->queueContent('{"terms":[{"language":"en","term":"solar","weight":8}]}');
    self::assertSame(InterestTermRefreshResult::Stored, $this->refresh->refresh($this->due()));
    self::assertSame(RunStatus::Completed, $this->latestRun()->getStatus());
    self::assertEquals($this->profileGeneratedAt, $this->termSet()->getProfileGeneratedAt());
}
public function testThreeUnusableRepliesFailTheRunAndKeepThePreviousTerms(): void
public function testATransportFailureIsAStrikeAndTheThirdFailsTheRun(): void
public function testARateLimitSkipsWithoutAStrike(): void
public function testARejectedRequestFailsTheRun(): void
public function testAHeldTickLockSkipsWithoutACall(): void            // chat->calls() === []
public function testAReaderWithNoFeedLanguageIsAskedForAllFour(): void // the user message starts "LANGUAGES: de (German), en (English), fr (French), es (Spanish)"
public function testTheLanguagesAreThoseOfTheForYouFeeds(): void      // a fr feed switched out of For you is not asked for
public function testANewProfileOpensAFreshRunInsteadOfContinuingTheOldOne(): void // old Running run untouched; new run attempts === 0 and no corrective tail

// InterestTermSweepTest
public function testOneFailingUserDoesNotStopTheOthers(): void
public function testNoUserStartsAfterTheDeadline(): void
```

Update the wiring tests as follows:
- `RefreshDueInterestTerms::class` is the new last entry (`every('5 minutes')`), and `firstMessageClass($last)` is updated.
- `'interestTerms' => InterestTermsTickSweep::class` sits in the priority order between `trending` (10) and `storyAi` (5).
- The tick JSON and the controller tests list the new key.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Stream tests/Service/Operations tests/Http/MaintenanceTickJsonTest.php tests/Controller/MaintenanceControllerTest.php`.
- [ ] **Step 3: Implement the repository methods, the due finder, `InterestTermRuns`, `InterestTermRefresh` and `InterestTermSweep`.** The refresh keeps only the outcome dispatch. Opening goes to `InterestTermRuns`, and the exception-to-result mapping goes into private one-liners. Keep the refresh PHPMD-clean.
- [ ] **Step 4: Implement the message, the handler (it logs `'Worker interest-term refresh finished.'` with `toLogContext()`), the schedule entry, the tick sweep and the JSON mapper.**
- [ ] **Step 5: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 6: Commit** with `feat(#2582): refresh interest terms whenever the stored profile is newer`.

### Task 5: Term rarity

**Files:**
- Create: `…/Stream/Pass/EntryStemming.php`, `…/Stream/Factory/EntryStemmingFactory.php`, `…/Stream/Model/EntryStemsModel.php`, `…/Stream/Model/StreamEntryModel.php`, `…/Stream/InterestTermRarity.php`
- Modify: `backend/src/Repository/ForYouStreamSourceRepository.php` (add `findRecentEntries`), `backend/src/Repository/InterestTermSetRepository.php` (add `findWithStaleRarities`), `…/Stream/InterestTermRefresh.php`, `…/Stream/InterestTermSweep.php`
- Test: `…/Stream/Pass/EntryStemmingTest.php`, `…/Stream/InterestTermRarityTest.php`, and extend `InterestTermSweepTest`

**Interfaces:**
- Consumes: Task 1's entities and repositories, `WordEquivalents`, `StopWordStems` and `EntryPlainText::cut(?string, int)`.
- Produces:
  - `StreamEntryModel(int $id, ?FeedLanguage $language, string $title, ?string $summary)`.
  - `EntryStemsModel(?FeedLanguage $language, array<string, float> $positionWeights)`: stem => 1.0 when the stem is in the headline and 0.5 when it is only in the body.
  - `EntryStemming`, a pass with a per-pass word cache keyed by language value and word: `collectStems(StreamEntryModel): EntryStemsModel`, with `HEADLINE_WEIGHT = 1.0`, `BODY_WEIGHT = 0.5` and `BODY_CHARACTERS = 600`.
  - `EntryStemmingFactory::create(): EntryStemming`.
  - `ForYouStreamSourceRepository::findRecentEntries(int $userId, \DateTimeImmutable $since, int $afterId, int $limit): list<StreamEntryModel>`:
    - For-you-included subscribed feeds only, `effective_date >= :since` and `id > :afterId`, ascending;
    - the language is `COALESCE(e.language, f.language)` and the summary is `SUBSTR(e.summary, 1, 2000)`.
  - `InterestTermSetRepository::findWithStaleRarities(\DateTimeImmutable $refreshedBefore, \DateTimeImmutable $now): list<InterestTermSet>`: active owners whose sets have null rarities or rarities older than the cutoff.
  - `InterestTermRarity::refreshRarities(InterestTermSet): void`, with `WINDOW_DAYS = 30`, `COUNTED_ENTRIES_FLOOR = 500`, `CHUNK = 1000` and `REFRESH_HOURS = 24`.

- [ ] **Step 1: Write the failing tests.**

```php
// EntryStemmingTest
public function testHeadlineStemsWeighOneAndBodyOnlyStemsHalf(): void
public function testAStemInBothPlacesCountsOnceAtTheHeadlineWeight(): void
public function testAGermanCompoundYieldsItsPartsToo(): void
public function testStopWordsLeaveNoStem(): void
public function testAnEntryWithoutALanguageGetsCutStems(): void
public function testMarkupInTheSummaryIsStrippedAndCutAtSixHundredCharacters(): void

// InterestTermRarityTest
public function testARareTermWeighsMoreThanACommonOne(): void
{
    // 600 en entries in 30 days; 'solar' in 3 of them, 'energy' in 300
    $this->rarity->refreshRarities($this->set);
    self::assertEqualsWithDelta(log(602 / 4), $this->termRarity('solar', FeedLanguage::English), 1e-9);
    self::assertEqualsWithDelta(log(602 / 301), $this->termRarity('energy', FeedLanguage::English), 1e-9);
}
public function testASmallWindowCountsAtTheFloor(): void              // 10 entries → N = 500
public function testCutFormTermsCountOnlyEntriesWithoutALanguage(): void
public function testEntriesOutsideTheWindowOrOutsideForYouDoNotCount(): void
public function testTheRefreshIsStampedOnTheSet(): void

// InterestTermSweepTest (extended)
public function testStaleRaritiesAreRefreshedOnceADay(): void          // 23 h old: untouched; 25 h: refreshed, report raritiesRefreshed = 1
public function testStoringTermsComputesTheirRaritiesAtOnce(): void   // via the refresh path: getRaritiesRefreshedAt() is now
```

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Stream`.
- [ ] **Step 3: Implement the models, `EntryStemming` with its factory, `findRecentEntries` and `findWithStaleRarities`.**
- [ ] **Step 4: Implement `InterestTermRarity`.**
  - It walks `findRecentEntries` in chunks.
  - It counts the entries per language key, and counts the document frequency only for the stems the set holds.
  - It then calls `recordRarity()` on each term with `log((max(N, FLOOR) + 2) / (df + 1))`, calls `recordRaritiesRefreshed(now)` on the set, and flushes.
- [ ] **Step 5: Wire it in.**
  - `InterestTermRefresh` calls it after `replaceTerms` on the `Stored` path.
  - `InterestTermSweep` refreshes each stale set after the due term calls and before the deadline, and counts the refreshes in `raritiesRefreshed`.
- [ ] **Step 6: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 7: Commit** with `feat(#2582): weigh interest terms by their rarity over 30 days`.

### Task 6: The membership table and the matching sweep

**Files:**
- Create: `backend/src/Entity/ForYouStreamEntry.php`, `backend/src/Repository/ForYouStreamMembershipRepository.php`
- Create: `…/Stream/ForYouStreamMembershipWriter/ForYouStreamMembershipWriterInterface.php`
- Create: `…/Stream/ForYouStreamScorer.php`, `…/Stream/ForYouStreamChunkMatcher.php`, `…/Stream/ForYouStreamSweep.php`
- Create: `…/Stream/Model/StreamScoreModel.php`, `…/Stream/Model/ForYouStreamMatchModel.php`, `…/Stream/Model/StreamCandidateModel.php`, `…/Stream/Model/ForYouStreamSweepReportModel.php`, `…/Stream/Pass/StreamSweepPass.php`
- Modify: `backend/src/Repository/ForYouStreamSourceRepository.php` (add `findCandidates`), `backend/src/Repository/InterestTermSetRepository.php` (add `findBelowMark`, `advanceMarks`)
- Create: `backend/migrations/Version20261127140000.php`
- Test: `…/Stream/ForYouStreamScorerTest.php`, `…/Stream/ForYouStreamSweepTest.php`, `backend/tests/Repository/ForYouStreamMembershipRepositoryTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 5, `EntryMembershipSweepRepository::idsBetween(int, int, int)`, `SweepBudgetModel` (from `Library/Search/Membership/Model`), `DatabasePlatform::insertIgnoreClause()` and `BulkInsert`.
- Produces:
  - The `ForYouStreamEntry` entity, table `for_you_stream_entry`:
    - columns: PK (`user_id`, `entry_id`), both FKs cascading; `matched_at`; `score` FLOAT; `matched_stems` VARCHAR(255);
    - index `idx_for_you_stream_entry_entry (entry_id)`.
  - `ForYouStreamMembershipWriterInterface::insertMissing(list<ForYouStreamMatchModel> $matches, \DateTimeImmutable $matchedAt): int`, which returns the rows added. `ForYouStreamMembershipRepository` implements it.
  - `StreamScoreModel(float $score, list<string> $matchedStems)`.
  - `ForYouStreamMatchModel(int $userId, int $entryId, StreamScoreModel $score)`, with `formatMatchedStems(): string`: the stems space-joined in contribution order and cut at 255 characters.
  - `StreamCandidateModel(StreamEntryModel $entry, list<int> $userIds)`.
  - `ForYouStreamSourceRepository::findCandidates(list<int> $userIds, non-empty-list<int> $entryIds, \DateTimeImmutable $publishedSince): list<StreamCandidateModel>`: each entry once, with the users among `$userIds` whose For-you-included subscriptions carry its feed.
  - `ForYouStreamScorer::findMatch(InterestTermsModel, EntryStemsModel): ?StreamScoreModel`, with `MIN_SCORE = 1.5`.
  - `ForYouStreamChunkMatcher::matchChunk(StreamSweepPass, non-empty-list<int> $chunk): list<ForYouStreamMatchModel>`.
  - `ForYouStreamSweep::sweep(SweepBudgetModel): ForYouStreamSweepReportModel`, with `CHUNK = 500` and `PUBLISHED_WINDOW_HOURS = 48`.
  - `ForYouStreamSweepReportModel(int $usersSwept, int $entriesScanned, int $matchesInserted, bool $caughtUp)`, with `none()` and `toLogContext()`.
  - `InterestTermSetRepository::findBelowMark(int $ceiling, \DateTimeImmutable $now): list<InterestTermSet>` and `advanceMarks(list<int> $userIds, int $entryId): void`.

The walk follows `SavedSearchMembershipSweep`:
- It groups the sets by mark, lowest first. A group walks up to the next group's mark and then joins it.
- Each chunk's inserts and its mark advance share one `wrapInTransaction`.
- A failing chunk is logged and stops the run short. The next run retries it.
- `StreamSweepPass` holds the group's `array<int, InterestTermsModel>`, one `EntryStemming`, `publishedSince = now − 48 h` and the deadline, so no value travels four hops (phptramp).

- [ ] **Step 1: Write the failing tests.**

```php
// ForYouStreamScorerTest (no database; InterestTermsModel::fromStrengths)
public function testTheScoreSumsStrengthTimesPositionOverMatchedStems(): void
{
    $terms = InterestTermsModel::fromStrengths(['en' => ['solar' => 2.0, 'grid' => 1.0]]);
    $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0, 'grid' => 0.5, 'other' => 1.0]);
    $score = $this->scorer->findMatch($terms, $stems);
    self::assertEqualsWithDelta(2.5, $score?->score, 1e-9);
    self::assertSame(['solar', 'grid'], $score?->matchedStems);
}
public function testAScoreBelowTheThresholdIsNoMatch(): void          // 1.49 → null; 1.5 → a match
public function testStemsAreLookedUpInTheEntrysLanguageOnly(): void   // de terms never match an en entry

// ForYouStreamSweepTest (DbTestCase, TickingClock/MockClock)
public function testAMatchingEntryIsStoredWithItsScoreAndStemsAndTheMarkAdvances(): void
public function testAnEntryBelowTheThresholdIsPassedButNotStored(): void
public function testAnEntryInAFeedOutsideForYouIsNotACandidate(): void
public function testAnEntryInAnUnsubscribedFeedIsNotACandidate(): void
public function testAnEntryPublishedMoreThan48HoursBeforeTheSweepIsSkippedAndPassed(): void
public function testAnEntryYoungerThanTheSettleDelayWaitsForTheNextRun(): void
public function testAnEntryWithoutALanguageMatchesThroughTheCutForm(): void
public function testTwoUsersAtOneMarkShareOneStemmingOfEachEntry(): void // EntryStemmingFactory counted: create() once per run
public function testAUserBehindCatchesUpToTheOthersMarkThenJoinsThem(): void
public function testTheBudgetStopsTheRunShort(): void                // caughtUp false, mark at the last finished chunk
public function testAFailingChunkStopsTheRunAndLeavesTheMark(): void
public function testAnEntryAlreadyStoredIsNotInsertedTwice(): void    // a rerun over one chunk: matchesInserted 0

// ForYouStreamMembershipRepositoryTest
public function testInsertMissingSkipsStoredPairsOnBothPlatforms(): void
public function testDeletingTheEntryDeletesItsRows(): void           // retention: rows go with their entry
```

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Curation/Recommendation/Stream tests/Repository/ForYouStreamMembershipRepositoryTest.php`.
- [ ] **Step 3: Implement the entity, the migration and the writer repository** (INSERT IGNORE / OR IGNORE, as `SavedSearchEntryMembershipRepository` does).
- [ ] **Step 4: Implement the models, the scorer, `findCandidates`, `findBelowMark`, `advanceMarks`, the chunk matcher, the pass and the sweep.**
- [ ] **Step 5: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`. Verify the migration on both platforms.
- [ ] **Step 6: Commit** with `feat(#2582): match new entries into the For you stream`.

### Task 7: Scheduling the matching sweep

**Files:**
- Create: `backend/src/Service/Operations/Worker/Message/SweepForYouStream.php`, `…/Worker/Handler/SweepForYouStreamHandler.php`
- Modify: `backend/src/Service/Operations/Worker/WorkerSchedule.php`
- Create: `backend/src/Service/Operations/Maintenance/MaintenanceSweep/ForYouStreamTickSweep.php`, `backend/src/Http/SweepReportJson/ForYouStreamSweepJson.php`
- Test: `…/tests/Service/Operations/Worker/SweepForYouStreamHandlerTest.php`; update `WorkerScheduleWiringTest`, `MaintenanceSweepWiringTest`, `MaintenanceTickJsonTest` and `MaintenanceControllerTest`

**Interfaces:**
- Consumes: `ForYouStreamSweep::sweep(SweepBudgetModel)` and `ForYouStreamSweepReportModel`.
- Produces:
  - The handler runs `BUDGET_SECONDS = 60` and logs `'Worker For you stream sweep finished.'`.
  - The schedule appends `every('1 minute', new SweepForYouStream())` as the new last entry, after `RefreshDueInterestTerms`.
  - The tick sweep is `#[AsTaggedItem(index: 'forYouStream', priority: 25)]` with `BUDGET_SECONDS = 10` through `SweepBudgetModel::remainingUntil`.
  - The JSON is `{usersSwept, entriesScanned, matchesInserted, caughtUp}`.

- [ ] **Step 1: Write the failing tests.**
  - `testTheHandlerSweepsWithASixtySecondBudgetAndLogsTheReport`.
  - The wiring tests expect `SweepForYouStream::class` last, and `'forYouStream'` between `savedSearchMemberships` (30) and `privateFeedSync` (20).
  - The JSON test checks the four keys.
- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Service/Operations tests/Http/MaintenanceTickJsonTest.php tests/Controller/MaintenanceControllerTest.php`.
- [ ] **Step 3: Implement the message, the handler, the schedule entry, the tick sweep and the JSON mapper.**
- [ ] **Step 4: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 5: Commit** with `feat(#2582): sweep the For you stream every minute and on the maintenance tick`.

### Task 8: The read API and mark-read

**Files:**
- Create: `backend/src/Repository/ForYouStreamEntryRepository.php`
- Create: `backend/src/Controller/Api/ForYouStreamEntriesController.php`, `backend/src/Dto/Entry/MarkForYouStreamReadRequest.php`
- Create: `backend/src/Service/Library/Reading/ForYouStreamMarkReadService.php`
- Test: `backend/tests/Controller/Api/ForYouStreamEntriesControllerTest.php`, `backend/tests/Service/Library/Reading/ForYouStreamMarkReadServiceTest.php`

**Interfaces:**
- Consumes: `EntryProjection`, `EntryListRowHydrator`, `DuplicateCollapseDql`/`CollapseScope`, `InclusionScopeDql`, `EntryListRowEnricher::enrich(list<EntryListRow>, int)`, `EntryPage::from(array $rows, int $limit, EntryListSort::PublishedDate)`, `EntryPageParameters::toListPage()` and `EntryReadMarker::markEntriesRead(int, list<int>)`.
- Produces:
  - `ForYouStreamEntryRepository` (`ServiceEntityRepository<Entry>`):
    - `listMembers(int $userId, EntryListPage $page): list<EntryListRow>`;
    - `findUnreadMemberIdsUpTo(int $userId, \DateTimeImmutable $until): list<int>`.
    - Membership is an EXISTS on `ForYouStreamEntry` for `:user`, plus `InclusionScopeDql::restrict($aliases->subscription, InclusionSurface::ForYou)`. It is applied on the primary alias and inside the collapse subquery alike, through a private `applyMembership(QueryBuilder, EntryAliases)`, as in `SavedSearchEntryRepository`.
  - `GET /api/entries/for-you-stream` (`api_entries_for_you_stream`), which takes `EntryPageParameters` and answers `{entries, nextCursor}`.
  - `POST /api/entries/for-you-stream/mark-read` (`api_entries_for_you_stream_mark_read`), with body `{until}`, which answers `Response::HTTP_NO_CONTENT`.
  - `ForYouStreamMarkReadService::mark(User $user, \DateTimeImmutable $until): void`.

- [ ] **Step 1: Write the failing tests.** These are `ApiTestCase` tests that seed `for_you_stream_entry` rows directly.

```php
public function testTheListIsTheCallersMatchesNewestFirst(): void
{
    $older = $this->member('2026-11-27T08:00:00');
    $newer = $this->member('2026-11-27T09:00:00');
    $this->otherUsersMember();
    $response = $this->getJson('/api/entries/for-you-stream');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([$newer->requireId(), $older->requireId()], array_column($this->json($response)['entries'], 'id'));
}
public function testTheUnreadSwitchAndTheOldestOrderApply(): void
public function testTheCursorPagesThroughTheMatches(): void
public function testAFeedSwitchedOutOfForYouHidesItsMatches(): void
public function testAnUnsubscribedFeedsMatchesAreHidden(): void
public function testWithoutABearerTokenTheListIsRefused(): void       // HTTP_UNAUTHORIZED, application/problem+json
public function testMarkReadMarksMatchesUpToUntilAndNothingElse(): void
// A member before until is read. A member after until stays unread, and so does a non-member in the same feed. The response is HTTP_NO_CONTENT.
public function testMarkReadWithoutUntilIsUnprocessable(): void       // HTTP_UNPROCESSABLE_ENTITY, problem+json
```

`ForYouStreamMarkReadServiceTest::testOnlyTheCallersMatchesAreMarked` checks per-entry state: the other user's identical member stays unread.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Controller/Api/ForYouStreamEntriesControllerTest.php tests/Service/Library/Reading/ForYouStreamMarkReadServiceTest.php`.
- [ ] **Step 3: Implement the repository, the DTO, the mark-read service and the controller.** The controller reads the request, calls the repository, the enricher and `EntryPage`, and returns. It has no private methods (`ThinControllerRule`).
- [ ] **Step 4: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 5: Run the §6 checklist (`docs/architecture.md`) against both endpoints and record the result in the commit body.**
  - Bearer auth only: the `/api` firewall.
  - Stateless: no session or cookie.
  - JSON in and out, with `application/problem+json` on 401 and 422 (asserted above).
  - No `Origin`/`Referer`/CSRF input.
  - No redirect handoff.
  - No user-facing link in the response.
- [ ] **Step 6: Commit** with `feat(#2582): list the For you stream and mark it read`.

### Task 9: Sidebar totals

**Files:**
- Create: `backend/src/Service/Library/Subscription/Model/ForYouStreamTallyModel.php`
- Modify: `backend/src/Service/Library/Subscription/Model/SubscriptionTalliesModel.php`, `…/Subscription/SubscriptionTallyReader.php`, `backend/src/Repository/ForYouStreamEntryRepository.php` (add counts), `backend/src/Http/SubscriptionCountsJson.php`
- Test: `backend/tests/Http/SubscriptionCountsJsonTest.php`, `backend/tests/Service/Library/Subscription/SubscriptionTallyReaderTest.php`, `backend/tests/Controller/Api/SubscriptionControllerTest.php` (extend)

**Interfaces:**
- Consumes: `InterestTermSetRepository::hasForUser(int)` and Task 8's membership scope.
- Produces:
  - `ForYouStreamTallyModel(bool $available, int $unreadCount, int $entryCount)`, with `unavailable()`.
  - `SubscriptionTalliesModel` gains `public ForYouStreamTallyModel $forYouStream` as its last constructor parameter.
  - `ForYouStreamEntryRepository::countUnreadMembers(int $userId): int` and `countMembers(int $userId): int`. Both use the same scope and collapse as the list, so a badge never counts a row the list hides.
  - `SubscriptionCountsJson::surfaceTotals()` adds `'forYouStream' => ['available' => bool, 'unreadCount' => int, 'entryCount' => int]`, with the docblock shape updated in both methods. `SubscriptionJson` inherits it.

- [ ] **Step 1: Write the failing tests.**

```php
public function testAUserWithoutATermSetHasNoStream(): void           // available false, counts 0, no count query run
public function testTheStreamCountsItsUnreadAndAllMatches(): void
public function testAFeedSwitchedOutOfForYouLeavesTheCounts(): void
public function testTheCountsPayloadCarriesTheStreamTally(): void     // GET /api/subscriptions/counts → forYouStream {available: true, unreadCount: 2, entryCount: 3}
```

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit tests/Http/SubscriptionCountsJsonTest.php tests/Service/Library/Subscription tests/Controller/Api/SubscriptionControllerTest.php`.
- [ ] **Step 3: Implement the model, the counts, the reader and the JSON.** Fix every other construction site of `SubscriptionTalliesModel` in `src` and `tests`; `stan` lists them.
- [ ] **Step 4: Run the tests and see them pass.** Then run `composer cs -- <changed files>` and `composer stan`.
- [ ] **Step 5: Commit** with `feat(#2582): count the For you stream in the sidebar totals`.

### Task 10: The digest section

Dropped (Lars, 2026-11-27): the stream stays out of the email digest. It was built as a8bc10a0e and reverted.

### Task 11: Frontend — the stream list and its sidebar row

**Files:**
- Modify: `frontend/src/app/reader/sidebar-totals.ts`, `…/reader/state/subscriptions.store.ts`, `…/reader/models.ts`, `…/reader/query/query.ts`, `…/reader/query/entry-query.ts`, `…/reader/query/mark-read-target.ts`, `…/reader/reader-api.ts`, `…/reader/shell/mark-read-actions.service.ts`, `…/reader/shell/list-heading.service.ts`, `…/reader/list/list-header/list-header.component.ts`, `…/reader/list/list-empty-state/list-empty-state.component.html`, `…/reader/shell/sidebar/sidebar-views.component.{ts,html}`, `…/reader/shell/sidebar/sidebar.component.{ts,html}`, `…/reader/shell/reader-shell.component.html`, `frontend/public/i18n/{de,en,fr,es}.json`
- Test: `query/query.spec.ts`, `query/entry-query.spec.ts`, `query/mark-read-target.spec.ts`, `reader-api.spec.ts`, `state/subscriptions.store.spec.ts`, `shell/sidebar/sidebar.component.spec.ts`, `shell/list-heading.service.spec.ts` (extend each)

**Interfaces:**
- Consumes: Task 8's endpoints and Task 9's `forYouStream` tally.
- Produces:
  - `ForYouStreamTallyDto { available: boolean; unreadCount: number; entryCount: number }` and `NO_FOR_YOU_STREAM_TALLY`, plus `SidebarTotalsDto.forYouStream`.
  - `SubscriptionsStore.forYouStream`, a signal set wherever `trendingUnopened` is set, reset with it.
  - `SelectionFields['for-you-stream']: FilteredList`, with `'for-you-stream'` added to `FILTERED_VIEWS`, so `?view=for-you-stream` parses.
  - `EntryView` gains `'for-you-stream'`.
  - `ReaderApi.markForYouStreamRead(until: string): Observable<void>`.
  - `MarkReadTarget` gains `{ scope: 'for-you-stream' }`.
  - The list header icon is `stream` and the title key is `reader.forYouStream`. The empty state uses `reader.forYouStreamEmpty`.

The UI strings:

| key | en | de | fr | es |
|---|---|---|---|---|
| `reader.forYouStream` | For you stream | Für-dich-Stream | Flux Pour vous | Flujo Para ti |
| `reader.forYouStreamEmpty` | Nothing has matched your interests yet. New posts that fit land here as they arrive. | Noch passt nichts zu Ihren Interessen. Neue passende Beiträge landen hier, sobald sie eintreffen. | Rien ne correspond encore à vos centres d'intérêt. Les nouveaux articles qui vous correspondent arrivent ici dès leur publication. | Aún no hay nada que encaje con tus intereses. Las nuevas publicaciones que encajen llegarán aquí en cuanto aparezcan. |

- [ ] **Step 1: Write the failing specs.**

```ts
it('parses ?view=for-you-stream as the stream with the unread switch off', () => {
  expect(selectionFromParams(convertToParamMap({ view: 'for-you-stream' }), CONTEXT).selection)
    .toEqual({ kind: 'for-you-stream', unread: false });
});
it('reads the stream from /api/entries/for-you-stream with the unread flag', () => {
  api.list({ view: 'for-you-stream', unread: true }).subscribe();
  const request = http.expectOne((req) => req.url.endsWith('/api/entries/for-you-stream'));
  expect(request.request.params.get('unread')).toBe('true');
});
it('marks the stream read with until in the body', () => {
  api.markForYouStreamRead('2026-11-27T10:00:00Z').subscribe();
  expect(http.expectOne(`${BASE}/api/entries/for-you-stream/mark-read`).request.body)
    .toEqual({ until: '2026-11-27T10:00:00Z' });
});
it('targets the stream for mark all read', () => {
  expect(markReadTarget({ kind: 'for-you-stream', unread: false })).toEqual({ scope: 'for-you-stream' });
});
it('hides the stream row until the account has terms, then shows its unread count', () => { /* sidebar spec */ });
it('takes the stream tally from a counts reload', () => { /* store spec: forYouStream() equals the response's */ });
it('titles and counts the stream list', () => { /* list-heading: title 'For you stream', count by the unread switch */ });
```

- [ ] **Step 2: Run the specs and see them fail**, using the worktree jest command from Global Constraints on the seven spec files.
- [ ] **Step 3: Implement the DTO and the store, then the `Selection` kind.**
  - Every `BySelectionKind` table fails to compile until it has an arm: `query.ts` IDENTITY_KEY, `entry-query.ts`, `mark-read-target.ts` and `list-heading.service.ts` (lastRefreshed `feedFetchedAt`, title, count `bySwitch(selection, forYouStream.unreadCount, forYouStream.entryCount)`). Then the list-header icon map, the API path, the mark-read switch and the empty state.
  - `hasListOrder`, `offersMarkAboveRead` and `listOrderKey` already treat the kind like `saved-searches`. `isSingleStreamView` and `canScopedRefresh` stay false for it.
- [ ] **Step 4: Add the sidebar row.**
  - It goes directly after For you in `sidebar-views.component.html`, under `@if (forYouStream().available)`.
  - It uses class `nav`, icon `stream` and `selectionQueryParams({ view: 'for-you-stream' })`, with a count shown when it is above 0.
  - The input chain is `reader-shell → sidebar → sidebar-views`. Add the i18n keys in all four files.
- [ ] **Step 5: Run the specs and see them pass.**
- [ ] **Step 6: Commit** with `feat(#2582): the For you stream list and its sidebar row`.

### Task 12: Frontend — the digest toggle

Dropped with Task 10.

---

## After the last task

- Run the final review, then `/simplify`, then the full gate set from Global Constraints, once.
- Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) after a local run of both sweeps.
- The PR body says `Closes #2582` and lists every ruling above, so Lars can reject one by number.
