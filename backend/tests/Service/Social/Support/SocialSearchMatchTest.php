<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\SocialSearchMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialSearchMatchTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function verdicts(): iterable
    {
        yield 'every term present' => ['rss reader', 'A reader for RSS', true];
        yield 'a term missing' => ['rss reader', 'RSS only', false];
        yield 'phrase across a hyphen and case' => ['"rss reader"', 'My new RSS-Reader', true];
        yield 'phrase across a run of spaces' => ['"rss reader"', 'rss   reader', true];
        yield 'phrase words out of order' => ['"rss reader"', 'reader for rss', false];
        yield 'phrase inner word is whole' => ['"rss reader"', 'rssx reader', false];
        yield 'either side of an or' => ['rss || atom', 'Atom feeds', true];
        yield 'neither side of an or' => ['rss || atom', 'JSON feeds', false];
        yield 'and binds tighter than or, left' => ['rss reader || atom', 'rss reader', true];
        yield 'and binds tighter than or, right' => ['rss reader || atom', 'atom', true];
        yield 'and binds tighter than or, split' => ['rss reader || atom', 'rss', false];
        yield 'excluded word present' => ['rss -gazette', 'RSS in the Gazette', false];
        yield 'excluded word absent' => ['rss -gazette', 'RSS in the paper', true];
        yield 'excluded phrase present' => ['rss -"daily news"', 'rss daily-news', false];
        yield 'excluded phrase words apart' => ['rss -"daily news"', 'rss news daily', true];
        yield 'group with exclusion, excluded' => [
            '("rss reader" || feedreader) -gazette',
            'The Gazette has an RSS reader',
            false,
        ];
        yield 'group with exclusion, kept' => ['("rss reader" || feedreader) -gazette', 'Try #FeedReader', true];
        yield 'excluded group' => ['rss -(daily news)', 'rss daily news', false];
        yield 'word matches as a prefix' => ['reader', 'Readers love it', true];
        yield 'word matches after a hash' => ['rssreader', 'Best #RSSReader', true];
        yield 'phrase last word matches as a prefix' => ['"rss read"', 'rss reader', true];
        yield 'no match inside a word' => ['ssreader', 'rssreader', false];
        yield 'no match after a digit' => ['rss', '2rss', false];
        yield 'a filter token is not checked' => ['from:alice rss', 'rss news', true];
        yield 'an excluded filter token is not checked' => ['rss -lang:de', 'rss news', true];
        yield 'only a filter token' => ['from:alice', 'anything', true];
        yield 'unicode case' => ['über', 'Über alles', true];
        yield 'unicode letter is no boundary' => ['ber', 'Über', false];
        yield 'unicode word after punctuation' => ['über', '(über)', true];
        yield 'unclosed quote is a phrase to the end' => ['"rss reader', 'an rss reader', true];
        yield 'unclosed quote still checks' => ['"rss reader', 'reader rss', false];
        yield 'stray closing parenthesis ignored' => ['rss ) reader', 'rss reader', true];
        yield 'stray closing parenthesis still checks' => ['rss ) reader', 'rss', false];
        yield 'unclosed group' => ['(rss || atom', 'atom', true];
        yield 'lone minus' => ['rss -', 'rss', true];
        yield 'lone or' => ['||', 'anything', true];
        yield 'trailing or' => ['rss ||', 'atom', false];
        yield 'empty query' => ['', 'anything', true];
        yield 'empty group' => ['rss ()', 'rss', true];
        yield 'word with inner punctuation is a phrase' => ['e-mail', 'send an E Mail', true];
        yield 'punctuation alone is no term' => ['rss &', 'rss', true];
    }

    #[DataProvider('verdicts')]
    public function testAPostMatchesWhenItsTextSatisfiesTheQuery(string $query, string $text, bool $matches): void
    {
        self::assertSame($matches, SocialSearchMatch::matcher($query)($text));
    }

    public function testOneMatcherJudgesManyTexts(): void
    {
        $matches = SocialSearchMatch::matcher('rss -gazette');

        self::assertSame(
            [true, false, false],
            [$matches('rss news'), $matches('atom news'), $matches('rss gazette')],
        );
    }
}
