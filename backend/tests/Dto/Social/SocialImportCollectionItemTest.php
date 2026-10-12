<?php

declare(strict_types=1);

namespace App\Tests\Dto\Social;

use App\Dto\Social\SocialImportCollectionItem;
use App\Enum\SocialFeedKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SocialImportCollectionItemTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function lengths(): iterable
    {
        yield 'an id of 512 characters' => [str_repeat('i', 512), 'Name', 0];
        yield 'an id of 513 characters' => [str_repeat('i', 513), 'Name', 1];
        yield 'a name of 512 characters' => ['at://list/a', str_repeat('n', 512), 0];
        yield 'a name of 513 characters' => ['at://list/a', str_repeat('n', 513), 1];
    }

    #[DataProvider('lengths')]
    public function testAnIdOrNameHoldsAtMost512Characters(string $id, string $name, int $violations): void
    {
        self::assertCount($violations, $this->validator->validate(new SocialImportCollectionItem('list', $id, $name)));
    }

    public function testTheIdAndNameLoseTheSpaceAroundThem(): void
    {
        $privateFeed = (new SocialImportCollectionItem('list', ' at://list/a ', ' Writers '))->toPrivateFeed();

        self::assertSame(SocialFeedKind::UserList, $privateFeed->kind);
        self::assertSame('at://list/a', $privateFeed->remoteId);
        self::assertSame('Writers', $privateFeed->name);
    }
}
