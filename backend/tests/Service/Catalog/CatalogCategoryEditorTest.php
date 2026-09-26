<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Catalog\CatalogCategoryEditor;
use App\Tests\DbTestCase;

final class CatalogCategoryEditorTest extends DbTestCase
{
    public function testCreateAppendsACategoryWithTheRequestedFields(): void
    {
        $first = $this->editor()->create(new CatalogCategoryRequest('editor_first', 'First', 'star', '#112233'));

        $second = $this->editor()->create(
            new CatalogCategoryRequest('editor_second', 'Second', 'bolt', '#445566', false, false),
        );

        $reloaded = $this->reload($second);
        self::assertSame('editor_second', $reloaded->getKey());
        self::assertSame('Second', $reloaded->getName());
        self::assertSame('bolt', $reloaded->getIcon());
        self::assertSame('#445566', $reloaded->getColor());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
        self::assertSame($first->getPosition() + 1, $reloaded->getPosition());
    }

    public function testCreateCanLockACategory(): void
    {
        $category = $this->editor()->create(
            new CatalogCategoryRequest('editor_locked', 'Locked', 'star', '#112233', locked: true),
        );

        self::assertTrue($this->reload($category)->isLocked());
    }

    public function testUpdateRewritesEveryEditableFieldButTheKey(): void
    {
        $category = $this->editor()->create(new CatalogCategoryRequest('editor_update', 'Before', 'star', '#000000'));

        $this->editor()->update(
            $category,
            new CatalogCategoryRequest('ignored_key', 'After', 'bolt', '#ffffff', false, false),
        );

        $reloaded = $this->reload($category);
        self::assertSame('editor_update', $reloaded->getKey());
        self::assertSame('After', $reloaded->getName());
        self::assertSame('bolt', $reloaded->getIcon());
        self::assertSame('#ffffff', $reloaded->getColor());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
    }

    public function testDeleteRemovesTheCategory(): void
    {
        $category = $this->editor()->create(new CatalogCategoryRequest('editor_delete', 'Doomed', 'star'));
        $id = $category->requireId();

        $this->editor()->delete($category);

        $this->em->clear();
        self::assertNull($this->em->find(CatalogCategory::class, $id));
    }

    public function testReorderGivesEachCategoryItsIndex(): void
    {
        $first = $this->editor()->create(new CatalogCategoryRequest('editor_order_a', 'A', 'star'));
        $second = $this->editor()->create(new CatalogCategoryRequest('editor_order_b', 'B', 'star'));

        $this->editor()->reorder(new ReorderRequest([$second->requireId(), $first->requireId()]));

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    public function testReorderRefusesAnUnknownCategory(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->editor()->reorder(new ReorderRequest([999999]));
    }

    private function editor(): CatalogCategoryEditor
    {
        $editor = self::getContainer()->get(CatalogCategoryEditor::class);
        self::assertInstanceOf(CatalogCategoryEditor::class, $editor);

        return $editor;
    }

    private function reload(CatalogCategory $category): CatalogCategory
    {
        $id = $category->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(CatalogCategory::class, $id);
        self::assertInstanceOf(CatalogCategory::class, $reloaded);

        return $reloaded;
    }
}
