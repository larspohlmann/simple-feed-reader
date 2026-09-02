# #785 Paywalled Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect a paywalled article on the fetched page, carry `paywalled: bool` through `ExtractionResult` and the reader JSON, show a note under the body in the SPA, and add a `paywalled` audit metric.

**Architecture:** Three small value classes under `backend/src/Service/Reader/Paywall/` read two signals — schema.org `isAccessibleForFree` from the raw source (regex over JSON-LD script blocks, because the normaliser strips scripts before the shared parse) and paywall-classed blocks (`paywall`, `subscription-only`, `subscriber(s)-only` class fragments) plus the page text from the shared normalised document, captured before readability mutates it. `PaywallSignals::isPreview($cleanedBody)` decides after cleaning: the declaration wins alone; otherwise a paywall block at or after the last extracted paragraph. `ArticleExtractor` threads the boolean into `ExtractionResult`, `ReaderJson` and the audit emit it, and `ReaderViewComponent` renders a `.reader-note` under the body.

**Tech Stack:** PHP 8.4 `\Dom\HTMLDocument` + `\Dom\XPath`, PHPUnit; Angular 20 signals, Jest, Transloco.

**Spec:** `docs/superpowers/specs/2026-09-02-785-paywalled-preview-design.md`

## Global Constraints

- Branch `fix/785-paywalled-preview`; commit messages `type(#785): summary`, no attribution lines, no `Co-Authored-By`.
- PHP: `declare(strict_types=1)`, `final readonly class`, PSR-12, PHPStan level max, no `@phpstan-ignore` without a comment saying why. **Every touched `src` file must be PHPMD-clean** (`composer md`). No boolean flag parameters that select behaviour. Guard clauses over nesting. Comments: one line, three at most, only for a *why*.
- Run from `backend/`: `composer cs` (autofix `composer cs:fix`), `composer stan` (after `bin/console cache:warmup`), `composer md`, `php bin/phpunit <path>`.
- Frontend: standalone components and signals; Prettier 100 columns; **no CSS added to `frontend/src/app/reader/reader-view/reader-view.component.scss`** (7.97 kB compiled against an 8 kB error budget). Run Jest **inside the Docker frontend container**: `docker compose exec -T frontend npx jest <path>` from the repo root.
- i18n: every new key in both `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`.
- The JSON field is a plain boolean on the existing `ok` response; nothing browser-only (native iOS client stays viable).
- The paywall verdict is a metric on the audit finding, never a marker (it must not raise the score).
- Never run `docker compose down -v`. Never write to the dev database.

---

### Task 1: SchemaOrgAccess — the JSON-LD declaration

**Files:**
- Create: `backend/src/Service/Reader/Paywall/SchemaOrgAccess.php`
- Test: `backend/tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`

**Interfaces:**
- Produces: `SchemaOrgAccess::paywalledIn(string $html): ?bool` — `true` = the page declares a paywall, `false` = declares free access, `null` = declares nothing.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Reader\Paywall\SchemaOrgAccess;
use PHPUnit\Framework\TestCase;

