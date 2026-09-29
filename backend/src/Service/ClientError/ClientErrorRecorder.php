<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Entity\User;
use App\Service\ClientError\Model\ClientErrorModel;
use App\Service\Logging\Loki\LokiPushHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Logs each scrubbed report to client_errors, the one channel that ships frontend errors to Loki. */
final readonly class ClientErrorRecorder
{
    public function __construct(
        private ClientErrorScrubber $scrubber,
        #[Autowire(service: 'monolog.logger.' . LokiPushHandler::CLIENT_ERRORS_CHANNEL)]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ClientErrorModel> $clientErrors
     */
    public function record(array $clientErrors, ?User $user): void
    {
        foreach ($clientErrors as $clientError) {
            $scrubbed = $this->scrubber->scrub($clientError);
            $this->logger->error($scrubbed->message, $this->context($scrubbed, $user));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ClientErrorModel $clientError, ?User $user): array
    {
        return array_filter([
            'kind' => $clientError->kind,
            'url' => $clientError->url,
            'route' => $clientError->route,
            'buildVersion' => $clientError->buildVersion,
            'userAgent' => $clientError->userAgent,
            'stack' => $clientError->stack,
            'at' => $clientError->at,
            'userId' => $user?->getId(),
        ], static fn (mixed $value): bool => null !== $value);
    }
}
