<?php

declare(strict_types=1);

namespace App\Service\Process;

/**
 * Fire-and-forget launch of a console command, fully detached from the
 * current request. Best-effort by contract (#371): implementations never
 * throw -- a host that cannot spawn (exec in disable_functions, a failed
 * fork, no known CLI binary) is a silent no-op, and the caller's existing
 * slow path must carry the work.
 */
interface DetachedProcessLauncherInterface
{
    public function launch(string $consoleCommandName, string ...$arguments): void;
}
