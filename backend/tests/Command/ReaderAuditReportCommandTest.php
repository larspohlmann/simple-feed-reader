<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Exception\MalformedOptionException;
use App\Command\ReaderAuditReportCommand;
use App\Tests\Support\TickingClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReaderAuditReportCommandTest extends TestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $path) {
            @unlink($path);
        }
    }

    private function findingsFile(): string
    {
        $path = sys_get_temp_dir() . '/reader-audit-report-' . uniqid('', true) . '.jsonl';
        $row = [
            'entryId' => 1,
            'feedId' => 1,
            'feedTitle' => 'Example Feed',
            'title' => 'An article',
            'sourceUrl' => 'https://example.com/article',
            'readerLink' => 'http://localhost:4200/?subscription=1&entry=1-an-article',
            'extracted' => true,
            'markers' => [['code' => 'leading_link_list', 'weight' => 3, 'suspect' => 'cleaner', 'detail' => 'x']],
            'metrics' => [],
        ];
        file_put_contents($path, json_encode($row, \JSON_THROW_ON_ERROR) . "\n");
        $this->filesToDelete[] = $path;

        return $path;
    }

    private function threeFlaggedFindingsFile(): string
    {
        $path = sys_get_temp_dir() . '/reader-audit-report-' . uniqid('', true) . '.jsonl';
        $lines = '';
        for ($entryId = 1; $entryId <= 3; ++$entryId) {
            $lines .= json_encode([
                'entryId' => $entryId,
                'feedId' => 1,
                'feedTitle' => 'Example Feed',
                'title' => 'Article ' . $entryId,
                'sourceUrl' => 'https://example.com/article-' . $entryId,
                'readerLink' => 'http://localhost:4200/?subscription=1&entry=' . $entryId,
                'extracted' => true,
                'markers' => [
                    ['code' => 'leading_link_list', 'weight' => 3, 'suspect' => 'cleaner', 'detail' => 'x'],
                ],
                'metrics' => [],
            ], \JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $lines);
        $this->filesToDelete[] = $path;

        return $path;
    }

    private function outputPath(): string
    {
        $path = sys_get_temp_dir() . '/reader-audit-report-out-' . uniqid('', true) . '.html';
        $this->filesToDelete[] = $path;

        return $path;
    }

    public function testTheReportIsWrittenWithTheClockedTimestampAndTheChosenTopCount(): void
    {
        $clock = new TickingClock(new \DateTimeImmutable('2026-02-03T04:05:00Z'), 0);
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($clock));
        $exitCode = $tester->execute(['--in' => $this->findingsFile(), '--out' => $out, '--top' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $html = file_get_contents($out) ?: '';
        self::assertStringContainsString('2026-02-03 04:05', $html);
    }

    public function testAMalformedTopIsRefusedBeforeTheReportIsWritten(): void
    {
        $clock = new TickingClock(new \DateTimeImmutable('2026-02-03T04:05:00Z'), 0);
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($clock));

        try {
            $tester->execute(['--in' => $this->findingsFile(), '--out' => $out, '--top' => 'abc']);
            self::fail('A malformed --top must be refused, not read as absent.');
        } catch (MalformedOptionException) {
            self::assertFileDoesNotExist($out);
        }
    }

    public function testABlankTopOptionFallsBackToZeroCandidatesRatherThanOneOrAllButOne(): void
    {
        $clock = new TickingClock(new \DateTimeImmutable('2026-02-03T04:05:00Z'), 0);
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($clock));
        $exitCode = $tester->execute(['--in' => $this->threeFlaggedFindingsFile(), '--out' => $out, '--top' => ' ']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $html = file_get_contents($out) ?: '';
        self::assertStringContainsString('Candidates (0 worst of 3 flagged)', $html);
    }

    public function testABlankInOptionFallsBackToAnEmptyPatternInsteadOfATypeError(): void
    {
        $clock = new TickingClock(new \DateTimeImmutable('2026-02-03T04:05:00Z'), 0);

        $tester = new CommandTester(new ReaderAuditReportCommand($clock));
        $exitCode = $tester->execute(['--in' => ' ', '--out' => $this->outputPath()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No sweep files match', $tester->getDisplay());
    }
}
