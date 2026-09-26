<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Dto\SavedSearch\UpdateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Service\Search\SavedSearchEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedSearchEditorTest extends DbTestCase
{
    public function testSavingANewTermCreatesItWithItsSlug(): void
    {
        $user = $this->user('search-saver@example.com');

        $outcome = $this->editor()->save($user, new CreateSavedSearchRequest('climate change'));

        self::assertTrue($outcome->isNew);
        $id = $outcome->savedSearch->requireId();
        self::assertSame($id . '-climate-change', $this->reload($outcome->savedSearch)->getSlug());
    }

    public function testSavingAnAlreadySavedTermReturnsTheExistingRow(): void
    {
        $user = $this->user('search-resaver@example.com');
        $first = $this->editor()->save($user, new CreateSavedSearchRequest('punk', true));

        $again = $this->editor()->save($user, new CreateSavedSearchRequest('punk', true));

        self::assertFalse($again->isNew);
        self::assertSame($first->savedSearch->requireId(), $again->savedSearch->requireId());
    }

    public function testChangeDigestInclusionPersists(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-digest@example.com'), new CreateSavedSearchRequest('opera'))
            ->savedSearch;
        $wanted = !$savedSearch->isIncludeInDigest();

        $this->editor()->changeDigestInclusion($savedSearch, new UpdateSavedSearchRequest($wanted));

        self::assertSame($wanted, $this->reload($savedSearch)->isIncludeInDigest());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-deleter@example.com'), new CreateSavedSearchRequest('jazz'))
            ->savedSearch;
        $id = $savedSearch->requireId();

        $this->editor()->delete($savedSearch);

        $this->em->clear();
        self::assertNull($this->em->find(SavedSearch::class, $id));
    }

    private function editor(): SavedSearchEditor
    {
        $editor = self::getContainer()->get(SavedSearchEditor::class);
        self::assertInstanceOf(SavedSearchEditor::class, $editor);

        return $editor;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reload(SavedSearch $savedSearch): SavedSearch
    {
        $id = $savedSearch->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(SavedSearch::class, $id);
        self::assertInstanceOf(SavedSearch::class, $reloaded);

        return $reloaded;
    }
}
