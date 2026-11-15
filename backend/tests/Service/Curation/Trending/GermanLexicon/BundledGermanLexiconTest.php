<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Exception\UnknownVerbStemException;
use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use PHPUnit\Framework\TestCase;

final class BundledGermanLexiconTest extends TestCase
{
    private string $temporaryPath;

    protected function setUp(): void
    {
        $this->temporaryPath = sys_get_temp_dir() . '/german-lexicon-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->temporaryPath)) {
            unlink($this->temporaryPath);
        }
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

    public function testMedianNounClassOfALength(): void
    {
        $lexicon = $this->fixtureLexicon();

        $this->assertSame(10, $lexicon->medianNounClassOfLength(4));
        $this->assertSame(12, $lexicon->medianNounClassOfLength(8));
        $this->assertSame(18, $lexicon->medianNounClassOfLength(40));
    }

    public function testResolvesVerbStems(): void
    {
        $lexicon = $this->fixtureLexicon();

        $this->assertTrue($lexicon->isVerbStem('verhandel'));
        $this->assertFalse($lexicon->isVerbStem('laufen'));
        $this->assertSame('verhandeln', $lexicon->infinitiveStemOf('verhandel'));
    }

    public function testRejectsAnInfinitiveStemRequestForANonVerbStem(): void
    {
        $this->expectException(UnknownVerbStemException::class);

        $this->fixtureLexicon()->infinitiveStemOf('laufen');
    }

    public function testLoadsOnFirstUseAndAgainAfterReset(): void
    {
        $this->writeLexicon('eins');
        $lexicon = new BundledGermanLexicon($this->temporaryPath);
        $this->assertTrue($lexicon->isNoun('eins'));

        $this->writeLexicon('zwei');
        $this->assertFalse($lexicon->isNoun('zwei'));

        $lexicon->reset();
        $this->assertTrue($lexicon->isNoun('zwei'));
    }

    public function testRejectsAFileOfTheWrongShape(): void
    {
        file_put_contents($this->temporaryPath, "<?php return ['nouns' => 'x'];");

        $this->expectException(\UnexpectedValueException::class);

        (new BundledGermanLexicon($this->temporaryPath))->isNoun('eins');
    }

    public function testRejectsAFileWhoseValuesHaveTheWrongType(): void
    {
        file_put_contents(
            $this->temporaryPath,
            "<?php return ['nouns' => ['eins' => 'x'], 'others' => [], 'verbs' => []];",
        );

        $this->expectException(\UnexpectedValueException::class);

        (new BundledGermanLexicon($this->temporaryPath))->isNoun('eins');
    }

    public function testRejectsAFileThatReturnsNoArray(): void
    {
        file_put_contents($this->temporaryPath, '<?php return 1;');

        $this->expectException(\UnexpectedValueException::class);

        (new BundledGermanLexicon($this->temporaryPath))->isNoun('eins');
    }

    private function fixtureLexicon(): BundledGermanLexicon
    {
        return new BundledGermanLexicon(dirname(__DIR__, 4) . '/Fixtures/german-lexicon/lexicon.php');
    }

    private function writeLexicon(string $noun): void
    {
        file_put_contents(
            $this->temporaryPath,
            sprintf("<?php return ['nouns' => ['%s' => 1], 'others' => [], 'verbs' => []];", $noun),
        );
    }
}
