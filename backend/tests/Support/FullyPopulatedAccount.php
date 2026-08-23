<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One account with every field a backup carries set to a non-null, non-default
 * value.
 *
 * BackupSchemaCoverageTest proves each declared field reaches the exporter's
 * output, and a null field would prove nothing. When that test fails because a
 * new field was added, populating it here is part of the fix (#556).
 *
 * The feed URL is derived from the email because feed.url is unique across the
 * instance: two accounts seeded in one test run would otherwise collide.
 */
final readonly class FullyPopulatedAccount
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function create(string $email): User
    {
        $user = (new UserFactory($this->em, $this->hasher))->create($email, locale: 'de');
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        $this->em->persist($this->settingsFor($user));

        $tag = $this->tagFor($user);
        $this->em->persist($tag);

        $feed = $this->feedFor($email);
        $this->em->persist($feed);
        $this->em->persist($this->subscriptionFor($user, $feed, $tag));

        $entry = $this->entryFor($feed);
        $this->em->persist($entry);
        $this->em->persist($this->stateFor($user, $entry));

        $this->em->flush();

        return $user;
    }

    private function settingsFor(User $user): RecommendationSettings
    {
        $settings = new RecommendationSettings($user);
        $settings->update($this->recommendationValues());

        return $settings;
    }

    private function recommendationValues(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'Prefer long-form reporting over news wires.',
            favoritesCap: 11,
            keptCap: 12,
            viewedCap: 13,
            candidatePoolSize: 14,
            lookbackDays: 15,
            picksLimit: 16,
            contextWindow: 17,
            batchCount: 18,
            debugEnabled: true,
            autoGenerateIntervalHours: 19,
            profileText: 'Reads infrastructure essays and typography criticism.',
            showReasons: true,
        );
    }

    private function tagFor(User $user): Tag
    {
        $tag = new Tag($user, 'Long reads');
        $tag->setColor('#3355ff');
        $tag->setIcon('bookmark');
        $tag->setPosition(2);

        return $tag;
    }

    private function feedFor(string $email): Feed
    {
        $feed = new Feed('https://populated.example/' . rawurlencode($email) . '/feed.xml');
        $feed->setTitle('Populated');
        $feed->setSiteUrl('https://populated.example');
        $feed->setDescription('Every backed-up feed field, set.');
        $feed->setFaviconUrl('https://populated.example/favicon.ico');
        $feed->setSourceFormat(SourceFormat::SCRAPED);

        return $feed;
    }

    private function subscriptionFor(User $user, Feed $feed, Tag $tag): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-02T10:00:00Z'));
        $subscription->setCustomTitle('My populated feed');
        $subscription->setPosition(3);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T10:00:00Z'));
        $subscription->addTag($tag, 5);

        return $subscription;
    }

    private function entryFor(Feed $feed): Entry
    {
        $url = 'https://populated.example/article';
        $entry = new Entry(
            $feed,
            'populated-guid',
            $url,
            'An article with every field set',
            new \DateTimeImmutable('2026-08-02T10:00:00Z'),
            new \DateTimeImmutable('2026-08-03T10:00:00Z'),
            (new UrlNormalizer())->hash($url),
        );
        $entry->setAuthor('A. Author');
        $entry->setSummary('A summary of the article.');
        $entry->setContentHtml('<p>The body of the article.</p>');
        $entry->setImage('https://populated.example/lead.jpg', 1200, 630);
        $entry->setPublishedAt(new \DateTimeImmutable('2026-08-01T10:00:00Z'));

        return $entry;
    }

    private function stateFor(User $user, Entry $entry): EntryState
    {
        $state = new EntryState($user, $entry);
        $state->setIsFavorite(true);
        $state->setIsKept(true);
        $state->markRead(new \DateTimeImmutable('2026-08-04T10:00:00Z'));
        $state->markViewed(new \DateTimeImmutable('2026-08-05T10:00:00Z'));

        return $state;
    }
}
