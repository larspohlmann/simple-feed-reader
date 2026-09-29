<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Entity\InstanceSettingsUpdate;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PublicBaseUrl\ConfiguredPublicBaseUrl;
use App\Tests\DbTestCase;
use App\Tests\Support\FinishesWorkerMessages;

final class SettingsResetBetweenMessagesTest extends DbTestCase
{
    use FinishesWorkerMessages;

    public function testTheNextMessageSeesAnApprovalSettingSavedElsewhere(): void
    {
        $settings = $this->instanceSettings();
        $settings->update(new InstanceSettingsUpdate(true, false, null, null, null));
        self::assertFalse($settings->requireApproval());

        $this->saveElsewhere('UPDATE instance_setting SET require_approval = 1');
        self::assertFalse($settings->requireApproval(), 'Within one message the memo serves the row it read first.');

        $this->finishAMessage();

        self::assertTrue($settings->requireApproval());
    }

    public function testTheNextMessageLinksToAPublicBaseUrlSavedElsewhere(): void
    {
        $this->instanceSettings()->update(new InstanceSettingsUpdate(false, false, 'https://old.example', null, null));
        /** @var ConfiguredPublicBaseUrl $baseUrl */
        $baseUrl = self::getContainer()->get(ConfiguredPublicBaseUrl::class);
        self::assertSame('https://old.example', $baseUrl->get());

        $this->saveElsewhere("UPDATE instance_setting SET public_base_url = 'https://new.example'");
        self::assertSame('https://old.example', $baseUrl->get(), 'Within one message the URL is memoised.');

        $this->finishAMessage();

        self::assertSame('https://new.example', $baseUrl->get());
    }

    private function instanceSettings(): InstanceSettings
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);

        return $settings;
    }

    /** Another process's write; the worker's clear() after each message leaves the memo holding the old row. */
    private function saveElsewhere(string $statement): void
    {
        $this->entityManager->getConnection()->executeStatement($statement);
        $this->entityManager->clear();
    }
}
