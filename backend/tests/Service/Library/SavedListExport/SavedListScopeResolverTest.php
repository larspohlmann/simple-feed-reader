<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\SavedListExport;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\LabelRepository;
use App\Service\Library\SavedListExport\SavedListScopeResolver;
use App\Tests\Support\NamesLabels;
use App\Tests\Support\SeedsUsers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SavedListScopeResolverTest extends KernelTestCase
{
    use NamesLabels;
    use SeedsUsers;

    private SavedListScopeResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $labels = self::getContainer()->get(LabelRepository::class);
        self::assertInstanceOf(LabelRepository::class, $labels);
        $this->resolver = new SavedListScopeResolver($labels);
    }

    public function testAWholeListHasNoLabel(): void
    {
        $user = $this->user('export-scope-whole@example.com');

        $scope = $this->resolver->requireScope($user->requireId(), 'kept', null, null);

        self::assertSame(EntryView::Kept, $scope->view);
        self::assertNull($scope->labelScope);
        self::assertSame('Kept', $scope->exportName());
    }

    public function testALabelCarriesItsName(): void
    {
        $user = $this->user('export-scope-label@example.com');
        $label = $this->labelNamed($user, 'Reading list');

        $scope = $this->resolver->requireScope($user->requireId(), 'kept', (string) $label->requireId(), null);

        self::assertSame('Reading list', $scope->labelName);
        self::assertSame($label->requireId(), $scope->labelId);
        self::assertSame('Kept – Reading list', $scope->exportName());
    }

    public function testUnlabeledIsNamedUnlabeled(): void
    {
        $user = $this->user('export-scope-unlabeled@example.com');

        $scope = $this->resolver->requireScope($user->requireId(), 'favorites', 'unlabeled', null);

        self::assertTrue($scope->isUnlabeled());
        self::assertSame('Favorites – Unlabeled', $scope->exportName());
    }

    public function testTheAllViewIsRefused(): void
    {
        $user = $this->user('export-scope-all@example.com');

        $this->expectViewRefusal(fn () => $this->resolver->requireScope($user->requireId(), 'all', null, null));
    }

    public function testAMissingViewIsRefused(): void
    {
        $user = $this->user('export-scope-missing@example.com');

        $this->expectViewRefusal(fn () => $this->resolver->requireScope($user->requireId(), null, null, null));
    }

    public function testAnotherUsersLabelIsNotFound(): void
    {
        $user = $this->user('export-scope-own@example.com');
        $stranger = $this->user('export-scope-stranger@example.com');
        $foreignLabel = $this->labelNamed($stranger, 'Theirs');

        $this->expectException(RecordNotFoundException::class);

        $this->resolver->requireScope($user->requireId(), 'kept', (string) $foreignLabel->requireId(), null);
    }

    public function testOldestFirstIsRead(): void
    {
        $user = $this->user('export-scope-order@example.com');

        $scope = $this->resolver->requireScope($user->requireId(), 'favorites', null, 'asc');

        self::assertSame(ListOrder::OldestFirst, $scope->order);
    }

    private function expectViewRefusal(callable $resolve): void
    {
        try {
            $resolve();
            self::fail('The view should have been refused.');
        } catch (ValidationException $refusal) {
            self::assertSame(['view' => ['An export needs view=favorites or view=kept.']], $refusal->errors);
        }
    }
}
