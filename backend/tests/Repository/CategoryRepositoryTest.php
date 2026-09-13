<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Service\Category\NormalizedCategory;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class CategoryRepositoryTest extends DbTestCase
{
    private CategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var CategoryRepository $repository */
        $repository = $this->em->getRepository(Category::class);
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
        $this->em->persist($politics);
        $this->em->persist($world);
        $this->em->flush();

        $normalizedPolitics = new NormalizedCategory('politics', 'Politics', 'https://a.test');
        $normalizedWorld = new NormalizedCategory('world', 'World', 'https://b.test');

        $resolved = $this->repository->findExistingByIdentities([$normalizedPolitics, $normalizedWorld]);

        self::assertCount(2, $resolved);
        self::assertSame($politics, $resolved[$normalizedPolitics->identity()]);
        self::assertSame($world, $resolved[$normalizedWorld->identity()]);
    }

    public function testUnknownIdentityIsAbsentFromTheResult(): void
    {
        $existing = new Category('politics', '');
        $this->em->persist($existing);
        $this->em->flush();

        $unknown = new NormalizedCategory('unknown', 'Unknown', '');
        $resolved = $this->repository->findExistingByIdentities([
            new NormalizedCategory('politics', 'Politics', ''),
            $unknown,
        ]);

        self::assertCount(1, $resolved);
        self::assertArrayNotHasKey($unknown->identity(), $resolved);
    }

    public function testSameCanonicalKeyDifferentSchemeStaySeparate(): void
    {
        $schemeA = new Category('politics', 'https://a.test');
        $schemeB = new Category('politics', 'https://b.test');
        $this->em->persist($schemeA);
        $this->em->persist($schemeB);
        $this->em->flush();

        $normalizedA = new NormalizedCategory('politics', 'Politics', 'https://a.test');
        $normalizedB = new NormalizedCategory('politics', 'Politics', 'https://b.test');

        $resolved = $this->repository->findExistingByIdentities([$normalizedA, $normalizedB]);

        self::assertSame($schemeA, $resolved[$normalizedA->identity()]);
        self::assertSame($schemeB, $resolved[$normalizedB->identity()]);
    }
}
