<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\CompoundSplitModel;
use PHPUnit\Framework\TestCase;

final class CompoundSplitModelTest extends TestCase
{
    public function testFewerPartsBeatALowerMean(): void
    {
        $twoParts = CompoundSplitModel::single('rathaus', 12)->prependedBy(CompoundSplitModel::single('bund', 8));
        $threeParts = $this->split(['bund', 'rat', 'haus'], 1);

        $this->assertTrue($twoParts->beats($threeParts));
        $this->assertFalse($threeParts->beats($twoParts));
    }

    public function testALowerClassSumBeatsAtTheSameCount(): void
    {
        $frequent = $this->split(['stau', 'becken'], 8);
        $rare = $this->split(['staub', 'ecken'], 9);

        $this->assertTrue($frequent->beats($rare));
        $this->assertFalse($rare->beats($frequent));
    }

    public function testAnEqualSumGoesToTheLongerHead(): void
    {
        $longerHead = $this->split(['stau', 'becken'], 8);
        $shorterHead = $this->split(['staub', 'ecken'], 8);

        $this->assertTrue($longerHead->beats($shorterHead));
        $this->assertFalse($shorterHead->beats($longerHead));
        $this->assertFalse($longerHead->beats($longerHead));
    }

    public function testComparesItsMeanClassWithAWholeWord(): void
    {
        $split = $this->split(['rat', 'haus'], 8);

        $this->assertTrue($split->hasMeanClassOfAtLeast(8));
        $this->assertFalse($split->hasMeanClassOfAtLeast(9));
    }

    /** @param non-empty-list<string> $parts */
    private function split(array $parts, int $frequencyClass): CompoundSplitModel
    {
        $split = CompoundSplitModel::single(array_pop($parts), $frequencyClass);
        foreach (array_reverse($parts) as $part) {
            $split = $split->prependedBy(CompoundSplitModel::single($part, $frequencyClass));
        }

        return $split;
    }
}
