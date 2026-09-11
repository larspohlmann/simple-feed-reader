<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scrubs each reported item and logs it through the client_errors channel, the
 * one seam that feeds the #983 Loki pipeline with source=frontend. The channel
 * logger is injected by service id because it is a per-channel Monolog logger,
 * not the default autowired one.
 */
final readonly class ClientErrorRecorder
{
    public function __construct(
        private ClientErrorScrubber $scrubber,
        #[Autowire(service: 'monolog.logger.client_errors')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ClientErrorItem> $items
     */
    public function record(array $items, ?User $user): void
    {
        foreach ($items as $item) {
            $scrubbed = $this->scrubber->scrub($item);
            $this->logger->error($scrubbed->message, $this->context($scrubbed, $user));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ClientErrorItem $item, ?User $user): array
    {
        return array_filter([
            'kind' => $item->kind,
            'url' => $item->url,
            'route' => $item->route,
            'buildVersion' => $item->buildVersion,
            'userAgent' => $item->userAgent,
            'stack' => $item->stack,
            'at' => $item->at,
            'userId' => $user?->getId(),
        ], static fn (mixed $value): bool => null !== $value);
    }
}
