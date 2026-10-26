<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class OutputCapFallback implements RejectedRequestFallbackInterface
{
    private const string CAP = '/supports at most (\d+) completion tokens/';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        $cap = $this->capNamedIn($rejection);
        if (null === $cap || !$this->lowers($connection, $cap)) {
            return false;
        }

        $connection->recordOutputCap($cap);
        $this->entityManager->flush();

        return true;
    }

    private function capNamedIn(ProviderRejectedRequestException $rejection): ?int
    {
        if (Response::HTTP_BAD_REQUEST !== $rejection->status()) {
            return null;
        }
        if (1 !== preg_match(self::CAP, $rejection->getMessage(), $match)) {
            return null;
        }
        $cap = (int) $match[1];

        return $cap > 0 ? $cap : null;
    }

    private function lowers(AiProviderSettings $connection, int $cap): bool
    {
        $recorded = $connection->getModelMaxOutputTokens();

        return null === $recorded || $cap < $recorded;
    }
}
