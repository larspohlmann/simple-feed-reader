<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Pass;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Pass\ResponseWave;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Scoring\Support\SystemOneReplyDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseWaveTest extends TestCase
{
    private const float IDLE_SECONDS = 120.0;

    public function testAResponseSilentForExactlyTheIdleBoundStaysOpenAndFailsJustAfter(): void
    {
        $clock = new MockClock('2026-10-02 09:00:00');
        $wave = new ResponseWave($clock, $this->credentials(), self::endpoint());
        $response = $this->response();
        $wave->await(0, $response);

        $clock->sleep(self::IDLE_SECONDS);
        $wave->failSilentFor(self::IDLE_SECONDS);
        self::assertSame([$response], $wave->openResponses());

        $clock->sleep(0.001);
        $wave->failSilentFor(self::IDLE_SECONDS);
        self::assertSame([], $wave->openResponses());
        self::assertSame(
            'That provider sent nothing for more than 120 seconds.',
            $wave->outcomes()[0]->cause()->getMessage(),
        );
    }

    public function testTheOutcomesFollowTheRequestOrderNotTheOrderTheySettled(): void
    {
        $wave = new ResponseWave(new MockClock(), $this->credentials(), self::endpoint());
        $first = ScoringOutcomeModel::failed(new ProviderUnreachableException('first'));
        $second = ScoringOutcomeModel::failed(new ProviderUnreachableException('second'));

        $wave->settleAt(1, $second);
        $wave->settleAt(0, $first);

        self::assertSame([$first, $second], $wave->outcomes());
    }

    private function response(): ResponseInterface
    {
        return (new MockHttpClient(new MockResponse('{}')))->request('POST', 'https://systemone.example.test');
    }

    private static function endpoint(): ScoringEndpoint
    {
        return new ScoringEndpoint(
            '/systemone',
            [429, 529],
            static fn (string $body): ScoringReplyModel => SystemOneReplyDecoder::decode($body, null),
        );
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.typesafe.test/v1', 'sk-jev');
    }
}
