<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\HeaderDecision;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\ResponseClassifier;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** A feed's answer: a 304 settles it at the headers, a redirect goes back on the queue, a 2xx waits for its body. */
final readonly class FeedResponseExchange implements ResponseExchangeInterface
{
    public function __construct(private ResponseClassifier $classifier)
    {
    }

    public static function kind(): string
    {
        return ResponseExchangeKind::Feed->value;
    }

    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel
    {
        $verdict = $this->classifier->fromHeaders($response, $attempt);

        if (HeaderDecision::AwaitBody === $verdict->decision) {
            return ExchangeStepModel::awaitBody();
        }

        if (HeaderDecision::Terminal === $verdict->decision) {
            \assert(null !== $verdict->response);

            return ExchangeStepModel::concluded(FetchOutcomeModel::succeeded($verdict->response));
        }

        if (!$attempt->canFollowRedirect()) {
            throw new FeedUnreachableException(sprintf(
                '%s: more than %d redirects',
                $attempt->ticket->request->url,
                FetchAttemptModel::MAX_REDIRECTS,
            ));
        }

        \assert(null !== $verdict->redirectUrl);

        return ExchangeStepModel::sendNext($attempt->followedTo($verdict->redirectUrl, $verdict->permanent));
    }

    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel
    {
        return FetchOutcomeModel::succeeded($this->classifier->fromBody($response, $attempt));
    }
}
