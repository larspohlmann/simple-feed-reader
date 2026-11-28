<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream\Model;

use App\Service\Curation\Recommendation\Stream\Model\ExtractedTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use PHPUnit\Framework\TestCase;

final class InterestTermOutcomeModelTest extends TestCase
{
    public function testAnUnusableOutcomeHasNoExtractedTermsToRequire(): void
    {
        $this->expectException(\LogicException::class);

        InterestTermOutcomeModel::unusable('x')->requireExtracted();
    }

    public function testAUsableOutcomeReturnsItsExtractedTerms(): void
    {
        $extracted = new ExtractedTermsModel([], []);

        self::assertSame($extracted, InterestTermOutcomeModel::usable($extracted)->requireExtracted());
    }
}
