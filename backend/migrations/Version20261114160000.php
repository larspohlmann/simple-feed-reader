<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Library\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Social\Support\BlueskyProfileFeed;
use App\Service\Social\Social\Support\LemmyProfileFeed;
use App\Service\Social\Social\Support\MastodonProfileFeed;
use App\Service\Social\Social\Support\SocialFeedUrl;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classify Lemmy community RSS feeds as network feeds (#2218).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();

        /** @var list<array{id: int|string, url: string, source_format: string}> $candidates */
        $candidates = $this->connection->fetchAllAssociative(
            "SELECT id, url, source_format FROM feed WHERE network IS NULL AND url LIKE '%/feeds/c/%'",
        );
        $lemmyIds = [];
        foreach ($candidates as $candidate) {
            if (SocialNetwork::Lemmy === self::networkOf($candidate['url'], $candidate['source_format'])) {
                $lemmyIds[] = (int) $candidate['id'];
            }
        }
        if ([] === $lemmyIds) {
            return;
        }
        $this->addSql(\sprintf("UPDATE feed SET network = 'lemmy' WHERE id IN (%s)", implode(', ', $lemmyIds)));
    }

    public function down(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();
        $this->addSql("UPDATE feed SET network = NULL WHERE network = 'lemmy' AND source_format = 'xml'");
    }

    private static function networkOf(string $url, string $sourceFormat): ?SocialNetwork
    {
        if (SourceFormat::isSaved($sourceFormat)) {
            return SavedFeedUrl::networkOf($url);
        }
        $privateAddress = SourceFormat::isSocial($sourceFormat) ? SocialFeedUrl::addressOf($url) : null;

        return $privateAddress->network ?? self::profileFeedNetworkOf($url);
    }

    private static function profileFeedNetworkOf(string $url): ?SocialNetwork
    {
        return match (true) {
            null !== BlueskyProfileFeed::parseIdentity($url) => SocialNetwork::Bluesky,
            null !== MastodonProfileFeed::parseIdentity($url) => SocialNetwork::Mastodon,
            null !== LemmyProfileFeed::parseIdentity($url) => SocialNetwork::Lemmy,
            default => null,
        };
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
