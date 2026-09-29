<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface;

/**
 * Records each launch instead of forking, so a test can assert the line and the count: the drain listener fires at
 * most once per request or command, and only a recorded list tells one launch from six.
 */
final class RecordingProcessLauncher implements DetachedProcessLauncherInterface
{
    /** @var list<list<string>> */
    public array $launches = [];

    public function launch(string $consoleCommandName, string ...$arguments): void
    {
        // array_values() because a variadic collected from named arguments is
        // a string-keyed array, so PHPStan max does not read the spread as a
        // list on its own.
        $this->launches[] = array_values([$consoleCommandName, ...$arguments]);
    }
}
