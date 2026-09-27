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

    /** @return array<string, mixed> */
    private function row(int $entryId): array
    {
        return [
            'entryId' => $entryId,
            'feedId' => 1,
            'feedTitle' => 'Example Feed',
            'title' => 1 === $entryId ? 'An article' : 'Article ' . $entryId,
            'sourceUrl' => 'https://example.com/article-' . $entryId,
            'readerLink' => 'http://localhost:4200/?subscription=1&entry=' . $entryId,
            'extracted' => true,
            'markers' => [['code' => 'leading_link_list', 'weight' => 3, 'suspect' => 'cleaner', 'detail' => 'x']],
            'metrics' => [],
        ];
    }

    private function findingsFile(): string
    {
        return $this->writeFindings([$this->row(1)]);
    }

    private function threeFlaggedFindingsFile(): string
    {
        return $this->writeFindings(array_map($this->row(...), range(1, 3)));
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeFindings(array $rows): string
    {
        $path = sys_get_temp_dir() . '/reader-audit-report-' . uniqid('', true) . '.jsonl';
        $lines = '';
        foreach ($rows as $row) {
            $lines .= json_encode($row, \JSON_THROW_ON_ERROR) . "\n";
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

    private function tickingClock(): TickingClock
    {
        return new TickingClock(new \DateTimeImmutable('2026-02-03T04:05:00Z'), 0);
    }

    public function testTheReportIsWrittenWithTheClockedTimestampAndTheChosenTopCount(): void
    {
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($this->tickingClock()));
        $exitCode = $tester->execute(['--in' => $this->findingsFile(), '--out' => $out, '--top' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $html = file_get_contents($out) ?: '';
        self::assertStringContainsString('2026-02-03 04:05', $html);
        self::assertStringContainsString('Candidates (1 worst of 1 flagged)', $html);
    }

    public function testAMalformedTopIsRefusedBeforeTheReportIsWritten(): void
    {
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($this->tickingClock()));

        try {
            $tester->execute(['--in' => $this->findingsFile(), '--out' => $out, '--top' => 'abc']);
            self::fail('A malformed --top must be refused, not read as absent.');
        } catch (MalformedOptionException) {
            self::assertFileDoesNotExist($out);
        }
    }

    public function testABlankTopOptionFallsBackToZeroCandidatesRatherThanOneOrAllButOne(): void
    {
        $out = $this->outputPath();

        $tester = new CommandTester(new ReaderAuditReportCommand($this->tickingClock()));
        $exitCode = $tester->execute(['--in' => $this->threeFlaggedFindingsFile(), '--out' => $out, '--top' => ' ']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $html = file_get_contents($out) ?: '';
        self::assertStringContainsString('Candidates (0 worst of 3 flagged)', $html);
    }

    public function testABlankInOptionFallsBackToAnEmptyPatternInsteadOfATypeError(): void
    {
        $tester = new CommandTester(new ReaderAuditReportCommand($this->tickingClock()));
        $exitCode = $tester->execute(['--in' => ' ', '--out' => $this->outputPath()]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No sweep files match', $tester->getDisplay());
    }
}
