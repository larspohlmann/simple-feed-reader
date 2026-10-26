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
    private const string CODE = 'invalid_value';

    private const string PARAMETER = 'max_tokens';

    private const string CAP = '/at most (\d+)/';

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
        if (!self::isTooLarge($rejection)) {
            return null;
        }
        if (1 !== preg_match(self::CAP, $rejection->getMessage(), $match)) {
            return null;
        }
        $cap = (int) $match[1];

        return $cap > 0 ? $cap : null;
    }

    private static function isTooLarge(ProviderRejectedRequestException $rejection): bool
    {
        return Response::HTTP_BAD_REQUEST === $rejection->status()
            && $rejection->identifiers()->names(self::CODE, self::PARAMETER);
    }

    private function lowers(AiProviderSettings $connection, int $cap): bool
    {
        $recorded = $connection->getModelMaxOutputTokens();

        return null === $recorded || $cap < $recorded;
    }
}
