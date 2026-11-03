<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Entry;
use App\Entity\User;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;

/**
 * Registers an entry as shared once a network accepted its repost or quote. A failure here never fails the request:
 * the post is out, and a retry would post it twice.
 */
final readonly class EntryShareRecorder
{
    public function __construct(
        private EntryShares $shares,
        private LoggerInterface $logger,
    ) {
    }

    public function record(User $user, Entry $entry): void
    {
        try {
            $this->shares->record($user, $entry);
        } catch (DbalException $exception) {
            $this->logger->warning('Recording a network share failed after the network accepted the post.', [
                'userId' => $user->requireId(),
                'entryId' => $entry->requireId(),
                'exception' => $exception,
            ]);
        }
    }
}
