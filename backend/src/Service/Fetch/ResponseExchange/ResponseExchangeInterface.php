<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** How the engine reads one kind of answer, keyed by its ResponseExchangeKind value in ConcurrentRequestEngine. */
#[AutoconfigureTag('app.response_exchange')]
interface ResponseExchangeInterface
{
    public static function kind(): string;

    /**
     * @throws FetchException
     * @throws ExceptionInterface wrapped into a FetchException by the engine
     */
    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel;

    /**
     * @throws FetchException
     * @throws ExceptionInterface wrapped into a FetchException by the engine
     */
    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel;
}