final class SchemaOrgAccessTest extends TestCase
{
    public function testABooleanFalseOnTheArticleNodeDeclaresAPaywall(): void
    {
        $html = $this->page('{"@type":"NewsArticle","headline":"x","isAccessibleForFree":false}');

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testTheStringFalseUnderHasPartDeclaresAPaywall(): void
    {
        // SZ.de and zeit.de write the value as the string "False".
        $html = $this->page(
            '{"@type":"NewsArticle","hasPart":{"@type":"WebPageElement","cssSelector":".article-content",'
            . '"isAccessibleForFree":"False"}}',
        );

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testTheSchemaOrgFalseUrlDeclaresAPaywall(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":"http://schema.org/False"}');

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testATrueWithNoFalseDeclaresFreeAccess(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":true}');

        self::assertFalse(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAFalseInAnyBlockWinsOverATrueInAnother(): void
    {
        $html = $this->page('{"@type":"WebPage","isAccessibleForFree":true}')
            . $this->page('{"@type":"Article","isAccessibleForFree":false}');

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAPageWithoutTheKeyDeclaresNothing(): void
    {
        $html = $this->page('{"@type":"Article","headline":"Free as in beer"}');

        self::assertNull(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAGraphIsWalkedToTheNestedNode(): void
    {
        $html = $this->page('{"@graph":[{"@type":"WebSite"},{"@type":"Article","isAccessibleForFree":false}]}');

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAnUnparseableBlockIsSkippedAndTheNextOneDecides(): void
    {
        $html = $this->page('{not json')
            . $this->page('{"@type":"Article","isAccessibleForFree":false}');

        self::assertTrue(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAnOrdinaryScriptIsNotReadAsJsonLd(): void
    {
        $html = '<script>var a = {"isAccessibleForFree": false};</script>';

        self::assertNull(SchemaOrgAccess::paywalledIn($html));
    }

    public function testAnUnknownStringValueDeclaresNothing(): void
    {
        $html = $this->page('{"@type":"Article","isAccessibleForFree":"maybe"}');

        self::assertNull(SchemaOrgAccess::paywalledIn($html));
    }

    private function page(string $jsonLd): string
    {
        return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body></body></html>';
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`
Expected: errors — class `App\Service\Reader\Paywall\SchemaOrgAccess` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

/**
 * The publisher's own paywall declaration: schema.org `isAccessibleForFree`,
 * the markup Google documents for paywalled content. Read from the raw source
 * because FetchedPageNormalizer strips every <script> before the shared parse.
 */
final readonly class SchemaOrgAccess
{
    private const string JSON_LD_PATTERN = '#<script\b[^>]*application/ld\+json[^>]*>(.*?)</script\s*>#is';
    private const string KEY = 'isAccessibleForFree';

    /** True when the page declares a paywall, false when it declares free access, null when it says nothing. */
    public static function paywalledIn(string $html): ?bool
    {
        $declaresFree = null;
        preg_match_all(self::JSON_LD_PATTERN, $html, $blocks);
        foreach ($blocks[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!\is_array($decoded)) {
                continue;
            }
            foreach (self::declarationsIn($decoded) as $accessibleForFree) {
                if (!$accessibleForFree) {
                    return true;
                }
                $declaresFree = false;
            }
        }

        return $declaresFree;
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<bool> every isAccessibleForFree in the tree, as a boolean
     */
    private static function declarationsIn(array $node): array
    {
        $declarations = [];
        $declared = self::asBoolean($node[self::KEY] ?? null);
        if ($declared !== null) {
            $declarations[] = $declared;
        }
        foreach ($node as $child) {
            if (\is_array($child)) {
                array_push($declarations, ...self::declarationsIn($child));
            }
        }

        return $declarations;
    }

    private static function asBoolean(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (!\is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', 'http://schema.org/true', 'https://schema.org/true' => true,
            'false', 'http://schema.org/false', 'https://schema.org/false' => false,
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`
Expected: 10 tests, all pass.

- [ ] **Step 5: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: all clean. If `composer cs` reports fixable issues, run `composer cs:fix` and re-run.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Reader/Paywall/SchemaOrgAccess.php tests/Service/Reader/Paywall/SchemaOrgAccessTest.php
git commit -m "feat(#785): read the schema.org isAccessibleForFree declaration from the raw page"
```

---

### Task 2: SqueezedText and PaywallBlocks — the DOM signal

**Files:**
- Create: `backend/src/Service/Reader/Paywall/SqueezedText.php`
- Create: `backend/src/Service/Reader/Paywall/PaywallBlocks.php`
- Test: `backend/tests/Service/Reader/Paywall/SqueezedTextTest.php`
- Test: `backend/tests/Service/Reader/Paywall/PaywallBlocksTest.php`

**Interfaces:**
- Produces: `SqueezedText::of(string $text): string` — whitespace runs (including NBSP and newlines) collapsed to one space, trimmed.
- Produces: `PaywallBlocks::textsIn(\Dom\HTMLDocument $document): list<string>` — the squeezed, non-empty text of every element whose class contains `paywall` (any case), outside `aside`/`nav`/`footer`, in document order. A wrapper and its child both appear.
- Consumes: `App\Service\Reader\Media\PageFurniture::holds(\Dom\Element): bool` (exists).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Reader/Paywall/SqueezedTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Reader\Paywall\SqueezedText;
use PHPUnit\Framework\TestCase;

final class SqueezedTextTest extends TestCase
{
    public function testRemovesEveryWhitespaceIncludingNewlinesAndNoBreakSpaces(): void
    {
        self::assertSame('abc', SqueezedText::of("  a \n\t b\u{00A0}\u{00A0}c  "));
    }

    public function testLeavesTextWithoutWhitespaceAlone(): void
    {
        self::assertSame('Wörter–und.Zeichen', SqueezedText::of('Wörter–und.Zeichen'));
    }

    public function testAnAllWhitespaceStringBecomesEmpty(): void
    {
        self::assertSame('', SqueezedText::of(" \n\u{00A0}"));
    }
}
```

`backend/tests/Service/Reader/Paywall/PaywallBlocksTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallBlocks;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class PaywallBlocksTest extends TestCase
{
    private const string SUBSTACK_CTA = "<div class=\"paywall-cta\">\n"
        . "<h2 class=\"paywall-title\">Continue reading this post for free.</h2>\n"
        . "<button>Claim my free post</button>\n</div>";

    public function testCollectsAWrapperAndItsChildInDocumentOrder(): void
    {
        $texts = PaywallBlocks::textsIn($this->document('<article><p>Teaser.</p>' . self::SUBSTACK_CTA . '</article>'));

        self::assertSame(
            ['Continuereadingthispostforfree.Claimmyfreepost', 'Continuereadingthispostforfree.'],
            $texts,
        );
    }

    public function testMatchesTheClassFragmentInAnyCaseAndAnyPosition(): void
    {
        $document = $this->document(
            '<div class="PayWall">Upper.</div><div class="duv-paywall-preview svelte-1">Zeit.</div>',
        );

        self::assertSame(['Upper.', 'Zeit.'], PaywallBlocks::textsIn($document));
    }

    public function testMatchesAGatedRegionNamedSubscriptionOnly(): void
    {
        // jungle.world (Drupal): the body wrapper carries `subscription-only`,
        // the call to action `subscription-only-block`, and no `paywall` anywhere.
        $document = $this->document(
            '<div class="body-wrapper subscription-only"><p>Text.</p>'
            . '<div class="subscription-only-block"><h2>Noch kein Abonnement?</h2></div></div>'
            . '<p class="subscribers-only">Members.</p>',
        );

        self::assertSame(['Text.NochkeinAbonnement?', 'NochkeinAbonnement?', 'Members.'], PaywallBlocks::textsIn($document));
    }

    public function testASubscribeWidgetIsNotAPaywallBlock(): void
    {
        $document = $this->document('<div class="subscribe-widget subscription-form">Subscribe to get new posts.</div>');

        self::assertSame([], PaywallBlocks::textsIn($document));
    }

    public function testSkipsBlocksInsidePageFurniture(): void
    {
        $document = $this->document(
            '<nav><a class="paywall-link" href="/abo">Abo</a></nav>'
            . '<aside class="paywall-teaser">Side.</aside>'
            . '<footer><p class="paywall-info">Foot.</p></footer>'
            . '<main><p class="paywall-cta">Main.</p></main>',
        );

        self::assertSame(['Main.'], PaywallBlocks::textsIn($document));
    }

    public function testSkipsAnEmptyBlock(): void
    {
        $document = $this->document('<div class="paywall-fade"></div><p class="paywall-title">  Title  </p>');

        self::assertSame(['Title'], PaywallBlocks::textsIn($document));
    }

    public function testAPageWithoutPaywallClassesYieldsNothing(): void
    {
        self::assertSame([], PaywallBlocks::textsIn($this->document('<p class="lead">No wall here.</p>')));
    }

    private function document(string $body): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $body . '</body></html>');
        self::assertNotNull($document);

        return $document;
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Paywall/SqueezedTextTest.php tests/Service/Reader/Paywall/PaywallBlocksTest.php`
Expected: errors — classes not found.

- [ ] **Step 3: Write the implementations**

`backend/src/Service/Reader/Paywall/SqueezedText.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

/**
 * Text with every whitespace removed. The page text, a paywall block and the
 * cleaned body are compared as substrings and by position; the source's
 * indentation and the serializer's line breaks must never decide a match.
 */
final readonly class SqueezedText
{
    private const string WHITESPACE = '/[\s\x{00A0}]+/u';

    public static function of(string $text): string
    {
        return (string) preg_replace(self::WHITESPACE, '', $text);
    }
}
```

`backend/src/Service/Reader/Paywall/PaywallBlocks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * The gated region or its call to action, as a publisher names it in the DOM
 * (`paywall-cta`, `duv-paywall-preview`, `subscription-only-block`), by class
 * fragment. Read from the shared normalised document before readability
 * consumes it — the block is exactly what the body cleaners remove.
 */
final readonly class PaywallBlocks
{
    /** The words publishers use for a gated region; `subscribe` alone is a newsletter form, not a wall. */
    private const array CLASS_FRAGMENTS = ['paywall', 'subscription-only', 'subscriber-only', 'subscribers-only'];
    private const string LOWER_CLASS = 'translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    /** @return list<string> the squeezed text of every paywall block outside page furniture, in document order */
    public static function textsIn(HTMLDocument $document): array
    {
        $texts = [];
        foreach ((new XPath($document))->query(self::paywallClassQuery()) as $element) {
            if (!$element instanceof Element || PageFurniture::holds($element)) {
                continue;
            }
            $text = SqueezedText::of((string) $element->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function paywallClassQuery(): string
    {
        $fragments = array_map(
            static fn (string $fragment): string => \sprintf('contains(%s, "%s")', self::LOWER_CLASS, $fragment),
            self::CLASS_FRAGMENTS,
        );

        return '//*[' . implode(' or ', $fragments) . ']';
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Paywall/SqueezedTextTest.php tests/Service/Reader/Paywall/PaywallBlocksTest.php`
Expected: 10 tests pass.

- [ ] **Step 5: Lint**

Run: `composer cs && composer md && composer stan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Reader/Paywall/SqueezedText.php src/Service/Reader/Paywall/PaywallBlocks.php tests/Service/Reader/Paywall/SqueezedTextTest.php tests/Service/Reader/Paywall/PaywallBlocksTest.php
git commit -m "feat(#785): collect paywall-classed blocks from the shared page document"
```

---

### Task 3: PaywallSignals — capture before readability, decide after cleaning

**Files:**
- Create: `backend/src/Service/Reader/Paywall/PaywallSignals.php`
- Test: `backend/tests/Service/Reader/Paywall/PaywallSignalsTest.php`

**Interfaces:**
- Consumes: `SchemaOrgAccess::paywalledIn(string): ?bool` (Task 1), `PaywallBlocks::textsIn(HTMLDocument): list<string>`, `SqueezedText::of(string): string` (Task 2), `App\Service\Html\HtmlDocumentParser::parseOrNull(string): ?HTMLDocument` (exists).
- Produces: `PaywallSignals::fromPage(string $html, ?\Dom\HTMLDocument $normalized): self` and `PaywallSignals::isPreview(string $cleanedBodyHtml): bool`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallSignals;
use PHPUnit\Framework\TestCase;

final class PaywallSignalsTest extends TestCase
{
    private const string FIRST = '<p>The first preview paragraph carries enough prose to be kept by readability.</p>';
    private const string SECOND = '<p>The second preview paragraph is where the free part of the article ends.</p>';
    private const string CTA = '<div class="paywall-cta"><h2 class="paywall-title">Continue reading this post for free.</h2>'
        . '<button>Claim my free post</button></div>';
    private const string PREVIEW_BODY = self::FIRST . self::SECOND;

    public function testTheJsonLdDeclarationAloneFlagsAPaywall(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY, '{"@type":"Article","isAccessibleForFree":false}'));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAJsonLdFreeDeclarationDecidesAloneOverAPaywallBlock(): void
    {
        $signals = $this->signals(
            $this->page(self::PREVIEW_BODY . self::CTA, '{"@type":"Article","isAccessibleForFree":true}'),
        );

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPaywallBlockBelowTheLastExtractedParagraphFlagsAPreview(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPaywallBannerAboveTheArticleDoesNotFlagAFreeArticle(): void
    {
        $banner = '<div class="paywall-banner"><p>Support independent journalism: become a member.</p></div>';
        $signals = $this->signals($this->page($banner . self::PREVIEW_BODY));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPromoBoxBetweenTwoExtractedParagraphsDoesNotFlagAFreeArticle(): void
    {
        $signals = $this->signals($this->page(self::FIRST . self::CTA . self::SECOND));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testProseThatMentionsAPaywallIsNotASignal(): void
    {
        $prose = '<p>Some sites hide their best writing behind a paywall, and that is their right.</p>';
        $signals = $this->signals($this->page(self::FIRST . $prose));

        self::assertFalse($signals->isPreview(self::FIRST . $prose));
    }

    public function testACtaTheCleanersLeftInTheBodyStillCountsFromTheLastProseParagraph(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY . '<p>Continue reading this post for free.</p>'));
    }

    public function testWhenTheLastParagraphCannotBeFoundABlockAbsentFromTheBodyCounts(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview('<p>A paragraph a cleaner rewrote beyond recognition.</p>'));
    }

    public function testWhenTheLastParagraphCannotBeFoundABlockStillInTheBodyDoesNotCount(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertFalse($signals->isPreview('<p>Rewritten.</p><p>Continue reading this post for free. Claim my free post</p>'));
    }

    public function testAPageWithoutADocumentCanOnlyBeDeclaredPaywalled(): void
    {
        self::assertFalse(PaywallSignals::fromPage('', null)->isPreview(self::PREVIEW_BODY));
        self::assertTrue(
            PaywallSignals::fromPage(
                '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>',
                null,
            )->isPreview(self::PREVIEW_BODY),
        );
    }

    public function testAnEmptyBodyIsNeverAPreviewWithoutADeclaration(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertFalse($signals->isPreview(''));
    }

    public function testAGatedWrapperAroundTheWholeArticleCountsByTheCallToActionItAdds(): void
    {
        // jungle.world: `subscription-only` wraps the preview AND the call to
        // action, so no paragraph stands outside a block and the fallback decides.
        $wrapper = '<div class="body-wrapper subscription-only">' . self::PREVIEW_BODY
            . '<div class="subscription-only-block"><h2>Noch kein Abonnement?</h2>'
            . '<p>Um diesen Inhalt zu lesen, wird ein Online-Abo benötigt.</p></div></div>';
        $signals = $this->signals($this->page($wrapper));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAGatedWrapperThatAddsNothingBeyondTheBodyDoesNotCount(): void
    {
        $signals = $this->signals($this->page('<div class="paywall-container">' . self::PREVIEW_BODY . '</div>'));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    private function signals(string $html): PaywallSignals
    {
        return PaywallSignals::fromPage($html, HtmlDocumentParser::parseOrNull($html));
    }

    private function page(string $article, ?string $jsonLd = null): string
    {
        $head = $jsonLd === null ? '' : '<script type="application/ld+json">' . $jsonLd . '</script>';

        return "<html><head>{$head}</head><body>\n<nav><a href=\"/\">Home</a></nav>\n"
            . "<article>\n<h1>Headline</h1>\n{$article}\n</article>\n<footer>Foot</footer>\n</body></html>";
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Paywall/PaywallSignalsTest.php`
Expected: error — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * What the fetched page says about a paywall, captured before readability
 * consumes the shared document and judged once the cleaned body exists (#785).
 * The publisher's declaration decides alone; a DOM block counts only at or
 * after the last extracted paragraph, so a banner above a free article never flags it.
 */
final readonly class PaywallSignals
{
    /** @param list<string> $blockTexts */
    private function __construct(
        private ?bool $declared,
        private array $blockTexts,
        private string $pageText,
    ) {
    }

    public static function fromPage(string $html, ?HTMLDocument $normalized): self
    {
        return new self(
            SchemaOrgAccess::paywalledIn($html),
            $normalized === null ? [] : PaywallBlocks::textsIn($normalized),
            SqueezedText::of((string) ($normalized?->body?->textContent ?? '')),
        );
    }

    /** True when the cleaned body is the free preview of a paywalled article. */
    public function isPreview(string $cleanedBodyHtml): bool
    {
        if ($this->declared !== null) {
            return $this->declared;
        }
        $body = HtmlDocumentParser::parseOrNull($cleanedBodyHtml)?->body;
        if ($this->blockTexts === [] || $body === null) {
            return false;
        }

        $anchor = $this->lastProsePosition($body);
        $bodyText = SqueezedText::of((string) $body->textContent);
        foreach ($this->blockTexts as $blockText) {
            if ($this->standsBelowThePreview($blockText, $anchor, $bodyText)) {
                return true;
            }
        }

        return false;
    }

    /** Where the body's last prose paragraph stands in the page text, or null when it cannot be found there. */
    private function lastProsePosition(Element $body): ?int
    {
        foreach (array_reverse(iterator_to_array($body->getElementsByTagName('p'))) as $paragraph) {
            $text = SqueezedText::of((string) $paragraph->textContent);
            if ($text === '' || $this->isPartOfAPaywallBlock($text)) {
                continue;
            }
            $position = mb_strrpos($this->pageText, $text);

            return $position === false ? null : $position;
        }

        return null;
    }

    private function isPartOfAPaywallBlock(string $paragraphText): bool
    {
        foreach ($this->blockTexts as $blockText) {
            if (str_contains($blockText, $paragraphText)) {
                return true;
            }
        }

        return false;
    }

    /** With no anchor to measure against, a block the extraction dropped is the signal. */
    private function standsBelowThePreview(string $blockText, ?int $anchor, string $bodyText): bool
    {
        if ($anchor === null) {
            return !str_contains($bodyText, $blockText);
        }
        $position = mb_strrpos($this->pageText, $blockText);

        return $position !== false && $position >= $anchor;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/Paywall/PaywallSignalsTest.php`
Expected: 13 tests pass.

- [ ] **Step 5: Lint**

Run: `composer cs && composer md && composer stan`
Expected: clean. If PHPMD flags `isPreview` for complexity, extract the loop into a private `anyBlockBelow(?int $anchor, string $bodyText): bool`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Reader/Paywall/PaywallSignals.php tests/Service/Reader/Paywall/PaywallSignalsTest.php
git commit -m "feat(#785): decide a paywall preview from the declaration or a block below the last paragraph"
```

---

### Task 4: `ExtractionResult::paywalled`, extractor wiring, and end-to-end fixtures

**Files:**
- Modify: `backend/src/Service/Reader/ExtractionResult.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php:50-97`
- Create: `backend/tests/Fixtures/reader/article-paywalled-jsonld-boolean.html`
- Create: `backend/tests/Fixtures/reader/article-paywalled-jsonld-string.html`
- Create: `backend/tests/Fixtures/reader/article-paywalled-dom-block.html`
- Create: `backend/tests/Fixtures/reader/article-free-substack.html`
- Create: `backend/tests/Fixtures/reader/article-free-paywall-banner.html`
- Create: `backend/tests/Fixtures/reader/article-free-jsonld-true.html`
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php`

**Interfaces:**
- Consumes: `PaywallSignals::fromPage(string, ?HTMLDocument)` and `->isPreview(string)` (Task 3).
- Produces: `ExtractionResult::ok(string $url, string $title, ?string $byline, ?string $siteName, string $contentHtml, ?string $excerpt, bool $paywalled = false): self` and `public bool $paywalled` (always `false` on `failed`).

- [ ] **Step 1: Write the fixtures**

Every fixture keeps the two long paragraphs of `backend/tests/Fixtures/reader/article.html` (copy them verbatim) so readability clears the 200-character gate.

`article-paywalled-jsonld-boolean.html` (the RiffReporter shape):

```html
<!DOCTYPE html>
<html lang="de"><head><title>Die letzte Prostituierte — Site</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Die letzte Prostituierte","isAccessibleForFree":false,"hasPart":{"@type":"WebPageElement","isAccessibleForFree":false,"cssSelector":".paywall"}}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>Die letzte Prostituierte</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
    <div class="paywall"><p>Dieser Text ist Teil unseres Abo-Angebots. Jetzt Mitglied werden und weiterlesen.</p></div>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`article-paywalled-jsonld-string.html` (the SZ.de shape — string `"False"`, no paywall element at all):

```html
<!DOCTYPE html>
<html lang="de"><head><title>Die große El-Mala-Frage — Site</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Die große El-Mala-Frage","hasPart":{"@type":"WebPageElement","cssSelector":".article-content","isAccessibleForFree":"False"}}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article class="article-content">
    <h1>Die große El-Mala-Frage</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`article-paywalled-dom-block.html` (the Substack shape — a JSON-LD block WITHOUT the key, then the CTA below the preview):

```html
<!DOCTYPE html>
<html lang="en"><head><title>Introduction to trip sitting — Site</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Introduction to trip sitting"}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>Introduction to trip sitting</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
    <div class="paywall-cta">
      <h2 class="paywall-title">Continue reading this post for free, courtesy of Example Author.</h2>
      <button>Claim my free post</button>
    </div>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`article-free-substack.html` (the negative: same shape, a subscribe widget but no paywall block):

```html
<!DOCTYPE html>
<html lang="en"><head><title>Audio version of When the Rituals — Site</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"Audio version of When the Rituals"}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>Audio version of When the Rituals</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
    <div class="subscribe-widget"><p>Subscribe to get new posts in your inbox.</p><button>Subscribe</button></div>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`article-free-paywall-banner.html` (the negative: a `paywall-*` banner ABOVE the article, no declaration):

```html
<!DOCTYPE html>
<html lang="en"><head><title>A free article — Site</title></head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <div class="paywall-banner"><p>Support independent journalism: become a member today.</p></div>
  <article>
    <h1>A free article</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`article-free-jsonld-true.html` (the negative: a free post on a paywalled site — declaration `true`, CTA below):

```html
<!DOCTYPE html>
<html lang="en"><head><title>A free post — Site</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","headline":"A free post","isAccessibleForFree":true}</script>
</head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>A free post</h1>
    <p>First substantial paragraph with more than enough words for the extraction
       heuristics to recognise this block as the real article body rather than
       boilerplate. Readability scores a candidate by the amount of readable text
       and the density of links, so this sentence is deliberately long and prose
       heavy, with commas and ordinary words and no navigation at all, repeated so
       the character threshold is comfortably exceeded on its own.</p>
    <p>Second substantial paragraph, again long enough to matter to the scoring so
       readability keeps it in the extracted output. It continues with more plain
       prose about nothing in particular, adding sentences and clauses and yet more
       ordinary words, so that together with the first paragraph the article body
       is unambiguously the highest scoring region of the whole document.</p>
    <div class="paywall-cta"><h2 class="paywall-title">Enjoying this? Subscribe for the paid posts.</h2></div>
  </article>
  <footer>© 2026</footer>
</body></html>
```

- [ ] **Step 2: Write the failing tests**

Append to `backend/tests/Service/Reader/ArticleExtractorTest.php` (inside the class, after the last test), and add `self::assertFalse($result->paywalled);` as the last assertion of the existing `testExtractsAndAbsolutisesImages`:

```php
    public function testFlagsAPaywalledArticleDeclaredInJsonLd(): void
    {
        $result = $this->extractFixture('article-paywalled-jsonld-boolean.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
        self::assertStringContainsString('First substantial paragraph', (string) $result->contentHtml);
    }

    public function testAcceptsTheStringFormOfTheJsonLdDeclaration(): void
    {
        $result = $this->extractFixture('article-paywalled-jsonld-string.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
    }

    public function testFlagsAPaywallBlockBelowTheExtractedPreview(): void
    {
        $result = $this->extractFixture('article-paywalled-dom-block.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
        self::assertStringContainsString('Second substantial paragraph', (string) $result->contentHtml);
    }

    public function testDoesNotFlagAFreePostWithoutAPaywallBlock(): void
    {
        $result = $this->extractFixture('article-free-substack.html');

        self::assertTrue($result->ok);
        self::assertFalse($result->paywalled);
    }

    public function testDoesNotFlagAPaywallBannerAboveTheArticle(): void
    {
        $result = $this->extractFixture('article-free-paywall-banner.html');

        self::assertTrue($result->ok);
        self::assertFalse($result->paywalled);
    }

    public function testTheJsonLdDeclarationDecidesAloneOverAPaywallBlock(): void
    {
        $result = $this->extractFixture('article-free-jsonld-true.html');

        self::assertTrue($result->ok);
        self::assertFalse($result->paywalled);
    }

    private function extractFixture(string $fixture): ExtractionResult
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/' . $fixture);

        return $this->extractor([new MockResponse($html, ['http_code' => 200])])->extract('https://site.test/post');
    }
```

Add `use App\Service\Reader\ExtractionResult;` to the test's imports.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: the new tests error on the undefined property `paywalled`.

- [ ] **Step 4: Extend `ExtractionResult`**

Replace the class body so the constructor, `ok()` and `failed()` read:

```php
    private function __construct(
        public bool $ok,
        public ?string $url,
        public ?string $reason,
        public ?string $title,
        public ?string $byline,
        public ?string $siteName,
        public ?string $contentHtml,
        public ?string $excerpt,
        public bool $paywalled,
    ) {
    }

    public static function ok(
        string $url,
        string $title,
        ?string $byline,
        ?string $siteName,
        string $contentHtml,
        ?string $excerpt,
        bool $paywalled = false,
    ): self {
        return new self(true, $url, null, $title, $byline, $siteName, $contentHtml, $excerpt, $paywalled);
    }

    public static function failed(?string $url, string $reason): self
    {
        return new self(false, $url, $reason, null, null, null, null, null, false);
    }
```

Add one line to the class docblock, after the reason list: ` * `paywalled` marks an ok body that is the free preview of a paywalled article (#785).`

- [ ] **Step 5: Wire `ArticleExtractor`**

In `extract()`, add `use App\Service\Reader\Paywall\PaywallSignals;` and capture the signals right after the image inventory, then pass the verdict:

```php
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $paywall = PaywallSignals::fromPage($page->html, $normalized);
        $media = $this->mediaScanner->scan($page->html, $page->finalUrl);
```

and

```php
        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
            paywalled: $paywall->isPreview($clean),
        );
```

Add one sentence to the end of the class docblock's last paragraph: `PaywallSignals reads the same normalised document and the raw source before readability consumes them, and decides on the cleaned body (#785).`

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: all pass, including the six new tests. If `testFlagsAPaywallBlockBelowTheExtractedPreview` fails because readability dropped the second paragraph, do not change the rule — check `$result->contentHtml` in the failure output and report it in your report as a concern.

Then run the whole suite: `php bin/phpunit`
Expected: green (the `ok()` default keeps every existing call site valid).

- [ ] **Step 7: Lint**

Run: `composer cs && composer md && bin/console cache:warmup --env=dev >/dev/null && composer stan`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Reader/ExtractionResult.php src/Service/Reader/ArticleExtractor.php tests/Fixtures/reader/article-paywalled-jsonld-boolean.html tests/Fixtures/reader/article-paywalled-jsonld-string.html tests/Fixtures/reader/article-paywalled-dom-block.html tests/Fixtures/reader/article-free-substack.html tests/Fixtures/reader/article-free-paywall-banner.html tests/Fixtures/reader/article-free-jsonld-true.html tests/Service/Reader/ArticleExtractorTest.php
git commit -m "feat(#785): carry a paywalled flag on the extraction result"
```

---

### Task 5: The JSON field and the audit metric

**Files:**
- Modify: `backend/src/Http/ReaderJson.php`
- Modify: `backend/tests/Controller/Api/EntryReaderControllerTest.php`
- Modify: `backend/src/Service/ReaderAudit/ReaderAuditRunner.php:44-97`
- Modify: `backend/tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`

**Interfaces:**
- Consumes: `ExtractionResult::ok(..., bool $paywalled = false)` and `->paywalled` (Task 4).
- Produces: JSON `paywalled: bool` on the `ok` branch of `GET /api/entries/{id}/reader`; audit metric `paywalled` (`0`/`1`) on every non-crashed finding.

- [ ] **Step 1: Write the failing tests**

In `EntryReaderControllerTest::testOwnedEntryOkReturnsExtractedArticle`, add after the `excerpt` assertion:

```php
        self::assertFalse($body['paywalled']);
```

Add a new test after it:

```php
    public function testDeclaresAPaywalledPreviewSoTheClientCanSaySo(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-paywalled@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::ok(
            url: 'https://example.com/article',
            title: 'The Title',
            byline: null,
            siteName: null,
            contentHtml: '<p>The free preview.</p>',
            excerpt: null,
            paywalled: true,
        ));
        $entry = $this->seedEntry($user, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ok', $body['status']);
        self::assertTrue($body['paywalled']);
    }
```

In `ReaderAuditRunnerTest`:
- `testMeasuresTheCleanedBodyForTheReport`: add `self::assertSame(0, $finding->metrics['paywalled']);`
- `testAFailedExtractionReportsZeroesRatherThanNoMeasurements`: change the expected array to `['chars' => 0, 'paragraphs' => 0, 'links' => 0, 'images' => 0, 'leadingBlocks' => 0, 'paywalled' => 0]`.
- Add:

```php
    public function testCountsAPaywalledPreviewAsAMetricNotAMarker(): void
    {
        // A paywall is not a cleaner defect: the sweep separates previews from
        // over-trims by this metric, and the score must not rise for it.
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://example.test/a',
            'Titel',
            null,
            null,
            '<p>Die kostenlose Vorschau eines Artikels hinter der Bezahlschranke.</p>',
            null,
            paywalled: true,
        ));

        $finding = $this->auditOne($extractor);

        self::assertSame(1, $finding->metrics['paywalled']);
        self::assertNotContains('paywalled', $finding->markerCodes());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`
Expected: failures — undefined index `paywalled`.

- [ ] **Step 3: Emit the field in `ReaderJson`**

Add `'paywalled' => $r->paywalled,` after `'excerpt' => $r->excerpt,` in the `ok` array, and add `paywalled: bool,` after `excerpt: string|null,` in the `@return` shape docblock.

- [ ] **Step 4: Emit the metric in `ReaderAuditRunner`**

Change the call in `audit()` to `metrics: $this->metrics($result, $body),` and the method to:

```php
    /** @return array<string, int|float> */
    private function metrics(ExtractionResult $result, ?ExtractedBody $body): array
    {
        if ($body === null) {
            return ['chars' => 0, 'paragraphs' => 0, 'links' => 0, 'images' => 0, 'leadingBlocks' => 0, 'paywalled' => 0];
        }

        return [
            'chars' => $body->textLength(),
            'paragraphs' => $body->paragraphCount,
            'links' => \count($body->links),
            'images' => \count($body->imageSources),
            'leadingBlocks' => \count($body->leadingBlocks()),
            'paywalled' => (int) $result->paywalled,
        ];
    }
```

Add `use App\Service\Reader\ExtractionResult;`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php tests/Service/ReaderAudit`
Expected: green.

- [ ] **Step 6: Lint**

Run: `composer cs && composer md && composer stan`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Http/ReaderJson.php tests/Controller/Api/EntryReaderControllerTest.php src/Service/ReaderAudit/ReaderAuditRunner.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
git commit -m "feat(#785): expose the paywalled flag on the reader JSON and as an audit metric"
```

---

### Task 6: The note under the body

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (the `ReaderArticle` interface)
- Modify: `frontend/src/app/reader/reader-cache.service.ts:21-33`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts:236-241` (beside `article`)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html:200` (after the `.content` div)
- Modify: `frontend/public/i18n/en.json:840`, `frontend/public/i18n/de.json:840` (the `reader` object)
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: JSON `paywalled: boolean` on the `ok` reader response (Task 5).
- Produces: `ReaderArticle.paywalled: boolean`; component signals `paywalled()` and `paywallUrl()`; i18n keys `reader.paywalled`, `reader.paywalledLink`; cache `VERSION = 11`.

- [ ] **Step 1: Write the failing tests**

In `reader-view.component.spec.ts`, add `paywalled: false,` to the `okContent` defaults (after `extractedAt: '',`). Add after the test `'falls back to feed content and shows a note when extraction fails'`:

```ts
  it('says under the body that this is the free preview of a paywalled article', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent({ paywalled: true, url: 'https://pub.test/a' })));
    const el = mount(entry()).nativeElement as HTMLElement;
    const note = el.querySelector('.paywall-note');
    expect(note).not.toBeNull();
    expect(note!.previousElementSibling).toBe(el.querySelector('.content'));
    expect(note!.querySelector('a')!.getAttribute('href')).toBe('https://pub.test/a');
  });

  it('shows no paywall note for a freely readable article', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent({ paywalled: false })));
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.paywall-note')).toBeNull();
  });

  it('drops the paywall note in the original view, which shows the feed body', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent({ paywalled: true })));
    const f = mount(entry());
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.paywall-note')).not.toBeNull();

    (el.querySelector('.mode') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('.paywall-note')).toBeNull();
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from the repo root: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts`
Expected: the three new tests fail (TypeScript error on `paywalled` first — fix the model in Step 3 and re-run to see the assertion failures).

- [ ] **Step 3: The model and the cache**

In `models.ts`, add to `ReaderArticle` after `excerpt: string | null;`:

```ts
  /** True when contentHtml is the free preview of a paywalled article (#785). */
  paywalled: boolean;
```

In `reader-cache.service.ts`, add a comment line after the `// v9:` block and bump the constant:

```ts
  // v10: v9 records hold trailing teaser carousels (#779).
  // v11: v10 records carry no `paywalled` flag (#785); an already-read
  // preview would never show the paywall note.
  private static readonly VERSION = 11;
```

(If the file already carries a `// v10:` line, keep it and add only the `v11` lines.)

- [ ] **Step 4: The component**

In `reader-view.component.ts`, after the `article` computed:

```ts
  /** The reader body is the free preview of a paywalled article (#785). The
   *  original view shows the feed's own teaser, which needs no such note. */
  readonly paywalled = computed(() => this.mode() === 'reader' && (this.article()?.paywalled ?? false));
  readonly paywallUrl = computed(() => this.article()?.url || this.entry()?.url || null);
```

In `reader-view.component.html`, directly after `<div #content class="content" [innerHTML]="displayHtml()"></div>`:

```html
        @if (paywalled()) {
          <p class="reader-note paywall-note">
            {{ 'reader.paywalled' | transloco }}
            @if (paywallUrl(); as url) {
              <a [href]="url" target="_blank" rel="noopener noreferrer">{{
                'reader.paywalledLink' | transloco
              }}</a>
            }
          </p>
        }
```

Add **nothing** to `reader-view.component.scss`.

- [ ] **Step 5: The i18n keys**

In `en.json`, after `"readerFallback": …,` inside `"reader"`:

```json
    "paywalled": "This is the free preview of a paywalled article. The rest is on the publisher's site.",
    "paywalledLink": "Continue on the publisher's site",
```

In `de.json`, at the same place:

```json
    "paywalled": "Das ist die kostenlose Vorschau eines Artikels hinter einer Bezahlschranke. Der Rest steht auf der Seite des Anbieters.",
    "paywalledLink": "Beim Anbieter weiterlesen",
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view/reader-view.component.spec.ts src/app/reader`
Expected: green.

- [ ] **Step 7: Lint, full check, and the style budget**

Run from `frontend/`: `npx prettier --write src/app/reader/reader-view/reader-view.component.html src/app/reader/reader-view/reader-view.component.ts src/app/reader/reader-view/reader-view.component.spec.ts src/app/reader/models.ts src/app/reader/reader-cache.service.ts public/i18n/en.json public/i18n/de.json`
Then from the repo root: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest green.
Then from `frontend/`: `npm run build 2>&1 | grep -E "reader-view|exceeded maximum budget|ERROR"`
Expected: `reader-view.component.scss` still reports the 4 kB *warning* at 7.97 kB and no *error* line.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-cache.service.ts frontend/src/app/reader/reader-view/reader-view.component.ts frontend/src/app/reader/reader-view/reader-view.component.html frontend/src/app/reader/reader-view/reader-view.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#785): note under the reader body that a paywalled article shows its free preview"
```

---

### Task 7: Verification (controller-run)

**Files:** none changed.

- [ ] **Step 1: Backend gates**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
Expected: all green; Infection MSI on the changed files at or above `minMsi` (80).

- [ ] **Step 2: MySQL leg**

From the repo root: `docker compose exec -T php vendor/bin/phpunit tests/Service/Reader tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit`
Expected: green.

- [ ] **Step 3: Live acceptance over the seven example entries**

From the repo root:

```bash
docker compose exec -T php bin/console app:reader:audit --entries=457216,482061,481913,482682,331138,495251,491202,495258,481899 --out=var/reader-audit/785.jsonl
docker compose exec -T php sh -c "jq -c '{entryId, extracted, paywalled: .metrics.paywalled}' var/reader-audit/785.jsonl"
```

Expected: `paywalled: 1` for 457216, 482061, 481913, 482682, 331138, 495251, 491202, 495258 and `paywalled: 0` for 481899. A `fetch` failure on one entry is a network fact, not a defect: rerun once, then report it.

- [ ] **Step 4: Frontend check**

From the repo root: `docker compose exec -T frontend npm run check`
Expected: green.
