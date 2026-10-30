# SQLite search folds non-ASCII case — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On SQLite, the database search (list search and saved-search matching) ignores case for non-ASCII letters too, so "ärger" finds "Ärger", without slowing ASCII searches.

**Architecture:** A new DQL function `FOLD_CASE(expr)` renders as a PDO-registered `mb_strtolower` UDF on SQLite and as the bare expression on MySQL, whose `utf8mb4_0900_ai_ci` columns already ignore case. `SearchTermsPredicateBuilder` wraps the haystack in `FOLD_CASE` only for a term with non-ASCII characters, binds the lower-cased term, and puts a native `LIKE` on an ASCII "anchor" of the term in front, so the PHP UDF only sees rows that already contain the anchor.

**Tech Stack:** PHP 8.4, Doctrine ORM 3 DQL functions, PDO SQLite `sqliteCreateFunction`, PHPUnit 12.

**Spec:** GitHub issue #1920.

## Global Constraints

- ASCII terms keep exactly one native `LIKE` per column in substring mode — no UDF, no extra predicate.
- MySQL never sees `FOLD_CASE`; its SQL stays native.
- At most two bound parameters per term (the saved-search matcher's chunk size of 20 assumes it, `DatabaseSavedSearchMatcher::SEARCHES_PER_STATEMENT`).
- `CaseFolding::fold()` is the one rule for the term (PHP) and the haystack (UDF), like `WordBoundaries::normalize()`.
- Accent-insensitivity on SQLite is out of scope; tests never ask "Ubung" to find "Übung".
- House style per CLAUDE.md: `final readonly`, no comments beyond a genuinely non-obvious invariant, commits as `fix(#1920): …`.

## File Structure

| File | Role |
|---|---|
| Create `backend/src/Doctrine/CaseFolding.php` | The folding rule and the "does this term need it" test |
| Create `backend/src/Doctrine/FoldCaseFunction.php` | DQL `FOLD_CASE`, platform-specific rendering |
| Modify `backend/src/Doctrine/SqliteConnectionSetupDriver.php` | Registers the `FOLD_CASE` UDF next to `NORMALIZE_WORD_BOUNDARIES` |
| Modify `backend/config/packages/doctrine.yaml` | Registers the DQL function |
| Modify `backend/src/Service/Search/Support/LikePattern.php` | `anchor()`: the stretch of a term native `LIKE` can prefilter on |
| Modify `backend/src/Repository/SearchTermsPredicateBuilder.php` | Folded haystack + anchor for non-ASCII terms |
| Tests | `tests/Doctrine/CaseFoldingTest.php`, `FoldCaseOnSqliteTest.php`, `FoldCaseRenderingTest.php`, `tests/Service/Search/Support/LikePatternTest.php`, `tests/Repository/EntrySearchTest.php`, `tests/Repository/DatabaseSavedSearchMatcherTest.php` |

---

### Task 1: `CaseFolding` and the `FOLD_CASE` function

**Files:**
- Create: `backend/src/Doctrine/CaseFolding.php`, `backend/src/Doctrine/FoldCaseFunction.php`
- Modify: `backend/src/Doctrine/SqliteConnectionSetupDriver.php`, `backend/config/packages/doctrine.yaml`
- Test: `backend/tests/Doctrine/CaseFoldingTest.php`, `FoldCaseOnSqliteTest.php`, `FoldCaseRenderingTest.php`

**Interfaces:**
- Produces: `CaseFolding::fold(string): string`, `CaseFolding::isNeededFor(string $term): bool`, `FoldCaseFunction::NAME = 'FOLD_CASE'`.

- [ ] **Step 1: Write the failing tests**

`tests/Doctrine/CaseFoldingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CaseFolding;
use PHPUnit\Framework\TestCase;

final class CaseFoldingTest extends TestCase
{
    public function testLowerCasesLettersBeyondAscii(): void
    {
        self::assertSame('ärger über αθηνα', CaseFolding::fold('ÄRGER Über ΑΘΗΝΑ'));
    }

    public function testAnAsciiTermNeedsNoFolding(): void
    {
        self::assertFalse(CaseFolding::isNeededFor('Berlin 2026!'));
    }

    public function testATermWithANonAsciiCharacterNeedsFolding(): void
    {
        self::assertTrue(CaseFolding::isNeededFor('Ärger'));
        self::assertTrue(CaseFolding::isNeededFor('E–Mail'));
    }
}
```

`tests/Doctrine/FoldCaseOnSqliteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CaseFolding;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** Read from the application's connection, so it proves SqliteConnectionSetupDriver registered the function. */
final class FoldCaseOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The native function is SQLite-only; this leg runs on another platform.');
        }
    }

    public function testTheConnectionFoldsLikePhpDoes(): void
    {
        $text = 'ÄRGER im Büro, ΑΘΗΝΑ';

        self::assertSame(
            CaseFolding::fold($text),
            $this->entityManager->getConnection()->fetchOne('SELECT FOLD_CASE(?)', [$text]),
        );
    }

    public function testANullHaystackStaysNull(): void
    {
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT FOLD_CASE(NULL)'));
    }
}
```

`tests/Doctrine/FoldCaseRenderingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Entry;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class FoldCaseRenderingTest extends DbTestCase
{
    public function testSqliteCallsTheNativeFunctionAndMysqlLeavesTheColumnToItsCollation(): void
    {
        $sql = $this->entityManager
            ->createQuery(\sprintf('SELECT FOLD_CASE(e.headline.title) FROM %s e', Entry::class))
            ->getSQL();
        self::assertIsString($sql);

        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertStringContainsString('FOLD_CASE(', $sql);

            return;
        }

        self::assertStringNotContainsString('FOLD_CASE', $sql);
        self::assertStringNotContainsString('LOWER(', $sql);
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Doctrine/CaseFoldingTest.php tests/Doctrine/FoldCaseOnSqliteTest.php tests/Doctrine/FoldCaseRenderingTest.php`
Expected: FAIL — class `App\Doctrine\CaseFolding` not found, `FOLD_CASE` unknown.

- [ ] **Step 3: Implement**

`src/Doctrine/CaseFolding.php`:

```php
<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Lower-casing for the search, beyond the ASCII letters SQLite's LIKE folds on its own. The term (here) and the
 * haystack (FoldCaseFunction's SQLite UDF) must fold identically.
 */
final readonly class CaseFolding
{
    public static function fold(string $value): string
    {
        return mb_strtolower($value);
    }

    public static function isNeededFor(string $term): bool
    {
        return preg_match('/[^\x00-\x7F]/', $term) === 1;
    }
}
```

`src/Doctrine/FoldCaseFunction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * FOLD_CASE(expr) lower-cases a haystack for a LIKE against a CaseFolding::fold()ed term. MySQL gets the bare
 * expression: the searched columns are utf8mb4_0900_ai_ci, so LOWER() would only cost a pass over every row.
 */
final class FoldCaseFunction extends FunctionNode
{
    public const string NAME = 'FOLD_CASE';

    private Node $stringExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->stringExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $sql = $sqlWalker->walkStringPrimary($this->stringExpression);
        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            return \sprintf('%s(%s)', self::NAME, $sql);
        }

        return $sql;
    }
}
```

`config/packages/doctrine.yaml`, under the existing `NORMALIZE_WORD_BOUNDARIES` line (same `string_functions` map):

```yaml
                FOLD_CASE: App\Doctrine\FoldCaseFunction
```

`src/Doctrine/SqliteConnectionSetupDriver.php`: in `connect()`, replace `$this->registerWordBoundariesFunction($connection);` with `$this->registerSearchFunctions($connection);`, and replace the `registerWordBoundariesFunction` method with:

```php
    /**
     * One native call each on SQLite: the nested-REPLACE rendering is 26 levels deep, and SQLite before 3.46
     * overflows its parser stack on two ORed whole-word searches (#584). WordBoundaries and CaseFolding stay the rule.
     */
    private function registerSearchFunctions(Connection $connection): void
    {
        $nativeConnection = $connection->getNativeConnection();
        if (!$nativeConnection instanceof \PDO) {
            return;
        }

        $nativeConnection->sqliteCreateFunction(
            NormalizeWordBoundariesFunction::NAME,
            static fn (?string $value): ?string => $value === null ? null : WordBoundaries::normalize($value),
            1,
        );
        $nativeConnection->sqliteCreateFunction(
            FoldCaseFunction::NAME,
            static fn (?string $value): ?string => $value === null ? null : CaseFolding::fold($value),
            1,
        );
    }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `bin/console cache:clear --env=test && php bin/phpunit tests/Doctrine`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Doctrine config/packages/doctrine.yaml tests/Doctrine
git commit -m "fix(#1920): a FOLD_CASE function that lower-cases on SQLite"
```

### Task 2: `LikePattern::anchor()`

**Files:**
- Modify: `backend/src/Service/Search/Support/LikePattern.php`
- Test: `backend/tests/Service/Search/Support/LikePatternTest.php`

**Interfaces:**
- Consumes: `CaseFolding::isNeededFor()`, `WordBoundaries::areIn()`.
- Produces: `LikePattern::anchor(string $term): ?string` — raw text (not a pattern), null when the term has none.

- [ ] **Step 1: Write the failing tests** (append to `LikePatternTest`)

```php
    public function testAnAsciiTermWithoutBoundariesIsItsOwnAnchor(): void
    {
        self::assertSame('C++', LikePattern::anchor('C++'));
    }

    public function testAnAsciiTermWithABoundaryIsAnchoredOnItsLongestAlphanumericStretch(): void
    {
        self::assertSame('Mail', LikePattern::anchor('E-Mail'));
    }

    public function testANonAsciiTermIsAnchoredOnItsLongestAsciiStretch(): void
    {
        self::assertSame('bersee', LikePattern::anchor('Übersee'));
        self::assertSame('Fluss', LikePattern::anchor('über den Fluss'));
    }

    public function testATermWithoutAsciiLettersHasNoAnchor(): void
    {
        self::assertNull(LikePattern::anchor('αθηνα'));
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Search/Support/LikePatternTest.php`
Expected: FAIL — undefined method `anchor`.

- [ ] **Step 3: Implement** (in `LikePattern`, after `wholeWord()`; add `use App\Doctrine\CaseFolding;`)

```php
    /**
     * A stretch native LIKE finds, ignoring case, in every row the full match accepts on any engine, so it can reject
     * rows before a folded or normalized haystack is computed. Alphanumerics alone survive both CaseFolding and
     * WordBoundaries unchanged.
     */
    public static function anchor(string $term): ?string
    {
        if (!CaseFolding::isNeededFor($term) && !WordBoundaries::areIn($term)) {
            return $term;
        }

        preg_match_all('/[A-Za-z0-9]+/', $term, $stretches);
        $longest = array_reduce(
            $stretches[0],
            static fn (string $longest, string $stretch): string => \strlen($stretch) > \strlen($longest) ? $stretch : $longest,
            '',
        );

        return $longest === '' ? null : $longest;
    }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Service/Search/Support/LikePatternTest.php` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Search/Support/LikePattern.php tests/Service/Search/Support/LikePatternTest.php
git commit -m "fix(#1920): an ASCII anchor for a search term"
```

### Task 3: Fold the haystack for non-ASCII terms

**Files:**
- Modify: `backend/src/Repository/SearchTermsPredicateBuilder.php`
- Test: `backend/tests/Repository/EntrySearchTest.php`, `backend/tests/Repository/DatabaseSavedSearchMatcherTest.php`

**Interfaces:**
- Consumes: `CaseFolding::fold/isNeededFor`, `FoldCaseFunction::NAME`, `LikePattern::anchor/containing/wholeWord`.

- [ ] **Step 1: Write the failing tests**

`EntrySearchTest`: replace the class docblock with

```php
/**
 * The LIKE search over title, summary and transcript text. Case folds on both engines, accents on MySQL alone, so no
 * test asks "Ubung" to find "Übung": that would pass in production and fail here.
 */
```

and add after `testIgnoresCaseForAnAsciiTerm()`:

```php
    public function testIgnoresCaseForAnUmlautTerm(): void
    {
        $this->entry('title', 'ÄRGER im Büro');
        $this->entry('summary', 'Untitled', 'Viel Ärger heute');
        $this->entry('miss', 'Nothing here');

        self::assertEqualsCanonicalizing(['title', 'summary'], $this->search('ärger'));
    }

    public function testIgnoresCaseForATermWithoutAsciiLetters(): void
    {
        $this->entry('hit', 'ΑΘΗΝΑ heute');
        $this->entry('miss', 'Berlin heute');

        self::assertSame(['hit'], $this->search('αθηνα'));
    }

    public function testIgnoresCaseForAnUmlautWholeWord(): void
    {
        $this->entry('hit', 'Viel Ärger heute');
        $this->entry('miss', 'Ärgernis heute');

        self::assertSame(['hit'], $this->search('ärger '));
    }

    public function testIgnoresCaseForAnUmlautInTheTranscript(): void
    {
        $this->transcribed($this->entry('spoken', 'Episode one', 'Nothing relevant'), 'Ann: Über den Fluss.');

        self::assertSame(['spoken'], $this->search('über den '));
    }
```

`DatabaseSavedSearchMatcherTest`: replace the docblock `/** ASCII terms only: SQLite's LIKE folds ASCII case alone. */` with `/** Case folds on both engines, accents on MySQL alone, so no term relies on accent folding. */` and add:

```php
    public function testASavedSearchIgnoresCaseForAnUmlautTerm(): void
    {
        $hit = $this->entry('a', 'ÄRGER im Büro');
        $miss = $this->entry('b', 'Nothing here');

        $matches = $this->matcher()->matchingIds([$this->search(1, 'ärger')], [$hit->requireId(), $miss->requireId()]);

        self::assertSame([1 => [$hit->getId()]], $matches);
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit --filter 'Umlaut|WithoutAsciiLetters' tests/Repository`
Expected: FAIL on SQLite — no rows found.

- [ ] **Step 3: Implement** — replace `substringMatch`, `wholeWordMatch` and `wholeWordColumnMatch` in `SearchTermsPredicateBuilder` (add `use App\Doctrine\CaseFolding;` and `use App\Doctrine\FoldCaseFunction;`; drop the now-unused `use App\Doctrine\WordBoundaries;`):

```php
    /** @return \Closure(string): string */
    private function substringMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $qb->setParameter($parameter, LikePattern::containing(CaseFolding::fold($term)));
        $anchor = CaseFolding::isNeededFor($term) ? $this->bindAnchor($qb, $parameter, $term) : null;
        $haystack = self::haystack($term);

        return static fn (string $column): string => self::anchored($column, $anchor, self::like($haystack($column), $parameter));
    }

    /** @return \Closure(string): string */
    private function wholeWordMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $word = $parameter . 'Word';
        $qb->setParameter($word, LikePattern::wholeWord(CaseFolding::fold($term)));
        $anchor = $this->bindAnchor($qb, $parameter, $term);
        $haystack = self::haystack($term);

        return static fn (string $column): string => self::anchored(
            $column,
            $anchor,
            self::like(\sprintf("CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(%s), ' ')", $haystack($column)), $word),
        );
    }

    private function bindAnchor(QueryBuilder $qb, string $parameter, string $term): ?string
    {
        $anchor = LikePattern::anchor($term);
        if ($anchor === null) {
            return null;
        }

        $qb->setParameter($parameter . 'Anchor', LikePattern::containing($anchor));

        return $parameter . 'Anchor';
    }

    /** @return \Closure(string): string */
    private static function haystack(string $term): \Closure
    {
        if (!CaseFolding::isNeededFor($term)) {
            return static fn (string $column): string => $column;
        }

        return static fn (string $column): string => \sprintf('%s(%s)', FoldCaseFunction::NAME, $column);
    }

    private static function anchored(string $column, ?string $anchor, string $match): string
    {
        if ($anchor === null) {
            return '(' . $match . ')';
        }

        return \sprintf('(%s AND %s)', self::like($column, $anchor), $match);
    }

    private static function like(string $haystack, string $parameter): string
    {
        return \sprintf("%s LIKE :%s ESCAPE '%s'", $haystack, $parameter, LikePattern::ESCAPE_CHARACTER);
    }
```

The old `wholeWordMatch` docblock (the "cheap LIKE … E-Mail must match E–Mail" note) is superseded by `LikePattern::anchor()`'s docblock; delete it.

- [ ] **Step 4: Run the search tests to see them pass**

Run: `php bin/phpunit tests/Repository tests/Doctrine tests/Service/Search`
Expected: PASS, including every existing whole-word and escape test.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/SearchTermsPredicateBuilder.php tests/Repository/EntrySearchTest.php tests/Repository/DatabaseSavedSearchMatcherTest.php
git commit -m "fix(#1920): the database search folds non-ASCII case on SQLite"
```

### Task 4: Gates and both legs

- [ ] `composer check` and `composer md` (from `backend/`), PhpStorm `lint_files` on the changed PHP.
- [ ] `composer test:parallel` natively and `docker compose exec php composer test:parallel` (MySQL), in parallel.
- [ ] `composer infection:diff`.
- [ ] Time one non-ASCII search against an ASCII one on the dev SQLite copy if one exists; otherwise report it unmeasured.
- [ ] `/simplify`, re-run gates, push, open the PR with `Closes #1920`.
