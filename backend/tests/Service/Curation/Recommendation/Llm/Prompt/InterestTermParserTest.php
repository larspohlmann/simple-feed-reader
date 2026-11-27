<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Llm\Prompt;

use App\Entity\InterestPhrase;
use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Llm\Prompt\InterestTermParser;
use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use PHPUnit\Framework\TestCase;

final class InterestTermParserTest extends TestCase
{
    private InterestTermParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InterestTermParser(new ModelReplyJsonDecoder());
    }

    public function testATermInARequestedLanguageIsKeptWithItsWeightOverTen(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"de","term":"Wärmepumpe","weight":7}]}',
            [FeedLanguage::German],
        );

        self::assertEquals([new InterestPhrase(FeedLanguage::German, 'Wärmepumpe', 0.7)], $result);
    }

    public function testATermInALanguageNotAskedForIsDropped(): void
    {
        $dropped = $this->parser->parse(
            '{"terms":[{"language":"fr","term":"pompe","weight":5}]}',
            [FeedLanguage::German],
        );
        $kept = $this->parser->parse(
            '{"terms":[{"language":"fr","term":"pompe","weight":5},{"language":"de","term":"Pumpe","weight":5}]}',
            [FeedLanguage::German],
        );

        self::assertSame([], $dropped);
        self::assertEquals([new InterestPhrase(FeedLanguage::German, 'Pumpe', 0.5)], $kept);
    }

    public function testWeightsAreClampedToOneToTen(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"en","term":"low","weight":0},{"language":"en","term":"high","weight":42}]}',
            [FeedLanguage::English],
        );

        self::assertEquals(
            [
                new InterestPhrase(FeedLanguage::English, 'low', 0.1),
                new InterestPhrase(FeedLanguage::English, 'high', 1.0),
            ],
            $result,
        );
    }

    public function testANumericStringWeightIsRead(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"en","term":"seven","weight":"7"},{"language":"en","term":"word","weight":"high"}]}',
            [FeedLanguage::English],
        );

        self::assertEquals([new InterestPhrase(FeedLanguage::English, 'seven', 0.7)], $result);
    }

    public function testAnInfiniteOrHugeWeightClampsToTheTop(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"en","term":"infinite","weight":1e999},'
            . '{"language":"en","term":"huge","weight":1e30}]}',
            [FeedLanguage::English],
        );

        self::assertEquals(
            [
                new InterestPhrase(FeedLanguage::English, 'infinite', 1.0),
                new InterestPhrase(FeedLanguage::English, 'huge', 1.0),
            ],
            $result,
        );
    }

    public function testAMalformedItemOrFieldDropsOnlyThatTerm(): void
    {
        $reply = json_encode(['terms' => [
            'not an object',
            ['language' => 7, 'term' => 'numeric language', 'weight' => 5],
            ['language' => 'en', 'term' => 7, 'weight' => 5],
            ['language' => 'en', 'term' => 'bool weight', 'weight' => true],
            ['language' => 'en', 'term' => 'null weight', 'weight' => null],
            ['language' => 'en', 'term' => 'missing weight'],
            ['language' => 'en', 'term' => 'kept', 'weight' => 5],
        ]], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($reply, [FeedLanguage::English]);

        self::assertEquals([new InterestPhrase(FeedLanguage::English, 'kept', 0.5)], $result);
    }

    public function testEmptyAndOverlongTermsAreDropped(): void
    {
        $reply = json_encode(['terms' => [
            ['language' => 'en', 'term' => '', 'weight' => 5],
            ['language' => 'en', 'term' => '  ', 'weight' => 5],
            ['language' => 'en', 'term' => str_repeat('a', 81), 'weight' => 5],
            ['language' => 'en', 'term' => str_repeat('b', 80), 'weight' => 5],
        ]], JSON_THROW_ON_ERROR);

        $result = $this->parser->parse($reply, [FeedLanguage::English]);

        self::assertEquals([new InterestPhrase(FeedLanguage::English, str_repeat('b', 80), 0.5)], $result);
    }

    public function testEachLanguageKeepsItsFirstSixtyTerms(): void
    {
        $terms = [];
        for ($index = 1; $index <= 80; ++$index) {
            $terms[] = ['language' => 'de', 'term' => 'term' . $index, 'weight' => 5];
        }
        $terms[] = ['language' => 'en', 'term' => 'other', 'weight' => 5];

        $result = $this->parser->parse(
            json_encode(['terms' => $terms], JSON_THROW_ON_ERROR),
            [FeedLanguage::German, FeedLanguage::English],
        );

        $german = array_values(array_filter(
            $result,
            static fn (InterestPhrase $term): bool => FeedLanguage::German === $term->language,
        ));
        self::assertCount(60, $german);
        self::assertSame('term1', $german[0]->phrase);
        self::assertSame('term60', $german[59]->phrase);
        self::assertCount(61, $result);
    }

    public function testAReplyThatIsNotJsonOrHasNoTermsListIsUnusable(): void
    {
        self::assertSame([], $this->parser->parse('not json', [FeedLanguage::English]));
        self::assertSame([], $this->parser->parse('{"nope":1}', [FeedLanguage::English]));
        self::assertSame([], $this->parser->parse('{"terms":"x"}', [FeedLanguage::English]));
    }

    public function testAReplyWhoseTermsAllDropIsUnusable(): void
    {
        $result = $this->parser->parse('{"terms":[{"language":"en","term":"","weight":5}]}', [FeedLanguage::English]);

        self::assertSame([], $result);
    }
}
