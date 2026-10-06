<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Pass;

use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Where one protocol's requests go, which statuses it retries, and how it reads a 2xx reply. */
final readonly class ScoringEndpoint
{
    /**
     * @param list<int> $retryableStatuses the protocol's own, beside the transport's shared mapping
     * @param \Closure(string, ResponseInterface, int): ScoringReplyModel $decoder the int is the request's position
     */
    public function __construct(
        public string $path,
        private array $retryableStatuses,
        private \Closure $decoder,
    ) {
    }

    public function retries(int $status): bool
    {
        return \in_array($status, $this->retryableStatuses, true);
    }

    public function decode(string $body, ResponseInterface $response, int $position): ScoringReplyModel
    {
        return ($this->decoder)($body, $response, $position);
    }
}
