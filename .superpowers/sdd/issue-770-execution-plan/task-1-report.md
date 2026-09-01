# Task 1 report: reader engagement chrome

## Result

The reader now removes leading engagement chrome before sanitization. The pass
removes emoji-only blocks, counters, time-only blocks, and duplicate bylines.
It keeps those shapes after the article starts. It keeps a byline when the feed
entry has no author. It leaves a body without a prose anchor unchanged.

The audit now reports `leading_engagement_chrome` on the same leading shape. It
uses the same prose threshold and link ratio as the cleaner. The audit path now
carries `Entry::author` into extraction.

## Changed files

- `backend/src/Service/Reader/LeadingEngagementRules.php`
- `backend/src/Service/Reader/LeadingEngagementCleaner.php`
- `backend/src/Service/Reader/ReaderBodyCleaner.php`
- `backend/src/Service/Reader/ArticleExtractorInterface.php`
- `backend/src/Service/Reader/ArticleExtractor.php`
- `backend/src/Controller/Api/EntryReaderController.php`
- `backend/src/Service/ReaderAudit/LeadingEngagementMarkers.php`
- `backend/src/Service/ReaderAudit/{AuditSampler,BodyBlock,CleanupMarkers,ExtractedBody,ReaderAuditRunner,SampledEntry}.php`
- Reader, controller, audit, and test-support regression tests.

## TDD evidence

RED, before the new cleaner existed:

```text
php bin/phpunit tests/Service/Reader/LeadingEngagementCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
ERRORS! Tests: 24, Assertions: 0, Errors: 24.
Class "App\Service\Reader\LeadingEngagementCleaner" not found
```

GREEN after the cleaner and cleaner wiring:

```text
php bin/phpunit tests/Service/Reader/LeadingEngagementCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
OK (24 tests, 59 assertions)
```

RED, before the audit marker existed:

```text
php bin/phpunit tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php tests/Service/ReaderAudit/CleanupMarkersTest.php
ERRORS! Tests: 6, Assertions: 0, Errors: 6.
Class "App\Service\ReaderAudit\LeadingEngagementMarkers" not found
```

GREEN for the final focused backend group:

```text
php bin/phpunit tests/Service/Reader/LeadingEngagementCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php tests/Service/Reader/ArticleExtractorTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php tests/Service/ReaderAudit/CleanupMarkersTest.php tests/Service/ReaderAudit/ConfirmedGoodArticlesTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
OK (71 tests, 231 assertions)
```

The author-flow tests were added after the initial extractor contract change, so
there is no independent RED result for those two forwarding tests. I did not
invent one. Their guard-break results below prove that each test observes the
real endpoint or audit forwarding behavior.

## Guard-break evidence

I temporarily changed `LeadingEngagementRules::isCounter()` to return `false`.
The direct cleaner and `ReaderBodyCleaner` wiring group then failed three tests,
including the locale-counter test and the same-pass wiring test. I restored the
rule.

I temporarily made `LeadingEngagementMarkers::detect()` return an empty list.
The issue-fixture audit test and `CleanupMarkers` wiring test both failed. I
restored the marker.

I temporarily removed the author argument at each handoff:

- `EntryReaderControllerTest::testCarriesTheEntryAuthorIntoTheReaderExtraction`
  failed with `null` instead of `Jana Steger`.
- `ReaderAuditRunnerTest::testCarriesTheSampledEntryAuthorIntoTheReaderExtraction`
  failed with `null` instead of `Jana Steger`.

I restored both handoffs.

## Verification

```text
composer cs
PASS

composer check
PASS: PHPCS, PHPStan, phptramp

php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/phpmd <all 13 touched src files> text phpmd.xml.dist
PASS

php bin/phpunit
OK, but there were issues! Tests: 4064, Assertions: 19933, PHPUnit Notices: 44.
```

The Docker MySQL full suite was run twice. Both runs reached 4,064 tests and
failed only `RecommendationRunAdvancerTest::testATickThatStreamsRefreshesItsLock`
on its close clock comparison at line 475. The isolated Docker run passed:

```text
docker compose exec php vendor/bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter testATickThatStreamsRefreshesItsLock
OK (1 test, 7 assertions)
```

This task does not modify recommendation code. The repeat full-suite timing
failure remains a concern.

No migration was added. I did not clear or recreate the dev database. I did not
write raw SQL, deploy, or write to production. PhpStorm inspection tooling was
not available in this task environment. I scanned the active dev log. It has
existing feed network failures and parser warnings, with no Task 1 error.

## Adversarial checks

- Unicode: emoji-only tests include U+FE0F. Emoji in a sentence stays.
- Locale counters: `1.251 Klicks`, `12,345 views`, and `1 251 Reaktionen` all
  remove. The noun list is the issue's closed German and English set.
- Position: `3 Kommentare` after prose stays.
- Byline replacement: `Von` or `By` stays when no entry author exists.
- Empty safety: an emoji/counter/time-only body stays unchanged when it has no
  real prose anchor.
- Audit alignment: `LeadingEngagementRules::PROSE_CHARS` is the only prose
  threshold. Both cleaner and audit use it with the same all-link text ratio.
  The issue fixture reports before cleanup and reports no marker after cleanup.

## Self-review

The cleaner is structural and host-agnostic. It does not use class names or a
publisher allow-list. It runs after duplicate-title removal and before edge
boilerplate trimming. Empty and `hr` remnants are removed only after engagement
chrome was removed, so media-bearing empty-looking blocks stay safe.
