<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Support\NetworkAnswer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NetworkAnswerTest extends TestCase
{
    public function testAnAnswerOfAnyStatusIsReturned(): void
    {
        $answer = new ApiResponseModel(Response::HTTP_BAD_GATEWAY, '');

        self::assertSame($answer, NetworkAnswer::orUnreachable(static fn (): ApiResponseModel => $answer, 'Bluesky'));
    }

    public function testNoAnswerIsAnUnreachableNetworkNamedInTheMessage(): void
    {
        $cause = new FeedUnreachableException('https://pds.example.test: timed out');

        try {
            NetworkAnswer::orUnreachable(static fn (): ApiResponseModel => throw $cause, 'The Mastodon instance');
            self::fail('No answer must not pass as one.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertSame('The Mastodon instance could not be reached.', $exception->getMessage());
            self::assertSame($cause, $exception->getPrevious());
        }
    }
}
