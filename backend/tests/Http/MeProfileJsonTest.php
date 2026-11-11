<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\User;
use App\Http\ActiveAiJson;
use App\Http\MeJson;
use App\Http\MeProfileJson;
use App\Http\RecommendationCapabilitiesJson;
use App\Repository\CatalogFeedRepository;
use App\Service\Account\AccountSettings;
use App\Service\Mail\MailCapability;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class MeProfileJsonTest extends DbTestCase
{
    use EnablesMailInTests;

    private const array NO_ACTIVE_CONNECTION = ['ready' => false, 'model' => null, 'capabilities' => null];
    private const array EMPTY_CATALOG = ['hasEntries' => false];

    public function testAnInstanceThatSendsNoMailReportsMailOffAndItsTimezone(): void
    {
        $user = $this->user();

        self::assertSame(
            [
                ...MeJson::profile($user, false),
                'preferences' => MeJson::preferences($this->accountSettings()->of($user), false, 'Europe/Berlin'),
                'ai' => self::NO_ACTIVE_CONNECTION,
                'catalog' => self::EMPTY_CATALOG,
            ],
            $this->profileJson('Europe/Berlin')->of($user),
        );
    }

    public function testAMailSendingInstanceReportsMailOn(): void
    {
        $this->seedEnabledMailInstance();
        $user = $this->user();

        self::assertSame(
            [
                ...MeJson::profile($user, true),
                'preferences' => MeJson::preferences($this->accountSettings()->of($user), false, 'UTC'),
                'ai' => self::NO_ACTIVE_CONNECTION,
                'catalog' => self::EMPTY_CATALOG,
            ],
            $this->profileJson('UTC')->of($user),
        );
    }

    public function testACatalogWithAPickableFeedReportsEntries(): void
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $this->entityManager->persist($category);
        $this->entityManager->persist(
            new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml'),
        );
        $this->entityManager->flush();
        $user = $this->user();

        self::assertSame(
            [
                ...MeJson::profile($user, false),
                'preferences' => MeJson::preferences($this->accountSettings()->of($user), false, 'UTC'),
                'ai' => self::NO_ACTIVE_CONNECTION,
                'catalog' => ['hasEntries' => true],
            ],
            $this->profileJson('UTC')->of($user),
        );
    }

    private function profileJson(string $instanceTimezone): MeProfileJson
    {
        $mail = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mail);

        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        $catalogFeeds = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $catalogFeeds);

        return new MeProfileJson(
            $mail,
            $this->accountSettings(),
            new ActiveAiJson(new RecommendationCapabilitiesJson($engines)),
            $catalogFeeds,
            $instanceTimezone,
        );
    }

    private function accountSettings(): AccountSettings
    {
        $accountSettings = self::getContainer()->get(AccountSettings::class);
        self::assertInstanceOf(AccountSettings::class, $accountSettings);

        return $accountSettings;
    }

    private function user(): User
    {
        $user = new User('profile@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
