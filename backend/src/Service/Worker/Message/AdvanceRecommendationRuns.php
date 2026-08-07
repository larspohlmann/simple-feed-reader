<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Ten-second scheduler tick: advance whatever recommendation runs already
 * exist. Carries no properties — the handler derives everything from the
 * database, so a copy sitting in the failure transport can never go stale.
 * Starting a run stays a manual button (#308); this message only advances
 * one that is already in progress.
 */
final readonly class AdvanceRecommendationRuns
{
}
