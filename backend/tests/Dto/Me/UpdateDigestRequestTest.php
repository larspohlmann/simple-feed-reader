<?php

declare(strict_types=1);

namespace App\Tests\Dto\Me;

use App\Dto\Me\UpdateDigestRequest;
use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use PHPUnit\Framework\TestCase;

final class UpdateDigestRequestTest extends TestCase
{
    public function testToConfigurationCarriesEverySetting(): void
    {
        $configuration = (new UpdateDigestRequest(true, DigestCadence::Weekly, 7, 3, DigestFormat::Text))
            ->toConfiguration();

        self::assertSame(
            [
                'enabled' => true,
                'cadence' => DigestCadence::Weekly,
                'sendHour' => 7,
                'weekday' => 3,
                'format' => DigestFormat::Text,
            ],
            get_object_vars($configuration),
        );
    }
}
