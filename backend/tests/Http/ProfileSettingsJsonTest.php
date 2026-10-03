<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;
use App\Http\ProfileSettingsJson;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;
use PHPUnit\Framework\TestCase;

final class ProfileSettingsJsonTest extends TestCase
{
    public function testAGeneratedProfileSaysWhenAndByWhichModel(): void
    {
        $json = ProfileSettingsJson::state(new ProfileSettingsModel(
            new StoredProfile(
                'Likes maps.',
                new \DateTimeImmutable('2026-10-03 07:15:00'),
                'llm.example.test',
                'qwen3-14b',
            ),
            new ProfileSettingsValues(48, null, 7, 9),
            null,
            [],
            true,
        ));

        self::assertSame('Likes maps.', $json['profileText']);
        self::assertSame('2026-10-03T07:15:00+00:00', $json['generatedAt']);
        self::assertSame(['providerHost' => 'llm.example.test', 'model' => 'qwen3-14b'], $json['generatedBy']);
        self::assertSame(48, $json['intervalHours']);
        self::assertNull($json['connection']);
        self::assertSame(7, $json['keptCap']);
        self::assertTrue($json['debugEnabled']);
    }

    public function testNoProfileHasNoAttribution(): void
    {
        $json = ProfileSettingsJson::state(new ProfileSettingsModel(
            StoredProfile::none(),
            ProfileSettingsValues::defaults(),
            null,
            [],
            false,
        ));

        self::assertNull($json['generatedBy']);
        self::assertSame(['keptCap' => 40, 'viewedCap' => 80], $json['defaults']);
    }
}
