<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Daily scheduler tick: prune old rows out of the failure transport, so a
 * stuck worker cannot let `messenger_messages` grow without bound. Carries
 * no properties — the handler derives what to purge from the database, so a
 * copy sitting in the failure transport can never go stale.
 */
final readonly class PurgeFailedMessages
{
}
