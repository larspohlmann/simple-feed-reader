<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\SavedSearch;
use App\Service\Search\SavedSearchDefinition;
use App\Service\Search\SavedSearchEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;

final class SavedSearchEditorTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testSavingANewTermCreatesItWithItsSlug(): void
    {
        $user = $this->user('search-saver@example.com');

        $outcome = $this->editor()->save($user, new SavedSearchDefinition('climate change'));

        self::assertTrue($outcome->isNew);
        $id = $outcome->savedSearch->requireId();
        self::assertSame($id . '-climate-change', $this->reload($outcome->savedSearch)->getSlug());
    }

    public function testSavingAnAlreadySavedTermReturnsTheExistingRow(): void
    {
        $user = $this->user('search-resaver@example.com');
        $first = $this->editor()->save($user, new SavedSearchDefinition('punk', true));

        $again = $this->editor()->save($user, new SavedSearchDefinition('punk', true));

        self::assertFalse($again->isNew);
        self::assertSame($first->savedSearch->requireId(), $again->savedSearch->requireId());
    }

    public function testChangeDigestInclusionPersists(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-digest@example.com'), new SavedSearchDefinition('opera'))
            ->savedSearch;
        $wanted = !$savedSearch->isIncludeInDigest();

        $this->editor()->changeDigestInclusion($savedSearch, $wanted);

        self::assertSame($wanted, $this->reload($savedSearch)->isIncludeInDigest());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-deleter@example.com'), new SavedSearchDefinition('jazz'))
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
}
