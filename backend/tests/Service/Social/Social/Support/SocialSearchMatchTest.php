<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Support\SocialSearchMatch;
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
        yield 'a filter token is not checked' => ['lang:de rss', 'rss news', true];
        yield 'an excluded filter token is not checked' => ['rss -lang:de', 'rss news', true];
        yield 'only a filter token' => ['lang:de', 'anything', true];
        yield 'an excluded group with a filter token keeps a match' => ['-(lang:de rss)', 'rss by carol', true];
        yield 'an excluded nested group with a filter token keeps a match' => [
            '-((lang:de rss) news)',
            'rss news',
            true,
        ];
        yield 'an excluded alternative with a filter token keeps a match' => [
            '-(rss || (lang:de news))',
            'news',
            true,
        ];
        yield 'an alternative with a filter token still checks its words' => ['rss || (lang:de news)', 'atom', false];
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
        self::assertSame($matches, SocialSearchMatch::matcher($query)(self::byCarol($text)));
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function authorVerdicts(): iterable
    {
        $hamburgNews = ['hamburgnews.bsky.social', 'did:plc:hamburgnews'];
        $alice = ['alice.bsky.social', 'did:plc:alice'];
        $carol = ['carol.bsky.social', 'did:plc:carol'];
        yield 'an excluded author' => ['rss -from:hamburgnews.bsky.social', $hamburgNews, false];
        yield 'another author than the excluded one' => ['rss -from:hamburgnews.bsky.social', $carol, true];
        yield 'the author, with an at sign and in another case' => ['rss from:@Alice.bsky.social', $alice, true];
        yield 'another author than the one asked for' => ['rss from:@Alice.bsky.social', $carol, false];
        yield 'the author by their DID' => ['rss from:did:plc:alice', $alice, true];
        yield 'another author than the DID asked for' => ['rss from:did:plc:alice', $carol, false];
        yield 'an excluded group naming the author' => ['-(from:carol.bsky.social rss)', $carol, false];
        yield 'the searching account is not checked' => ['rss from:me', $carol, true];
        yield 'an at sign alone is not checked' => ['rss from:@', $carol, true];
    }

    /** @param list<string> $authorNames */
    #[DataProvider('authorVerdicts')]
    public function testAFromFilterIsCheckedAgainstTheAuthor(string $query, array $authorNames, bool $matches): void
    {
        self::assertSame(
            $matches,
            SocialSearchMatch::matcher($query)(new SearchedPostModel($authorNames, ['rss news'])),
        );
    }

    public function testOneMatcherJudgesManyPosts(): void
    {
        $matches = SocialSearchMatch::matcher('rss -gazette');

        self::assertSame([true, false, false], array_map(
            static fn (string $text): bool => $matches(self::byCarol($text)),
            ['rss news', 'atom news', 'rss gazette'],
        ));
    }

    private static function byCarol(string $text): SearchedPostModel
    {
        return new SearchedPostModel(['carol.bsky.social'], [$text]);
    }
}
