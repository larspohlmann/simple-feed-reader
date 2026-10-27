<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\DatabaseSavedSearchMatcher;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Container;

final readonly class TranscriptSearchFixtures
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function installDatabaseMatching(Container $container): RecordingSearchIndexWriter
    {
        $container->set(SavedSearchMatcherInterface::class, $container->get(DatabaseSavedSearchMatcher::class));
        $indexWriter = new RecordingSearchIndexWriter();
        $container->set(SearchIndexWriterInterface::class, $indexWriter);

        return $indexWriter;
    }

    public function searchForTranscriptOnlyTerm(): SavedSearch
    {
        $user = new User('rematch@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $search = new SavedSearch($user, 'Hallo', false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();

        return $search;
    }

    /** @return list<int> */
    public function memberEntryIds(SavedSearch $search): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? ORDER BY entry_id',
            [$search->getId()],
        );

        return array_map(intval(...), $ids);
    }
}
