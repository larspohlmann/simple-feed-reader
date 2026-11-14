<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\DigestSettings;
use App\Entity\FeedDirectoryScope;
use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;
use App\Entity\User;
use App\Service\Identity\Account\Model\AccountSettingsModel;

final class UnsavedUserSettings
{
    public static function reading(): ReadingSettings
    {
        return new ReadingSettings(self::user());
    }

    public static function sharing(): SharingSettings
    {
        return new SharingSettings(self::user());
    }

    public static function digest(): DigestSettings
    {
        return new DigestSettings(self::user());
    }

    public static function of(User $user): AccountSettingsModel
    {
        return new AccountSettingsModel(
            new ReadingSettings($user),
            new SharingSettings($user),
            new DigestSettings($user),
            new FeedDirectoryScope($user),
        );
    }

    private static function user(): User
    {
        return new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00'));
    }

    private function __construct()
    {
    }
}
