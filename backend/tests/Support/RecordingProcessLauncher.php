<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Process\DetachedProcessLauncherInterface;

/**
 * Records every detached launch instead of forking one, so a test can assert
 * both the command line a caller asked for and how many times it asked. The
 * count is load-bearing: RecommendationDrainOnTerminateListener fires at most
 * once per request or console command, and only a recorded list can tell one
 * launch from six (#371, #393).
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
