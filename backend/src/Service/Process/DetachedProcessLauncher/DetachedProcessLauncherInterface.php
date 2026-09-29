<?php

declare(strict_types=1);

namespace App\Service\Process\DetachedProcessLauncher;

/**
 * Best-effort: an implementation never throws, and a host that cannot spawn is a silent no-op, so the caller's slow
 * path must still carry the work.
 */
interface DetachedProcessLauncherInterface
{
    public function launch(string $consoleCommandName, string ...$arguments): void;
}
