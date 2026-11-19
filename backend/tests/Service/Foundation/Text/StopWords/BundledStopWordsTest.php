<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\StopWords;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\StopWords\BundledStopWords;
use PHPUnit\Framework\TestCase;

final class BundledStopWordsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/stopwords-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testItReturnsTheListOfTheAskedLanguage(): void
    {
        $stopWords = $this->stopWordsReturning("['de' => ['und'], 'en' => ['the'], 'fr' => ['les'], 'es' => ['los']]");

        $this->assertSame(['the'], $stopWords->listWords(FeedLanguage::English));
        $this->assertSame(['und'], $stopWords->listWords(FeedLanguage::German));
    }

    public function testItLoadsOnFirstUseAndAgainAfterReset(): void
    {
        $stopWords = $this->stopWordsReturning("['de' => ['und']]");
        $first = $stopWords->listWords(FeedLanguage::German);
        $this->stopWordsReturning("['de' => ['oder']]");
        $beforeReset = $stopWords->listWords(FeedLanguage::German);
        $stopWords->reset();
        $afterReset = $stopWords->listWords(FeedLanguage::German);

        $this->assertSame(['und'], $first);
        $this->assertSame(['und'], $beforeReset);
        $this->assertSame(['oder'], $afterReset);
    }

    public function testAFileOfTheWrongShapeIsRefused(): void
    {
        $stopWords = $this->stopWordsReturning("['de' => 'und']");

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($this->path);

        $stopWords->listWords(FeedLanguage::German);
    }

    public function testAListHoldingANonStringIsRefused(): void
    {
        $stopWords = $this->stopWordsReturning("['de' => ['und', 7]]");

        $this->expectException(\UnexpectedValueException::class);

        $stopWords->listWords(FeedLanguage::German);
    }

    public function testAFileReturningNoArrayIsRefused(): void
    {
        $stopWords = $this->stopWordsReturning("'und'");

        $this->expectException(\UnexpectedValueException::class);

        $stopWords->listWords(FeedLanguage::German);
    }

    public function testAFileMissingALanguageIsRefused(): void
    {
        $stopWords = $this->stopWordsReturning("['de' => ['und']]");

        $this->expectException(\UnexpectedValueException::class);

        $stopWords->listWords(FeedLanguage::French);
    }

    private function stopWordsReturning(string $phpValue): BundledStopWords
    {
        file_put_contents($this->path, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $phpValue . ";\n");

        return new BundledStopWords($this->path);
    }
}
