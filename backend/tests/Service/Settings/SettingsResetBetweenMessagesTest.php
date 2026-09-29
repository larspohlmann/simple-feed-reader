<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Entity\InstanceSettingsUpdate;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PublicBaseUrl\ConfiguredPublicBaseUrl;
use App\Tests\DbTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/** The worker's view: an admin saves the settings in another process while the worker handles messages (D-P8). */
final class SettingsResetBetweenMessagesTest extends DbTestCase
{
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

    /** Another process's write, which the worker's clear() after each message also leaves the memo holding. */
    private function saveElsewhere(string $statement): void
    {
        $this->em->getConnection()->executeStatement($statement);
        $this->em->clear();
    }

    /** What messenger:consume attaches at run time and Worker::run() dispatches after each handled message. */
    private function finishAMessage(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        /** @var ResetServicesListener $listener */
        $listener = self::getContainer()->get('messenger.listener.reset_services');
        $dispatcher->addSubscriber($listener);
        $worker = new Worker([], $this->createStub(MessageBusInterface::class), $dispatcher);

        $dispatcher->dispatch(new WorkerRunningEvent($worker, false));
    }
}
