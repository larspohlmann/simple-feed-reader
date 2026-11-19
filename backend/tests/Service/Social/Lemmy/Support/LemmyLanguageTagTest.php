<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Lemmy\Support\LemmyLanguageTag;
use PHPUnit\Framework\TestCase;

final class LemmyLanguageTagTest extends TestCase
{
    private const string SITE_LANGUAGES = __DIR__ . '/../../../../Fixtures/lemmy/site-languages.json';

    public function testTheFourLanguagesAreTheIdsAnInstanceReports(): void
    {
        $idsByCode = self::idsByCode();

        foreach (['de', 'en', 'fr', 'es'] as $code) {
            self::assertSame($code, LemmyLanguageTag::of($idsByCode[$code]));
        }
    }

    public function testUndeterminedAndAbsentStateNoLanguage(): void
    {
        self::assertNull(LemmyLanguageTag::of(0));
        self::assertNull(LemmyLanguageTag::of(null));
    }

    public function testAnyOtherLanguageIsStatedButNotOneOfTheFour(): void
    {
        $portuguese = self::idsByCode()['pt'];

        self::assertSame('x-lemmy-' . $portuguese, LemmyLanguageTag::of($portuguese));
    }

    /** @return array<string, int> the language id of each code */
    private static function idsByCode(): array
    {
        $site = JsonNodeModel::of(json_decode((string) file_get_contents(self::SITE_LANGUAGES), true));
        $idsByCode = [];
        foreach ($site->nodes('all_languages') as $language) {
            $idsByCode[(string) $language->string('code')] = (int) $language->int('id');
        }

        return $idsByCode;
    }
}
