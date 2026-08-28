<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Hourly scheduler tick: send every digest that is due (#636). A sweep — it
 * does whatever is outstanding when it runs, so a missed tick catches up in
 * one. Carries no properties — the handler derives due accounts from the
 * database, so a copy sitting in the failure transport can never go stale.
 */
final readonly class SendDueDigests
{
}
