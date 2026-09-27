<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConsoleOption;
use App\Command\Exception\MalformedOptionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class ConsoleOptionTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function texts(): iterable
    {
        yield 'absent' => [null, null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'padded' => ['  var/out.jsonl ', 'var/out.jsonl'];
    }

    #[DataProvider('texts')]
    public function testTextIsTrimmedAndBlankReadsAsAbsent(?string $given, ?string $read): void
    {
        self::assertSame($read, ConsoleOption::text($this->input('value', $given), 'value'));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function numbers(): iterable
    {
        yield 'absent' => [null, null];
        yield 'zero' => ['0', 0];
        yield 'digits' => ['42', 42];
        yield 'padded digits' => [' 7 ', 7];
    }

    #[DataProvider('numbers')]
    public function testAWholeNumberIsReadTrimmed(?string $given, ?int $read): void
    {
        self::assertSame($read, ConsoleOption::wholeNumber($this->input('value', $given), 'value'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedNumbers(): iterable
    {
        yield 'negative' => ['-3'];
        yield 'partly digits' => ['10abc'];
        yield 'not a number' => ['abc'];
        yield 'a fraction' => ['1.5'];
    }

    #[DataProvider('malformedNumbers')]
    public function testAMalformedNumberIsRefusedNamingTheOptionAndTheValue(string $given): void
    {
        try {
            ConsoleOption::wholeNumber($this->input('value', $given), 'value');
            self::fail('A malformed number must be refused, not read as absent.');
        } catch (MalformedOptionException $refusal) {
            self::assertStringContainsString('--value', $refusal->getMessage());
            self::assertStringContainsString('"' . $given . '"', $refusal->getMessage());
            self::assertSame(Command::INVALID, $refusal->getCode());
        }
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function limits(): iterable
    {
        yield 'absent' => [null, null];
        yield 'zero still does one item' => ['0', 1];
        yield 'a limit' => ['5', 5];
    }

    #[DataProvider('limits')]
    public function testALimitIsAtLeastOne(?string $given, ?int $read): void
    {
        self::assertSame($read, ConsoleOption::limit($this->input('limit', $given)));
    }

    public function testAMalformedLimitIsRefused(): void
    {
        $this->expectException(MalformedOptionException::class);

        ConsoleOption::limit($this->input('limit', '10abc'));
    }

    private function input(string $name, ?string $value): InputInterface
    {
        $definition = new InputDefinition([new InputOption($name, null, InputOption::VALUE_REQUIRED)]);

        return new ArrayInput($value === null ? [] : ['--' . $name => $value], $definition);
    }
}
