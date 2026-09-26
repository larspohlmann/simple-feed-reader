<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Tag;
use App\Entity\User;
use App\Http\OnboardingJson;
use App\Http\TagJson;
use App\Service\Subscription\BulkSubscribeResult;
use PHPUnit\Framework\TestCase;

final class OnboardingJsonTest extends TestCase
{
    public function testSkippedSumsEverySkipReasonAndTheCreatedTagsAreMapped(): void
    {
        $tag = new Tag(new User('onboarding@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z')), 'Science');
        $result = new BulkSubscribeResult(
            imported: 3,
            alreadySubscribed: 1,
            invalid: 2,
            skippedOverLimit: 4,
            tagsCreated: [$tag],
        );

        self::assertSame([
            'subscribed' => 3,
            'skipped' => 7,
            'skippedOverLimit' => 4,
            'tagsCreated' => [TagJson::one($tag)],
        ], OnboardingJson::subscribed($result));
    }
}
