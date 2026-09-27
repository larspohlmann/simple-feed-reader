<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AltchaChallengeJson;
use App\Service\Auth\AltchaChallenge;
use PHPUnit\Framework\TestCase;

final class AltchaChallengeJsonTest extends TestCase
{
    public function testTheWidgetGetsEveryFieldWithItsLowercaseMaxnumber(): void
    {
        self::assertSame(
            [
                'algorithm' => 'SHA-256',
                'challenge' => 'c0ffee',
                'salt' => 'salt?expires=1',
                'signature' => 'beef',
                'maxnumber' => 150000,
            ],
            AltchaChallengeJson::from(new AltchaChallenge('SHA-256', 'c0ffee', 'salt?expires=1', 'beef', 150000)),
        );
    }
}
