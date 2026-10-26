<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Enum\TokenLimitParameter;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class MaxTokensRefusedFallback implements RejectedRequestFallbackInterface
{
    private const string CODE = 'unsupported_parameter';

    private const string PARAMETER = 'max_tokens';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        if (!$this->refusesMaxTokens($connection, $rejection)) {
            return false;
        }

        $connection->recordMaxTokensRefused();
        $this->entityManager->flush();

        return true;
    }

    private function refusesMaxTokens(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        return Response::HTTP_BAD_REQUEST === $rejection->status()
            && TokenLimitParameter::MaxTokens === $connection->getTokenLimitParameter()
            && $rejection->identifiers()->names(self::CODE, self::PARAMETER);
    }
}
