<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Outbound\Fetch\Exception\FeedUnreachableException;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\RequestMayHaveLeftException;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use App\Service\Social\Social\Support\NetworkAnswer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Response;

final class NetworkAnswerTest extends TestCase
{
    private const string URL = 'https://lemmy.example/api/v3/post';

    public function testAnAnswerOfAnyStatusIsReturned(): void
    {
        $answer = new ApiResponseModel(Response::HTTP_BAD_GATEWAY, '');

        self::assertSame($answer, NetworkAnswer::askNetwork(static fn (): ApiResponseModel => $answer, 'Bluesky'));
    }

    public function testNoAnswerIsAnUnreachableNetworkNamedInTheMessage(): void
    {
        $cause = new FeedUnreachableException('https://pds.example.test: timed out');

        try {
            NetworkAnswer::askNetwork(static fn (): ApiResponseModel => throw $cause, 'The Mastodon instance');
            self::fail('No answer must not pass as one.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertSame('The Mastodon instance could not be reached.', $exception->getMessage());
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testAWriteThatTimedOutMayHaveGoneThrough(): void
    {
        $cause = FetchException::from(self::URL, new TimeoutException('Idle timeout reached'));

        try {
            NetworkAnswer::fetchAnswer(
                ApiRequestModel::postJson(self::URL, []),
                static fn (ApiRequestModel $request): ApiResponseModel => throw $cause,
                'The Lemmy instance',
                'lemmy.example',
            );
            self::fail('A timed-out write must not pass as an answer.');
        } catch (SocialWriteOutcomeUnknownException $exception) {
            self::assertSame('lemmy.example', $exception->serviceName);
            self::assertSame(
                'lemmy.example had trouble with this request, so it may or may not have gone through. '
                . 'Check before trying again.',
                $exception->getMessage(),
            );
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testAWriteThatMayHaveLeftMayHaveGoneThrough(): void
    {
        $cause = FetchException::from(self::URL, new RequestMayHaveLeftException('Connection reset by peer'));

        try {
            NetworkAnswer::fetchAnswer(
                ApiRequestModel::postJson(self::URL, []),
                static fn (ApiRequestModel $request): ApiResponseModel => throw $cause,
                'The Lemmy instance',
                'lemmy.example',
            );
            self::fail('A write that may have left must not pass as unreachable.');
        } catch (SocialWriteOutcomeUnknownException $exception) {
            self::assertSame('lemmy.example', $exception->serviceName);
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testAReadThatTimedOutIsUnreachable(): void
    {
        $this->assertUnreachable(
            ApiRequestModel::get(self::URL),
            new TimeoutException('Idle timeout reached'),
        );
    }

    public function testAWriteThatNeverConnectedIsUnreachable(): void
    {
        $this->assertUnreachable(
            ApiRequestModel::postJson(self::URL, []),
            new TransportException('Could not resolve host'),
        );
    }

    public function testAReadThatMayHaveLeftIsUnreachable(): void
    {
        $this->assertUnreachable(
            ApiRequestModel::get(self::URL),
            new RequestMayHaveLeftException('Connection reset by peer'),
        );
    }

    public function testAWriteThatIsMarkedChangingNothingIsARead(): void
    {
        $this->assertUnreachable(
            ApiRequestModel::postJson(self::URL, [])->changingNothing(),
            new TimeoutException('Idle timeout reached'),
        );
    }

    private function assertUnreachable(ApiRequestModel $request, \Throwable $cause): void
    {
        try {
            NetworkAnswer::fetchAnswer(
                $request,
                static fn (ApiRequestModel $sent): ApiResponseModel => throw FetchException::from(self::URL, $cause),
                'The Lemmy instance',
                'lemmy.example',
            );
            self::fail('No answer must not pass as one.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertSame(SocialNetworkUnreachableException::class, $exception::class);
            self::assertSame('The Lemmy instance could not be reached.', $exception->getMessage());
        }
    }
}
