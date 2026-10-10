# RSS 1.0 Root Detection by Namespace — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `Rss1Parser::supports` claims only an `RDF` root in the RDF namespace, as Atom's `supports()` already checks its namespace.

**Architecture:** Replace `$root->localName === 'RDF'` with `XmlHelper::isElement($root, 'RDF', self::RDF_NS)`. A foreign `<RDF>` then gets `FeedParserFactory`'s "No parser for feed root" error instead of "RSS 1.0 document without <channel>".

**Measured before planning:** the one RDF-rooted feed of the 235-feed dev corpus is in `http://www.w3.org/1999/02/22-rdf-syntax-ns#`.

**Spec:** GitHub issue #1492.

## Global Constraints

- CLAUDE.md Clean Code rules; no new comments.
- Commit format: `fix(#1492): <lower-case summary>`; no attribution lines. Run from `backend/`.

---

### Task 1: the RDF root is matched by namespace

**Files:**
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php` (`supports`)
- Test: `backend/tests/Service/Parser/Factory/FeedParserFactoryTest.php`

- [ ] **Step 1: Failing tests.** Append to `FeedParserFactoryTest`:

```php
    public function testThrowsForAnRdfRootWithoutANamespace(): void
    {
        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessage('No parser for feed root <RDF>');
        $this->factory()->parserFor($this->root('<RDF><channel><title>x</title></channel></RDF>'));
    }

    public function testThrowsForAnRdfRootInAForeignNamespace(): void
    {
        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessage('No parser for feed root <RDF>');
        $this->factory()->parserFor(
            $this->root('<x:RDF xmlns:x="urn:example:other"><channel><title>x</title></channel></x:RDF>'),
        );
    }
```

Run: `php bin/phpunit tests/Service/Parser/Factory/FeedParserFactoryTest.php`. Expected: both FAIL (no exception; `Rss1Parser` is returned).

- [ ] **Step 2: Implement.** `Rss1Parser::supports` returns `XmlHelper::isElement($root, 'RDF', self::RDF_NS);`.

- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Parser`. Expected: PASS.

- [ ] **Step 4: Corpus diff** against the `develop` baseline. Expected: identical.

- [ ] **Step 5: Commit.** `git commit -m "fix(#1492): the rss 1.0 root is rdf:RDF in the rdf namespace"`

### Task 2: Gates

- [ ] `composer cs`, `stan`, `md`, `tramp`, PhpStorm `lint_files` on the changed files; `composer test:parallel`, `docker compose exec php composer test` (repo root), `composer infection:diff`.
