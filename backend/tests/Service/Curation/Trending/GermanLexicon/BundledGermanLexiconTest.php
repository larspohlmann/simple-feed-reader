<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class BundledGermanLexiconTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/german-lexicon-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->temporaryDirectory . '/*') ?: []);
        rmdir($this->temporaryDirectory);
    }

    public function testGivesTheKindAndFrequencyClassOfAKnownForm(): void
    {
        $police = $this->fixtureLexicon()->findEntry('polizei');
        $large = $this->fixtureLexicon()->findEntry('groß');

        $this->assertNotNull($police);
        $this->assertTrue($police->isNoun());
        $this->assertSame(9, $police->frequencyClass);
        $this->assertNotNull($large);
        $this->assertFalse($large->isNoun());
        $this->assertSame(4, $large->frequencyClass);
    }

    public function testGivesNoEntryForAnUnknownForm(): void
    {
        $this->assertNull($this->fixtureLexicon()->findEntry('nirgendwo'));
    }

    public function testLoadsOnFirstUseAndAgainAfterReset(): void
    {
        $this->writeLexicon('eins');
        $lexicon = new BundledGermanLexicon($this->temporaryDirectory);
        $this->assertNotNull($lexicon->findEntry('eins'));

        $this->writeLexicon('zwei');
        $this->assertNull($lexicon->findEntry('zwei'));

        $lexicon->reset();
        $this->assertNotNull($lexicon->findEntry('zwei'));
    }

    public function testRejectsAMissingTextFile(): void
    {
        $this->writeLexicon('eins');
        unlink($this->temporaryDirectory . '/lexicon.tsv');

        $this->expectException(\UnexpectedValueException::class);

        @(new BundledGermanLexicon($this->temporaryDirectory))->findEntry('eins');
    }

    private function fixtureLexicon(): BundledGermanLexicon
    {
        return TrendingWiring::germanLexicon();
    }

    private function writeLexicon(string $noun): void
    {
        file_put_contents($this->temporaryDirectory . '/lexicon.tsv', $noun . "\tn\t1\n");
    }
}
