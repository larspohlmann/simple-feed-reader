<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\ClassTokenMatcher;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ClassTokenMatcherTest extends TestCase
{
    public function testMatchesAWholeToken(): void
    {
        self::assertTrue(
            ClassTokenMatcher::hasAnyToken($this->elementWithClass('shariff-buttons shariff'), ['shariff']),
        );
    }

    public function testDoesNotMatchASubstringOfAToken(): void
    {
        // "myshariff" and "sharing-hint" each merely contain the fragment, not a
        // whole token, so neither is a match.
        self::assertFalse(
            ClassTokenMatcher::hasAnyToken($this->elementWithClass('myshariff sharing-hint'), ['shariff']),
        );
    }

    public function testReturnsFalseWhenTheElementHasNoClassAttribute(): void
    {
        self::assertFalse(ClassTokenMatcher::hasAnyToken($this->elementWithClass(null), ['shariff']));
    }

    public function testMatchesWhenAnyTokenInTheSetIsPresent(): void
    {
        self::assertTrue(
            ClassTokenMatcher::hasAnyToken($this->elementWithClass('newsletter'), ['related', 'newsletter']),
        );
    }

    private function elementWithClass(?string $class): Element
    {
        $attribute = $class !== null ? ' class="' . $class . '"' : '';
        $document = HTMLDocument::createFromString(
            '<!doctype html><html lang="en"><body><div' . $attribute . '></div></body></html>',
            LIBXML_NOERROR,
        );
        $element = $document->querySelector('div');
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
