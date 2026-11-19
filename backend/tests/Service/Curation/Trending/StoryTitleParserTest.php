<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
use App\Service\Curation\Trending\StoryTitleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoryTitleParserTest extends TestCase
{
    /** @return iterable<string, array{string, ?StoryHeadingModel}> */
    public static function replies(): iterable
    {
        yield 'a trailing full stop is dropped' => [
            '{"title":"Hamburg port strike halts container traffic."}',
            self::heading('Hamburg port strike halts container traffic'),
        ];
        yield 'a code fence is tolerated' => [
            "```json\n{\"title\":\"Streik im Hafen\"}\n```",
            self::heading('Streik im Hafen'),
        ];
        yield 'a blank title' => ['{"title":"  "}', null];
        yield 'no title key' => ['{"headline":"x"}', null];
        yield 'not JSON' => ['not json', null];
        yield 'a title that is no string' => ['{"title":42}', null];
        yield 'a title over 120 characters' => ['{"title":"' . str_repeat('a', 121) . '"}', null];
        yield 'a title of 120 characters' => [
            '{"title":"' . str_repeat('ä', 120) . '"}',
            self::heading(str_repeat('ä', 120), null),
        ];
        yield 'German quotation marks are stripped' => [
            '{"title":"„Streik im Hafen“"}',
            self::heading('Streik im Hafen'),
        ];
        yield 'a full stop after the quotes' => [
            '{"title":"„Streik im Hafen“."}',
            self::heading('Streik im Hafen'),
        ];
        yield 'straight quotes are stripped' => [
            '{"title":"\"Streik im Hafen\""}',
            self::heading('Streik im Hafen'),
        ];
        yield 'surrounding space is trimmed' => [
            '{"title":"  Streik im Hafen  "}',
            self::heading('Streik im Hafen'),
        ];
        yield 'trailing marks are dropped together' => [
            '{"title":"Streik im Hafen?! …"}',
            self::heading('Streik im Hafen'),
        ];
        yield 'only punctuation' => ['{"title":"…"}', null];
        yield 'an inner quote stays' => [
            '{"title":"Merz nennt Streik \"unnötig\""}',
            self::heading('Merz nennt Streik "unnötig"'),
        ];
        yield 'a synopsis is kept' => [
            '{"title":"Harbour barrier approved","synopsis":"The authority approved it. Work starts in May."}',
            self::heading('Harbour barrier approved', 'The authority approved it. Work starts in May.'),
        ];
        yield 'a synopsis has its whitespace collapsed and trimmed' => [
            "{\"title\":\"T\",\"synopsis\":\"  Two\\n sentences.  \"}",
            self::heading('T', 'Two sentences.'),
        ];
        yield 'an empty synopsis keeps the title' => ['{"title":"T","synopsis":""}', self::heading('T')];
        yield 'a blank synopsis keeps the title' => ['{"title":"T","synopsis":"  "}', self::heading('T')];
        yield 'a missing synopsis keeps the title' => ['{"title":"T"}', self::heading('T')];
        yield 'a synopsis that is no string keeps the title' => [
            '{"title":"T","synopsis":7}',
            self::heading('T'),
        ];
        yield 'a synopsis of 500 characters is kept' => [
            '{"title":"T","synopsis":"' . str_repeat('ä', 500) . '"}',
            self::heading('T', str_repeat('ä', 500)),
        ];
        yield 'a synopsis of 501 characters keeps the title' => [
            '{"title":"T","synopsis":"' . str_repeat('ä', 501) . '"}',
            self::heading('T'),
        ];
    }

    private static function heading(string $title, ?string $synopsis = null): StoryHeadingModel
    {
        return new StoryHeadingModel($title, $synopsis);
    }

    #[DataProvider('replies')]
    public function testParse(string $content, ?StoryHeadingModel $expected): void
    {
        self::assertEquals($expected, (new StoryTitleParser(new ModelReplyJsonDecoder()))->parse($content));
    }
}
