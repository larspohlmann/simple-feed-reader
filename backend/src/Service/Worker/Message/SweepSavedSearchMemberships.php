<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Every minute: advance every saved search's membership mark (#1116). A
 * sweep — it does whatever is outstanding when it runs — so it carries no
 * properties and a missed tick catches up in one.
 */
final readonly class SweepSavedSearchMemberships
{
}
