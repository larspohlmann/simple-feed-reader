<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Support\ClassTokenMatcher;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class ClassTokenMatcherTest extends TestCase
{
    use ParsesHtml;

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
        $document = $this->document(
            '<!doctype html><html lang="en"><body><div' . $attribute . '></div></body></html>'
        );
        $element = $document->querySelector('div');
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
