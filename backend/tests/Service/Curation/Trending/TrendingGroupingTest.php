<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class TrendingGroupingTest extends DbTestCase
{
    private const string HEADLINE = 'Lighthouse keepers strike along the northern coast';

    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
    }

    public function testTheRunCarriesTheWindowStartItReadFrom(): void
    {
        self::assertSame('2031-05-13 12:00:00', $this->collect()->since->format('Y-m-d H:i:s'));
    }

    public function testAPostSharingAStoryCardTakesTheCardsHeadlineAndSite(): void
    {
        $article = $this->article();
        $post = $this->postCarding('https://www.shared.example/2031/lighthouse', self::HEADLINE);

        $run = $this->collect();

        self::assertArrayHasKey($article->requireId(), $run->headlines->sharedWeightsWithEarlier($post->requireId()));
        self::assertSame('shared.example', $run->readMember($post->requireId())->articleSite);
    }

    public function testAPostWhoseCardNamesNoStoryTakesNeitherItsTextNorItsSite(): void
    {
        $this->article();
        $post = $this->postCarding('https://shared.example/', self::HEADLINE);

        $run = $this->collect();

        self::assertSame([], $run->headlines->sharedWeightsWithEarlier($post->requireId()));
        self::assertNull($run->readMember($post->requireId())->articleSite);
    }

    public function testAnArticleEntryTakesItsOwnSite(): void
    {
        $article = $this->article();

        self::assertSame('outlet.example', $this->collect()->readMember($article->requireId())->articleSite);
    }

    public function testAPostAndItsCardKeepLinkingByTheCardsUrl(): void
    {
        $article = $this->article();
        $post = $this->postCarding('https://outlet.example/lighthouse', 'Unrelated words entirely');

        self::assertSame(
            [[$article->requireId(), $post->requireId()]],
            $this->collect()->collectEntriesSharingAUrlOrItem(),
        );
    }

    public function testCardsLinkByTheStoredHash(): void
    {
        $article = $this->article();
        $post = $this->postCarding('https://elsewhere.example/copy', 'Unrelated words entirely');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE shared_link SET url_hash = ? WHERE url = ?',
            [(new UrlNormalizer())->hash('https://outlet.example/lighthouse'), 'https://elsewhere.example/copy'],
        );

        self::assertSame(
            [[$article->requireId(), $post->requireId()]],
            $this->collect()->collectEntriesSharingAUrlOrItem(),
        );
    }

    public function testAResolvedShortLinkSharesTheOutletsUrl(): void
    {
        $article = $this->article();
        $post = $this->postCarding('https://n.pr/x', 'Unrelated words entirely');
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'resolved', resolved_url = ?, resolved_url_hash = ?",
            ['https://outlet.example/lighthouse', (new UrlNormalizer())->hash('https://outlet.example/lighthouse')],
        );

        $run = $this->collect();

        self::assertSame([[$article->requireId(), $post->requireId()]], $run->collectEntriesSharingAUrlOrItem());
        self::assertSame('outlet.example', $run->readMember($post->requireId())->articleSite);
    }

    public function testAMemberCarriesItsCardsLink(): void
    {
        $this->article();
        $post = $this->postCarding('https://outlet.example/lighthouse', self::HEADLINE);

        $run = $this->collect();

        self::assertSame(
            $this->entityManager->getConnection()->fetchOne('SELECT shared_link_id FROM link_card'),
            $run->readMember($post->requireId())->sharedLinkId,
        );
    }

    private function article(): Entry
    {
        $entry = $this->fixtures->article(
            $this->fixtures->feed('outlet'),
            self::HEADLINE,
            '2031-05-14 08:00:00',
            'https://outlet.example/lighthouse',
        );
        $this->fixtures->flush();

        return $entry;
    }

    private function postCarding(string $cardUrl, string $cardTitle): Entry
    {
        $post = $this->fixtures->entry(
            $this->fixtures->feed('poster', SocialNetwork::Mastodon),
            'Worth a read',
            '2031-05-14 09:00:00',
        );
        $this->fixtures->flush();
        $this->entityManager->persist(
            new LinkCard(
                $post,
                0,
                SharedLinks::of($this->entityManager, new LinkCardContent($cardUrl, $cardTitle, null, null, 'Shared')),
            ),
        );
        $this->entityManager->flush();

        return $post;
    }

    private function collect(): TrendingRun
    {
        return TrendingWiring::grouping($this->entityManager, new MockClock('2031-05-14 12:00:00', 'UTC'))
            ->collect($this->fixtures->owner()->requireId(), TrendingWindow::Day);
    }
}
