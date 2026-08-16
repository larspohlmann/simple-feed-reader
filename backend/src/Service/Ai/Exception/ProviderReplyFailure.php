<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint answered, and the answer is the problem.
 *
 * The distinction this marks is the one every consumer of a completion has to
 * make: did the *endpoint* fail, or did the *model*? An endpoint failure means
 * no reply exists, so it aborts the wave, counts against the transport ceiling
 * and eventually fails the run. A reply failure means a reply exists and is
 * merely unusable, so it costs its own call a corrective retry and degrades if
 * it persists.
 *
 * It is an interface rather than three `instanceof` checks against a concrete
 * class because #437 wrote it as exactly that — one in the client, two in the
 * wave, one in the advancer — and a second reply-side failure would have had
 * to find all of them. Here a new kind picks its side once, at its own
 * declaration, and every consumer follows.
 */
interface ProviderReplyFailure extends \Throwable
{
    /** Whatever arrived before the reply was rejected; the retry quotes it back. */
    public function partialAnswer(): string;
}
