<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WorkerRunsWithResetTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function composeFiles(): iterable
    {
        yield 'dev stack' => ['docker-compose.yml'];
        yield 'prod stack' => ['docker-compose.prod.yml'];
    }

    #[DataProvider('composeFiles')]
    public function testNoWorkerSkipsTheResetBetweenMessages(string $composeFile): void
    {
        $path = \dirname(__DIR__, 2) . '/' . $composeFile;
        if (!is_file($path)) {
            self::markTestSkipped($composeFile . ' is not mounted here (e.g. inside the app container).');
        }

        $consumers = $this->consumeCommands($path);

        self::assertNotSame([], $consumers, $composeFile . ' runs no messenger:consume.');
        foreach ($consumers as $service => $command) {
            self::assertStringNotContainsString('--no-reset', $command, $service . ' keeps state across messages.');
        }
    }

    /** @return array<string, string> */
    private function consumeCommands(string $path): array
    {
        /** @var array{services: array<string, array{command?: string|list<string>}>} $compose */
        $compose = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        $consumers = [];
        foreach ($compose['services'] as $name => $service) {
            $command = $service['command'] ?? '';
            $line = is_array($command) ? implode(' ', $command) : $command;
            if (str_contains($line, 'messenger:consume')) {
                $consumers[$name] = $line;
            }
        }

        return $consumers;
    }
}
