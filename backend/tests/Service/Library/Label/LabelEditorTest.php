<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Label;

use App\Repository\LabelRepository;
use App\Service\Library\Label\Exception\LabelNameTakenException;
use App\Service\Library\Label\LabelEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class LabelEditorTest extends DbTestCase
{
    use SeedsUsers;

    public function testEnsureNamedReturnsTheExistingLabelForACaseVariant(): void
    {
        $user = $this->user('label-ensure-existing@example.com');
        $existing = $this->editor()->create($user, 'Work');

        $ensured = $this->editor()->ensureNamed($user, 'WORK');

        self::assertSame($existing->requireId(), $ensured->requireId());
        self::assertCount(2, $this->labels()->countTalliesForUser($user->requireId())->labels);
    }

    public function testEnsureNamedCreatesWhenAbsent(): void
    {
        $user = $this->user('label-ensure-new@example.com');

        $ensured = $this->editor()->ensureNamed($user, 'Fresh');

        self::assertSame('Fresh', $ensured->getName());
        $found = $this->labels()->findForUserByName($user->requireId(), 'fresh');
        self::assertSame($ensured->requireId(), $found?->requireId());
    }

    public function testCreateAndRenameWorkThroughTheNameLock(): void
    {
        $user = $this->user('label-lock-flow@example.com');
        $created = $this->editor()->create($user, 'Work');

        $this->editor()->rename($created, 'Office');

        self::assertSame('Office', $created->getName());
        $this->expectException(LabelNameTakenException::class);
        $this->editor()->create($user, 'office');
    }

    private function editor(): LabelEditor
    {
        $editor = self::getContainer()->get(LabelEditor::class);
        self::assertInstanceOf(LabelEditor::class, $editor);

        return $editor;
    }

    private function labels(): LabelRepository
    {
        $labels = self::getContainer()->get(LabelRepository::class);
        self::assertInstanceOf(LabelRepository::class, $labels);

        return $labels;
    }
}
