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
            new StoryHeadingModel('Hamburg port strike halts container traffic', null),
        ];
        yield 'a code fence is tolerated' => [
            "```json\n{\"title\":\"Streik im Hafen\"}\n```",
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'a blank title' => ['{"title":"  "}', null];
        yield 'no title key' => ['{"headline":"x"}', null];
        yield 'not JSON' => ['not json', null];
        yield 'a title that is no string' => ['{"title":42}', null];
        yield 'a title over 120 characters' => ['{"title":"' . str_repeat('a', 121) . '"}', null];
        yield 'a title of 120 characters' => [
            '{"title":"' . str_repeat('ä', 120) . '"}',
            new StoryHeadingModel(str_repeat('ä', 120), null),
        ];
        yield 'German quotation marks are stripped' => [
            '{"title":"„Streik im Hafen“"}',
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'a full stop after the quotes' => [
            '{"title":"„Streik im Hafen“."}',
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'straight quotes are stripped' => [
            '{"title":"\"Streik im Hafen\""}',
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'surrounding space is trimmed' => [
            '{"title":"  Streik im Hafen  "}',
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'trailing marks are dropped together' => [
            '{"title":"Streik im Hafen?! …"}',
            new StoryHeadingModel('Streik im Hafen', null),
        ];
        yield 'only punctuation' => ['{"title":"…"}', null];
        yield 'an inner quote stays' => [
            '{"title":"Merz nennt Streik \"unnötig\""}',
            new StoryHeadingModel('Merz nennt Streik "unnötig"', null),
        ];
        yield 'a synopsis is kept' => [
            '{"title":"Harbour barrier approved","synopsis":"The authority approved it. Work starts in May."}',
            new StoryHeadingModel('Harbour barrier approved', 'The authority approved it. Work starts in May.'),
        ];
        yield 'a synopsis has its whitespace collapsed and trimmed' => [
            "{\"title\":\"T\",\"synopsis\":\"  Two\\n sentences.  \"}",
            new StoryHeadingModel('T', 'Two sentences.'),
        ];
        yield 'an empty synopsis keeps the title' => ['{"title":"T","synopsis":""}', new StoryHeadingModel('T', null)];
        yield 'a blank synopsis keeps the title' => [
            '{"title":"T","synopsis":"  "}',
            new StoryHeadingModel('T', null),
        ];
        yield 'a missing synopsis keeps the title' => ['{"title":"T"}', new StoryHeadingModel('T', null)];
        yield 'a synopsis that is no string keeps the title' => [
            '{"title":"T","synopsis":7}',
            new StoryHeadingModel('T', null),
        ];
        yield 'a synopsis of 500 characters is kept' => [
            '{"title":"T","synopsis":"' . str_repeat('ä', 500) . '"}',
            new StoryHeadingModel('T', str_repeat('ä', 500)),
        ];
        yield 'a synopsis of 501 characters keeps the title' => [
            '{"title":"T","synopsis":"' . str_repeat('ä', 501) . '"}',
            new StoryHeadingModel('T', null),
        ];
    }

    #[DataProvider('replies')]
    public function testParse(string $content, ?StoryHeadingModel $expected): void
    {
        self::assertEquals($expected, (new StoryTitleParser(new ModelReplyJsonDecoder()))->parse($content));
    }
}
