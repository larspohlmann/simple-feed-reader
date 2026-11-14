<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\MastodonQuoting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonQuotingTest extends TestCase
{
    /** @param array<string, mixed> $status */
    #[DataProvider('statuses')]
    public function testOnlyAnApprovalTheUserIsCoveredByAllowsAQuote(array $status, bool $allowed): void
    {
        self::assertSame($allowed, MastodonQuoting::allows(JsonNodeModel::of($status)));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function statuses(): iterable
    {
        yield 'accepted at once' => [['quote_approval' => ['current_user' => 'automatic']], true];
        yield 'accepted after review' => [['quote_approval' => ['current_user' => 'manual']], true];
        yield 'denied' => [['quote_approval' => ['current_user' => 'denied']], false];
        yield 'a policy Mastodon cannot judge' => [['quote_approval' => ['current_user' => 'unknown']], false];
        yield 'an instance before 4.5' => [['id' => '110'], false];
        yield 'no answer for the user' => [['quote_approval' => ['automatic' => ['public']]], false];
    }
}
