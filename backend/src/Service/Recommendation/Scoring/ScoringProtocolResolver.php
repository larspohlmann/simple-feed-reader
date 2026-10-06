<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocol\ScoringProtocolInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class ScoringProtocolResolver
{
    public function __construct(
        #[AutowireLocator('app.scoring_protocol')]
        private ContainerInterface $protocols,
    ) {
    }

    /** @return ScoringProtocolInterface<object> */
    public function protocolOf(ScoringProtocol $protocol): ScoringProtocolInterface
    {
        try {
            $implementation = $this->protocols->get($protocol->value);
        } catch (ContainerExceptionInterface $exception) {
            throw new \LogicException(
                sprintf('No scoring protocol is wired for "%s".', $protocol->value),
                previous: $exception,
            );
        }
        \assert($implementation instanceof ScoringProtocolInterface);

        return $implementation;
    }
}
