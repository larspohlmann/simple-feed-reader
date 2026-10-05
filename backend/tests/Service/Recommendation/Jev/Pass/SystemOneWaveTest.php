<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Pass;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Pass\SystemOneWave;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SystemOneWaveTest extends TestCase
{
    private const float IDLE_SECONDS = 120.0;

    public function testAResponseSilentForExactlyTheIdleBoundStaysOpenAndFailsJustAfter(): void
    {
        $clock = new MockClock('2026-10-02 09:00:00');
        $wave = new SystemOneWave($clock, $this->credentials());
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
        $wave = new SystemOneWave(new MockClock(), $this->credentials());
        $first = SystemOneOutcomeModel::failed(new ProviderUnreachableException('first'));
        $second = SystemOneOutcomeModel::failed(new ProviderUnreachableException('second'));

        $wave->settleAt(1, $second);
        $wave->settleAt(0, $first);

        self::assertSame([$first, $second], $wave->outcomes());
    }

    private function response(): ResponseInterface
    {
        return (new MockHttpClient(new MockResponse('{}')))->request('POST', 'https://systemone.example.test');
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.typesafe.test/v1', 'sk-jev');
    }
}
