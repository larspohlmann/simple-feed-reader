<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SocialInteractionProblems;
use App\Http\Problem\ExceptionProblems\SocialProblems;
use App\Service\Social\Publishing\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Publishing\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostRemovedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SocialInteractionProblemsTest extends TestCase
{
    #[DataProvider('problems')]
    public function testEachInteractionFailureHasItsProblem(\Throwable $exception, string $type, int $status): void
    {
        $resolved = (new SocialInteractionProblems())->resolve($exception);

        self::assertNotNull($resolved);
        self::assertSame($type, $resolved->problem->type);
        self::assertSame($status, $resolved->problem->status);
    }

    /** @return iterable<string, array{\Throwable, string, int}> */
    public static function problems(): iterable
    {
        yield 'not a post' => [new NotASocialPostException('x'), 'social_post_unsupported', Response::HTTP_CONFLICT];
        yield 'no account' => [
            new NoSocialAccountForNetworkException('x'),
            'social_account_missing',
            Response::HTTP_CONFLICT,
        ];
        yield 'post gone' => [new SocialPostNotFoundException('x'), 'social_post_not_found', Response::HTTP_NOT_FOUND];
        yield 'post removed here' => [
            new SocialPostRemovedException('x'),
            'social_post_removed',
            Response::HTTP_GONE,
        ];
        yield 'post refused' => [new SocialPostRefusedException('x'), 'social_post_refused', Response::HTTP_CONFLICT];
        yield 'link title too short' => [
            new SocialPostTitleTooShortException('x'),
            'social_post_title_too_short',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ];
        yield 'link without a community' => [
            new SocialPostCommunityMissingException('x'),
            'social_post_community_missing',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ];
        yield 'post too long' => [
            new SocialPostTooLongException('x'),
            'social_post_too_long',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ];
        yield 'token scope missing' => [
            new SocialTokenScopeMissingException('x', 'read'),
            'social_token_scope_missing',
            Response::HTTP_CONFLICT,
        ];
        yield 'throttled' => [
            new SocialNetworkThrottledException('x', 90),
            'social_network_throttled',
            Response::HTTP_SERVICE_UNAVAILABLE,
        ];
        yield 'unreachable' => [
            new SocialNetworkUnreachableException('x'),
            'social_network_unreachable',
            Response::HTTP_BAD_GATEWAY,
        ];
    }

    /** @return iterable<string, array{\Throwable, string, string}> */
    public static function serviceProblems(): iterable
    {
        yield 'unavailable' => [
            SocialNetworkUnavailableException::naming('lemmy.world', 503),
            'social_network_unavailable',
            'The network is not responding',
        ];
        yield 'write outcome unknown' => [
            SocialWriteOutcomeUnknownException::naming('lemmy.world', 503),
            'social_write_outcome_unknown',
            'The network may not have finished the request',
        ];
    }

    #[DataProvider('serviceProblems')]
    public function testAServiceProblemNamesTheServiceAndCarriesTheMessage(
        \Throwable $exception,
        string $type,
        string $title,
    ): void {
        $resolved = (new SocialInteractionProblems())->resolve($exception);

        self::assertNotNull($resolved);
        self::assertSame($type, $resolved->problem->type);
        self::assertSame($title, $resolved->problem->title);
        self::assertSame(Response::HTTP_BAD_GATEWAY, $resolved->problem->status);
        self::assertSame($exception->getMessage(), $resolved->problem->detail);
        self::assertSame(['serviceName' => 'lemmy.world'], $resolved->extensions);
        self::assertTrue($resolved->isServerFault());
    }

    public function testAMissingScopeIsNamedInTheDetail(): void
    {
        $missing = new SocialTokenScopeMissingException('x', 'write:favourites');

        $resolved = (new SocialInteractionProblems())->resolve($missing);

        self::assertSame(
            'Reconnect this account with an access token that has the write:favourites scope.',
            $resolved?->problem->detail,
        );
    }

    public function testATooShortTitleIsNamedInTheProblemTitle(): void
    {
        $resolved = (new SocialInteractionProblems())->resolve(new SocialPostTitleTooShortException('x'));

        self::assertSame('The post title is too short', $resolved?->problem->title);
    }

    public function testAnOverlongPostIsNamedAPostNotAReply(): void
    {
        $resolved = (new SocialInteractionProblems())->resolve(new SocialPostTooLongException('x'));

        self::assertSame('The post is too long for the network', $resolved?->problem->title);
    }

    public function testAThrottleNamesItsDelayOnlyWhenTheNetworkGaveOne(): void
    {
        $problems = new SocialInteractionProblems();

        self::assertSame(
            ['Retry-After' => '90'],
            $problems->resolve(new SocialNetworkThrottledException('x', 90))?->headers,
        );
        self::assertSame([], $problems->resolve(new SocialNetworkThrottledException('x'))?->headers);
    }

    public function testAThrottleIsAnExpectedAnswerWhileAnUnreachableNetworkIsAServerFault(): void
    {
        $problems = new SocialInteractionProblems();

        self::assertFalse($problems->resolve(new SocialNetworkThrottledException('x'))?->isServerFault());
        self::assertTrue($problems->resolve(new SocialNetworkUnreachableException('x'))?->isServerFault());
    }

    public function testTheAccountProblemsNoLongerClaimAnUnreachableNetworkAndOthersPassThrough(): void
    {
        self::assertNull((new SocialProblems())->resolve(new SocialNetworkUnreachableException('x')));
        self::assertNull((new SocialInteractionProblems())->resolve(new \RuntimeException('x')));
    }
}
