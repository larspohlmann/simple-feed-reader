<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationProfileParser;
use PHPUnit\Framework\TestCase;

final class RecommendationProfileParserTest extends TestCase
{
    private RecommendationProfileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationProfileParser(new ModelReplyJsonDecoder());
    }

    public function testParsesAProfileString(): void
    {
        $result = $this->parser->parse('{"profile":"Likes maps and Rust."}');

        self::assertTrue($result->usable);
        self::assertSame('Likes maps and Rust.', $result->profile);
    }

    public function testRejectsMissingOrEmptyProfile(): void
    {
        self::assertFalse($this->parser->parse('{"profile":""}')->usable);
        self::assertFalse($this->parser->parse('not json')->usable);
        self::assertFalse($this->parser->parse('{"nope":1}')->usable);
    }

    /** A profile of only whitespace carries nothing usable, the same as an empty one. */
    public function testRejectsAWhitespaceOnlyProfile(): void
    {
        self::assertFalse($this->parser->parse('{"profile":"   "}')->usable);
    }

    /** A profile that is present but the wrong JSON type is not silently coerced. */
    public function testRejectsANonStringProfile(): void
    {
        self::assertFalse($this->parser->parse('{"profile":42}')->usable);
    }

    /** An unusable result carries no profile: nothing downstream should read it. */
    public function testAnUnusableResultCarriesNoProfile(): void
    {
        self::assertNull($this->parser->parse('not json')->profile);
    }
}
