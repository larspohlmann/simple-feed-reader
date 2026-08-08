<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Five-minute scheduler tick: refresh whichever feeds are due. The
 * 2026-08-07 decision that brings scheduled refresh to worker-equipped
 * installs; poll-only (Strato) installs stay manual. Carries no properties
 * — the handler derives due feeds from the database, so a copy sitting in
 * the failure transport can never go stale.
 */
final readonly class RefreshDueFeeds
{
}
