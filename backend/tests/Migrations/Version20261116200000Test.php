<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\LinkCardContent;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261116200000;
use Psr\Log\NullLogger;

final class Version20261116200000Test extends DbTestCase
{
    private const string LANDING = 'https://www.the-independent.com/news/article.html';

    public function testAResolvedShortLinkNamedAfterItsOwnHostTakesItsLandingsHost(): void
    {
        $linkId = $this->linkNamed('https://trib.al/P3VsbgA', 'trib.al');
        $this->resolve($linkId);

        self::assertSame(1, $this->migrateUp());

        self::assertSame('the-independent.com', $this->siteNameOf($linkId));
    }

    public function testASuppliedSiteNameIsKept(): void
    {
        $linkId = $this->linkNamed('https://trib.al/abc', 'The Independent');
        $this->resolve($linkId);

        self::assertSame(0, $this->migrateUp());

        self::assertSame('The Independent', $this->siteNameOf($linkId));
    }

    public function testALinkThatDidNotResolveIsKept(): void
    {
        $linkId = $this->linkNamed('https://bit.ly/abc', 'bit.ly');
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'unchanged' WHERE shared_link_id = ?",
            [$linkId],
        );

        self::assertSame(0, $this->migrateUp());

        self::assertSame('bit.ly', $this->siteNameOf($linkId));
    }

    private function linkNamed(string $url, string $siteName): int
    {
        return SharedLinks::of(
            $this->entityManager,
            new LinkCardContent($url, 'Title', null, null, $siteName),
        )->requireId();
    }

    private function resolve(int $linkId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'resolved', resolved_url = ?, resolved_url_hash = ?"
            . ' WHERE shared_link_id = ?',
            [self::LANDING, hash('sha256', self::LANDING), $linkId],
        );
    }

    /** @return int the statements the migration ran */
    private function migrateUp(): int
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261116200000.php';
        $connection = $this->entityManager->getConnection();
        $migration = new Version20261116200000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }

        return \count($migration->getSql());
    }

    private function siteNameOf(int $linkId): mixed
    {
        return $this->entityManager->getConnection()
            ->fetchOne('SELECT site_name FROM shared_link WHERE id = ?', [$linkId]);
    }
}
