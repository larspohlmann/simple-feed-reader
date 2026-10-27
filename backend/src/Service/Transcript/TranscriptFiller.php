<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\EntryTranscript;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use Psr\Log\LoggerInterface;

/** Fills one queued transcript, or fails it for good, or backs it off. The caller flushes. */
final readonly class TranscriptFiller
{
    public function __construct(
        private TranscriptSourceInterface $source,
        private TranscriptReader $reader,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function fill(EntryTranscript $transcript, float $budgetSeconds): void
    {
        try {
            $read = $this->reader->read(
                $this->source->bodyAt($transcript->getUrl(), $budgetSeconds),
                $transcript->getFormat(),
            );
        } catch (TranscriptUnusableException $exception) {
            $transcript->fail();
            $this->logger->info('Transcript unusable; not retried.', $this->contextOf($transcript, $exception));

            return;
        } catch (TranscriptUnreachableException $exception) {
            $transcript->retryAfter($this->clock->now());
            $this->logger->info('Transcript unreachable; retried later.', $this->contextOf($transcript, $exception));

            return;
        } catch (\Throwable $exception) {
            $transcript->retryAfter($this->clock->now());
            $this->logger->error(
                'Transcript fetch failed unexpectedly; retried later.',
                $this->contextOf($transcript, $exception),
            );

            return;
        }

        $transcript->fill($read->paragraphs, $read->searchText);
    }

    /** @return array{url: string, exception: \Throwable} */
    private function contextOf(EntryTranscript $transcript, \Throwable $exception): array
    {
        return ['url' => $transcript->getUrl(), 'exception' => $exception];
    }
}
