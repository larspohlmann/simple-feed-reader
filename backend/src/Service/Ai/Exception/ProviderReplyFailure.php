<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint answered, and the answer is the problem.
 *
 * The distinction every consumer of a completion has to make: did the
 * *endpoint* fail, or did the *model*? An endpoint failure means no reply
 * exists, aborting the wave and counting against the transport ceiling until
 * it fails the run. A reply failure means a reply exists but is unusable, so
 * it costs its own call a corrective retry and degrades if it persists.
 *
 * An interface rather than three `instanceof` checks against a concrete
 * class because #437 wrote it as exactly that — one in the client, two in
 * the wave, one in the advancer. Here a new kind picks its side once, at its
 * own declaration, and every consumer follows.
 */
interface ProviderReplyFailure extends \Throwable
{
    /** Whatever arrived before the reply was rejected; the retry quotes it back. */
    public function partialAnswer(): string;
}
