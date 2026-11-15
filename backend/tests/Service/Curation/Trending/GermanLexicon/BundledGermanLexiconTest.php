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

    public function testRecognisesNounsAndOtherKnownForms(): void
    {
        $lexicon = $this->fixtureLexicon();

        $this->assertTrue($lexicon->isNoun('polizei'));
        $this->assertFalse($lexicon->isNoun('groß'));
        $this->assertTrue($lexicon->isKnown('groß'));
        $this->assertFalse($lexicon->isKnown('nirgendwo'));
    }

    public function testFrequencyClassOfAnUnknownFormIsOneBeyondTheRarest(): void
    {
        $lexicon = $this->fixtureLexicon();

        $this->assertSame(14, $lexicon->frequencyClassOf('akt'));
        $this->assertSame(4, $lexicon->frequencyClassOf('groß'));
        $this->assertSame(18, $lexicon->frequencyClassOf('nirgendwo'));
    }

    public function testLoadsOnFirstUseAndAgainAfterReset(): void
    {
        $this->writeLexicon('eins');
        $lexicon = new BundledGermanLexicon($this->temporaryDirectory);
        $this->assertTrue($lexicon->isNoun('eins'));

        $this->writeLexicon('zwei');
        $this->assertFalse($lexicon->isNoun('zwei'));

        $lexicon->reset();
        $this->assertTrue($lexicon->isNoun('zwei'));
    }

    public function testRejectsAMetaFileOfTheWrongShape(): void
    {
        $this->writeLexicon('eins');
        file_put_contents($this->temporaryDirectory . '/lexicon-meta.php', "<?php return ['rarest' => 'x'];");

        $this->expectException(\UnexpectedValueException::class);

        (new BundledGermanLexicon($this->temporaryDirectory))->isNoun('eins');
    }

    public function testRejectsAMetaFileThatReturnsNoArray(): void
    {
        $this->writeLexicon('eins');
        file_put_contents($this->temporaryDirectory . '/lexicon-meta.php', '<?php return 1;');

        $this->expectException(\UnexpectedValueException::class);

        (new BundledGermanLexicon($this->temporaryDirectory))->isNoun('eins');
    }

    public function testRejectsAMissingTextFile(): void
    {
        $this->writeLexicon('eins');
        unlink($this->temporaryDirectory . '/lexicon.tsv');

        $this->expectException(\UnexpectedValueException::class);

        @(new BundledGermanLexicon($this->temporaryDirectory))->isNoun('eins');
    }

    private function fixtureLexicon(): BundledGermanLexicon
    {
        return TrendingWiring::germanLexicon();
    }

    private function writeLexicon(string $noun): void
    {
        file_put_contents($this->temporaryDirectory . '/lexicon.tsv', $noun . "\tn\t1\n");
        file_put_contents(
            $this->temporaryDirectory . '/lexicon-meta.php',
            "<?php return ['rarest' => 1];",
        );
    }
}
