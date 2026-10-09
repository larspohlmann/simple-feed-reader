# #1473 Parser: XmlHelper Child Lookups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the parser's hand-rolled "direct child X in namespace Y" loops with `XmlHelper`'s lookups, with no behaviour change.

**Architecture:** `XmlHelper` gains `isElement(\DOMNode, string $localName, ?string $namespaceUri): bool` (with `@phpstan-assert-if-true \DOMElement`), which `childElements` itself uses. The single-match loops become `childElement`; the mixed per-node predicates that collect every match stay loops and call `isElement`, so both private `isMediaElement` copies go.

**Tech Stack:** PHP 8.4, PHPUnit.

**Spec:** GitHub issue #1473.

## Global Constraints

- No behaviour change: existing parser tests stay green, unchanged.
- First-match semantics kept: `FeedMediaNode::title()` and `ItemMediaExtractor::posterIn()` read the first matching element even when it is empty.

---

### Task 1: The refactor

**Files:**
- Modify: `backend/src/Service/Parser/Support/XmlHelper.php` (add `isElement`, `childElements` uses it)
- Modify: `backend/src/Service/Parser/ItemMediaExtractor.php` (`itunesDuration`, `posterIn` via `childElement`; delete `isItunesDuration`, `isMediaElement`)
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php` (group loop via `childElements`; `mediaCandidatesIn` via `isElement`; delete `isMediaElement` and the `@var` casts)
- Modify: `backend/src/Service/Parser/Pass/FeedMediaNode.php` (`title()` via `childElement`; delete `isMediaTitle`)
- Modify: `backend/src/Service/Parser/FeedFormatParser/AbstractAtomParser.php` (`authorName`/`authorUri` share one `authorChildText($entry, $localName)`)
- Test: `backend/tests/Service/Parser/Support/XmlHelperTest.php` (`isElement`: right name+namespace, wrong namespace, null namespace, text node)

- [ ] Write the `isElement` tests, run them, see them fail.
- [ ] Implement `isElement`; refactor the four classes.
- [ ] `php bin/phpunit tests/Service/Parser` green, unchanged.
- [ ] `composer check`, `composer md`, `composer infection:diff`, PhpStorm inspections.
- [ ] Commit `refactor(#1473): parser child lookups through XmlHelper`.
