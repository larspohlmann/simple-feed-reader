<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Llm\Prompt;

use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Llm\Prompt\InterestTermParser;
use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;
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

        self::assertTrue($result->usable);
        self::assertEquals([new InterestTermModel(FeedLanguage::German, 'Wärmepumpe', 0.7)], $result->terms);
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

        self::assertFalse($dropped->usable);
        self::assertEquals([new InterestTermModel(FeedLanguage::German, 'Pumpe', 0.5)], $kept->terms);
    }

    public function testWeightsAreClampedToOneToTen(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"en","term":"low","weight":0},{"language":"en","term":"high","weight":42}]}',
            [FeedLanguage::English],
        );

        self::assertEquals(
            [
                new InterestTermModel(FeedLanguage::English, 'low', 0.1),
                new InterestTermModel(FeedLanguage::English, 'high', 1.0),
            ],
            $result->terms,
        );
    }

    public function testANumericStringWeightIsRead(): void
    {
        $result = $this->parser->parse(
            '{"terms":[{"language":"en","term":"seven","weight":"7"},{"language":"en","term":"word","weight":"high"}]}',
            [FeedLanguage::English],
        );

        self::assertEquals([new InterestTermModel(FeedLanguage::English, 'seven', 0.7)], $result->terms);
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

        self::assertEquals([new InterestTermModel(FeedLanguage::English, str_repeat('b', 80), 0.5)], $result->terms);
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
            $result->terms,
            static fn (InterestTermModel $term): bool => FeedLanguage::German === $term->language,
        ));
        self::assertCount(60, $german);
        self::assertSame('term1', $german[0]->phrase);
        self::assertSame('term60', $german[59]->phrase);
        self::assertCount(61, $result->terms);
    }

    public function testAReplyThatIsNotJsonOrHasNoTermsListIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('not json', [FeedLanguage::English])->usable);
        self::assertFalse($this->parser->parse('{"nope":1}', [FeedLanguage::English])->usable);
        self::assertFalse($this->parser->parse('{"terms":"x"}', [FeedLanguage::English])->usable);
    }

    public function testAReplyWhoseTermsAllDropIsUnusable(): void
    {
        $result = $this->parser->parse('{"terms":[{"language":"en","term":"","weight":5}]}', [FeedLanguage::English]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->terms);
    }
}
