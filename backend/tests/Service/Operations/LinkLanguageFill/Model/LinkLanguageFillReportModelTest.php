<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\LinkLanguageFill\Model;

use App\Enum\FeedLanguage;
use App\Service\Operations\LinkLanguageFill\Model\LinkLanguageFillReportModel;
use PHPUnit\Framework\TestCase;

final class LinkLanguageFillReportModelTest extends TestCase
{
    public function testFillsCountPerLanguageInCodeOrder(): void
    {
        $report = (new LinkLanguageFillReportModel())
            ->filled(FeedLanguage::French)
            ->filled(FeedLanguage::German)
            ->filled(FeedLanguage::French)
            ->leftShort();

        self::assertSame(['de' => 1, 'fr' => 2], $report->filledByLanguage);
        self::assertSame(3, $report->filledCount());
        self::assertSame(2, $report->filledIn(FeedLanguage::French));
        self::assertSame(0, $report->filledIn(FeedLanguage::Spanish));
        self::assertSame(1, $report->leftWithout);
    }

    public function testTwoReportsAddUp(): void
    {
        $sum = new LinkLanguageFillReportModel(['fr' => 1, 'es' => 2], 3)
            ->plus(new LinkLanguageFillReportModel(['de' => 4, 'fr' => 5], 6));

        self::assertSame(['de' => 4, 'es' => 2, 'fr' => 6], $sum->filledByLanguage);
        self::assertSame(9, $sum->leftWithout);
    }
}
