<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

/**
 * One batch's request in a wave, paired with the run-log row it settles.
 *
 * @template-covariant TRequest of object
 */
final readonly class BatchCall
{
    /** @param TRequest $request */
    public function __construct(
        public object $request,
        public RecordedCall $recordedCall,
    ) {
    }
}
