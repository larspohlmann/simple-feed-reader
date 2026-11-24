# One Comment-Body Renderer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every comment source renders its body through one service, which then links bare URLs for all of them.

**Architecture:** A `CommentBodyModel` says what a body is (HTML, Markdown, plain text); `CommentBodyRenderer` converts, links bare URLs (Task 5) and sanitizes. The five sources build the model and call the renderer instead of `EntrySanitizer`/`MarkdownHtml`/`LinkedPlainText` directly. The HTML-aware bare-URL linker moves out of `SharedLinks` into `Foundation/Html/Support`.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 12, `Dom\HTMLDocument`.

**Spec:** `docs/superpowers/specs/2026-11-24-one-comment-body-renderer-design.md`

## Global Constraints

- CLAUDE.md house style: `final readonly`, verb-first names, no comments unless a reader would get it wrong, PHPMD-clean touched files, `composer check` green.
- `Service/Content/Comments` owns the renderer; `Social/Bluesky` and `Social/Mastodon` already depend on that module (they build `EntryCommentModel`), so no new module edge.
- Tasks 1–3 (#2511) change no rendered HTML except that Substack bodies pass the sanitizer (`target="_blank"`, `rel="noopener noreferrer"` on their anchors).
- Commits: `refactor(#2511): …` for Tasks 1–4, `feat(#2512): …` for Task 5.

## Review Focus

- A URL followed by sentence punctuation (`https://example.org/a.`) — the period stays outside the link (Task 5 test).
- A URL already inside an `<a>`, `<code>` or `<pre>` — untouched, no nested anchor (Task 5 tests).
- A Markdown autolink `<https://example.org>` — linked once, not twice (Task 5 test).
- A body that is only whitespace, or HTML that sanitizes to nothing — null, so Lemmy and feed sources still drop it (Task 1 test).
- Plain text with `<b>` — stays escaped through the sanitizer (Task 2, existing Substack test).

---

### Task 1: `CommentBodyModel` and `CommentBodyRenderer`

**Files:**
- Create: `backend/src/Service/Content/Comments/Model/CommentBodyModel.php`
- Create: `backend/src/Service/Content/Comments/Model/CommentBodyFormat.php` (enum: `Html`, `Markdown`, `PlainText`)
- Create: `backend/src/Service/Content/Comments/CommentBodyRenderer.php`
- Test: `backend/tests/Service/Content/Comments/CommentBodyRendererTest.php`
- Modify: `backend/tests/Support/SocialApiWiring.php` (add the helper below)

**Interfaces:**
- Produces: `CommentBodyModel::fromHtml(string $html): self`, `::fromMarkdown(string $markdown): self`, `::fromPlainText(string $text): self`; public readonly `CommentBodyFormat $format`, `string $source`.
- Produces: `CommentBodyRenderer::__construct(MarkdownHtml $markdown, EntrySanitizer $sanitizer)`, `buildHtml(CommentBodyModel $body): ?string` — null when the body renders empty.
- Produces: `SocialApiWiring::commentBodyRenderer(): CommentBodyRenderer` built from `self::markdownHtml()` and `new EntrySanitizer(new TrailingBlankRemover())`.

- [ ] **Step 1: Write the failing tests**

```php
public function testHtmlIsSanitized(): void
{
    self::assertSame(
        '<p>Hi <a href="https://example.org" rel="noopener noreferrer" target="_blank">there</a></p>',
        $this->renderer->buildHtml(CommentBodyModel::fromHtml('<p>Hi <a href="https://example.org">there</a><script>x</script></p>')),
    );
}

public function testMarkdownIsConvertedThenSanitized(): void
{
    self::assertSame('<p><strong>bold</strong> &lt;i&gt;</p>', $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('**bold** <i>')));
}

public function testPlainTextIsEscapedParagraphedAndSanitized(): void
{
    self::assertSame('<p>a &lt;b&gt;<br>c</p><p>d</p>', $this->renderer->buildHtml(CommentBodyModel::fromPlainText("a <b>\nc\n\nd")));
}

#[DataProvider('emptyBodies')]
public function testAnEmptyBodyRendersAsNothing(CommentBodyModel $body): void
{
    self::assertNull($this->renderer->buildHtml($body));
}

/** @return iterable<string, array{CommentBodyModel}> */
public static function emptyBodies(): iterable
{
    yield 'blank html' => [CommentBodyModel::fromHtml('  ')];
    yield 'html that sanitizes away' => [CommentBodyModel::fromHtml('<script>x</script>')];
    yield 'blank markdown' => [CommentBodyModel::fromMarkdown("\n")];
    yield 'blank text' => [CommentBodyModel::fromPlainText(' ')];
}
```

Adjust only the exact expected strings to what the sanitizer emits (attribute order), never the behavior.

- [ ] **Step 2: Run and see them fail** — `php bin/phpunit tests/Service/Content/Comments/CommentBodyRendererTest.php` → class not found.
- [ ] **Step 3: Implement** — `buildHtml()` matches on `$body->format`: Html → as is, Markdown → `$this->markdown->toHtml()`, PlainText → `LinkedPlainText::asHtml()` (null stays null); then `$this->sanitizer->sanitize()`.
- [ ] **Step 4: Run, PASS.**
- [ ] **Step 5: Commit** — `refactor(#2511): one renderer for comment bodies`

### Task 2: Comment sources in `Content/Comments` use the renderer

**Files:**
- Modify: `backend/src/Service/Content/Comments/CommentsSource/CommentsFeedSource.php` (replace `EntrySanitizer` with `CommentBodyRenderer`; `(string) $this->renderer->buildHtml(CommentBodyModel::fromHtml(...))` keeps today's `(string)`)
- Modify: `backend/src/Service/Content/Comments/CommentsSource/LemmyCommentsSource.php`, `Pass/LemmyThread.php` (constructor takes `CommentBodyRenderer` instead of `MarkdownHtml` + `EntrySanitizer`; body via `fromMarkdown`)
- Modify: `backend/src/Service/Content/Comments/CommentsSource/SubstackCommentsSource.php`, `Pass/SubstackThread.php` (source injects the renderer and passes it to `new SubstackThread(...)`; body via `fromPlainText`)
- Test: `backend/tests/Service/Content/Comments/CommentsSource/SubstackCommentsSourceTest.php`

**Interfaces:** Consumes Task 1.

- [ ] **Step 1: Write the failing test** in `SubstackCommentsSourceTest`:

```php
public function testABodyPassesTheSanitizerLikeEveryOtherComment(): void
{
    self::assertStringContainsString('target="_blank"', $this->comments()[0]->html);
}
```

- [ ] **Step 2: Run, FAIL** (Substack anchors carry no target today).
- [ ] **Step 3: Rewire the three sources** as listed.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Content/Comments` → PASS, existing tests unchanged.
- [ ] **Step 5: Commit** — `refactor(#2511): feed, Lemmy and Substack comments render through the renderer`

### Task 3: Bluesky and Mastodon replies use the renderer

**Files:**
- Modify: `backend/src/Service/Social/Bluesky/BlueskyThreadReplies.php` (`buildHtml()` hands `fromHtml($text . $link)` to the renderer, `?? ''` stays)
- Modify: `backend/src/Service/Social/Mastodon/MastodonContextReplies.php` (`buildHtml()` hands `fromHtml($folded)`, `?? ''` stays)
- Modify every `new BlueskyThreadReplies(` / `new MastodonContextReplies(` in `backend/tests` (`Support/SelfThreadEnrichers.php`, `Mastodon/MastodonSelfThreadFoldTest.php`, `MastodonContextRepliesTest.php`, `MastodonReplyThreadSourceTest.php`, `Bluesky/BlueskyReplyThreadSourceTest.php`, `BlueskyThreadRepliesTest.php`) to pass `SocialApiWiring::commentBodyRenderer()`.

**Interfaces:** Consumes Task 1.

- [ ] **Step 1: Rewire both classes and their test constructions.** Pure refactoring: the existing tests are the pin.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social` → PASS with no assertion changed.
- [ ] **Step 3: Verify no comment source still renders on its own:** `grep -rn "EntrySanitizer\|MarkdownHtml\|LinkedPlainText" src/Service/Content/Comments src/Service/Social/Bluesky/BlueskyThreadReplies.php src/Service/Social/Mastodon/MastodonContextReplies.php` → only `CommentBodyRenderer.php`.
- [ ] **Step 4: Commit** — `refactor(#2511): Bluesky and Mastodon replies render through the comment-body renderer`

### Task 4: The bare-URL linker moves to `Foundation/Html/Support`

**Files:**
- Create: `backend/src/Service/Foundation/Html/Support/BareUrlLinks.php` (static-only, private constructor)
- Modify: `backend/src/Service/Social/Share/Support/SharedLinks.php` (drop `linkBareUrls`, `linkBareUrlsInText`, `anchor`, `BARE_URL_SPLIT`; call `BareUrlLinks::linkIn($document)`)
- Test: `backend/tests/Service/Foundation/Html/Support/BareUrlLinksTest.php`

**Interfaces:**
- Produces: `BareUrlLinks::linkIn(HTMLDocument $document): int` — links bare URLs (pattern `LinkedPlainText::URL`) in text outside `a, code, pre` in `$document->body`, returns how many.
- Produces: `BareUrlLinks::linkHtml(string $html): string` — parses the fragment with `HtmlDocumentParser::parseFragment`, returns the input unchanged when nothing was linked, else the body's `innerHTML`.

- [ ] **Step 1: Write the failing tests**

```php
public function testLinksABareUrlAndLeavesTrailingPunctuationOutside(): void
{
    self::assertSame('<p>See <a href="https://example.org/a">https://example.org/a</a>.</p>', BareUrlLinks::linkHtml('<p>See https://example.org/a.</p>'));
}

#[DataProvider('unlinkable')]
public function testLeavesUrlsInLinksAndCodeAlone(string $html): void
{
    self::assertSame($html, BareUrlLinks::linkHtml($html));
}

/** @return iterable<string, array{string}> */
public static function unlinkable(): iterable
{
    yield 'anchor' => ['<a href="https://example.org">https://example.org</a>'];
    yield 'code' => ['<code>https://example.org</code>'];
    yield 'pre' => ['<pre>https://example.org</pre>'];
    yield 'no url' => ['<p>plain</p>'];
}
```

- [ ] **Step 2: Run, FAIL.**
- [ ] **Step 3: Move the code** from `SharedLinks` (same algorithm), add `linkHtml`.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Foundation/Html tests/Service/Social/Share` → PASS, `SharedLinksTest` unchanged.
- [ ] **Step 5: Commit** — `refactor(#2511): the bare-URL linker leaves SharedLinks for Foundation/Html`

### Task 5: The renderer links bare URLs in every comment

**Files:**
- Modify: `backend/src/Service/Content/Comments/CommentBodyRenderer.php` (after the format conversion, before sanitizing: `BareUrlLinks::linkHtml()`, so new anchors get `target`/`rel`)
- Test: `backend/tests/Service/Content/Comments/CommentBodyRendererTest.php`
- Test: `backend/tests/Service/Content/Comments/CommentsSource/LemmyCommentsSourceTest.php`, `CommentsFeedSourceTest.php` (one source-level pin each, via a fixture comment containing a bare URL)

**Interfaces:** Consumes Tasks 1 and 4.

- [ ] **Step 1: Write the failing tests**

```php
public function testABareUrlInHtmlBecomesALinkThatOpensInANewTab(): void
{
    self::assertSame(
        '<p>see <a href="https://example.org/a" rel="noopener noreferrer" target="_blank">https://example.org/a</a>.</p>',
        $this->renderer->buildHtml(CommentBodyModel::fromHtml('<p>see https://example.org/a.</p>')),
    );
}

public function testABareUrlInMarkdownBecomesALink(): void
{
    self::assertStringContainsString('<a href="https://example.org/a"', (string) $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('see https://example.org/a')));
}

public function testAMarkdownAutolinkIsLinkedOnce(): void
{
    self::assertSame(1, substr_count((string) $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('<https://example.org/a>')), '<a '));
}
```

Plus in `LemmyCommentsSourceTest` and `CommentsFeedSourceTest`: a comment whose content is `see https://example.org/thread` yields HTML containing `<a href="https://example.org/thread"`.

- [ ] **Step 2: Run, FAIL.**
- [ ] **Step 3: Add the linking step.**
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Content tests/Service/Social` → PASS.
- [ ] **Step 5: Commit** — `feat(#2512): bare URLs in every comment body are links`

### Task 6: Gates

- [ ] `composer check`, `composer md` on touched files, PhpStorm lint on changed PHP — clean.
- [ ] `php bin/phpunit` and `docker compose exec php composer test:parallel` — green.
- [ ] `composer infection:diff` — at or above `minMsi`.
- [ ] Live check: open an entry with Lemmy comments containing a bare URL at http://localhost:4200, click it, it opens in a new tab.
