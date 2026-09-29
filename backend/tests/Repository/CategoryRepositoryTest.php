<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Service\Category\Model\NormalizedCategoryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class CategoryRepositoryTest extends DbTestCase
{
    private CategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var CategoryRepository $repository */
        $repository = $this->entityManager->getRepository(Category::class);
        $this->repository = $repository;
    }

    public function testEmptyInputReturnsEmptyArrayWithoutQueryingTheDatabase(): void
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $result = $this->repository->findExistingByIdentities([]);

        self::assertSame([], $result);
        self::assertSame(
            [],
            $recorder->queries(),
            'an empty input must short-circuit before hitting the database',
        );
    }

    public function testReturnsExistingCategoriesKeyedByTheirIdentity(): void
    {
        $politics = new Category('politics', 'https://a.test');
        $world = new Category('world', 'https://b.test');
        $this->entityManager->persist($politics);
        $this->entityManager->persist($world);
        $this->entityManager->flush();

        $normalizedPolitics = new NormalizedCategoryModel('politics', 'Politics', 'https://a.test');
        $normalizedWorld = new NormalizedCategoryModel('world', 'World', 'https://b.test');

        $resolved = $this->repository->findExistingByIdentities([$normalizedPolitics, $normalizedWorld]);

        self::assertCount(2, $resolved);
        self::assertSame($politics, $resolved[$normalizedPolitics->identity()]);
        self::assertSame($world, $resolved[$normalizedWorld->identity()]);
    }

    public function testUnknownIdentityIsAbsentFromTheResult(): void
    {
        $existing = new Category('politics', '');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $unknown = new NormalizedCategoryModel('unknown', 'Unknown', '');
        $resolved = $this->repository->findExistingByIdentities([
            new NormalizedCategoryModel('politics', 'Politics', ''),
            $unknown,
        ]);

        self::assertCount(1, $resolved);
        self::assertArrayNotHasKey($unknown->identity(), $resolved);
    }

    public function testSameCanonicalKeyDifferentSchemeStaySeparate(): void
    {
        $schemeA = new Category('politics', 'https://a.test');
        $schemeB = new Category('politics', 'https://b.test');
        $this->entityManager->persist($schemeA);
        $this->entityManager->persist($schemeB);
        $this->entityManager->flush();

        $normalizedA = new NormalizedCategoryModel('politics', 'Politics', 'https://a.test');
        $normalizedB = new NormalizedCategoryModel('politics', 'Politics', 'https://b.test');

        $resolved = $this->repository->findExistingByIdentities([$normalizedA, $normalizedB]);

        self::assertSame($schemeA, $resolved[$normalizedA->identity()]);
        self::assertSame($schemeB, $resolved[$normalizedB->identity()]);
    }
}
