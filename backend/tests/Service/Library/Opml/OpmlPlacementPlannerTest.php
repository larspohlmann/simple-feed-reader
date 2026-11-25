<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Opml;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use App\Service\Library\Opml\OpmlPlacementPlanner;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class OpmlPlacementPlannerTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->user('opml-planner@example.test');
    }

    public function testAFolderPathBecomesItsDestination(): void
    {
        $root = $this->plan(null, self::folder('A', self::folder('B')));

        self::assertSame([], $root->destination);
        self::assertSame(['A'], $root->folders[0]->destination);
        self::assertSame(['A', 'B'], $root->folders[0]->folders[0]->destination);
    }

    public function testAParentTagLeadsEveryDestination(): void
    {
        $root = $this->plan('From Feedly', self::folder('A'));

        self::assertSame(['From Feedly'], $root->destination);
        self::assertSame(['From Feedly', 'A'], $root->folders[0]->destination);
    }

    public function testABlankParentIsNoParent(): void
    {
        $root = $this->plan('  ', self::folder('A'));

        self::assertSame([], $root->destination);
    }

    public function testAFolderNamedLikeATagElsewhereIsNumbered(): void
    {
        $this->tag('Tech');

        $root = $this->plan('From Feedly', self::folder('Tech'));

        self::assertSame(['From Feedly', 'Tech (1)'], $root->folders[0]->destination);
    }

    public function testAFolderAtItsTagsOwnSpotReusesIt(): void
    {
        $this->tag('Tech');

        $root = $this->plan(null, self::folder('Tech'));

        self::assertSame(['Tech'], $root->folders[0]->destination);
    }

    public function testASecondImportReusesTheNumberedTag(): void
    {
        $this->tag('Tech');
        $this->tag('Tech (1)', $this->tag('From Feedly'));

        $root = $this->plan('From Feedly', self::folder('Tech'));

        self::assertSame(['From Feedly', 'Tech (1)'], $root->folders[0]->destination);
    }

    public function testATakenNumberMovesToTheNext(): void
    {
        $this->tag('Tech');
        $this->tag('Tech (1)');

        $root = $this->plan('From Feedly', self::folder('Tech'));

        self::assertSame(['From Feedly', 'Tech (2)'], $root->folders[0]->destination);
    }

    public function testTwoFoldersOfOneNameInOneFileAreTwoTags(): void
    {
        $root = $this->plan(null, self::folder('A', self::folder('Tech')), self::folder('B', self::folder('Tech')));

        self::assertSame(['A', 'Tech'], $root->folders[0]->folders[0]->destination);
        self::assertSame(['B', 'Tech (1)'], $root->folders[1]->folders[0]->destination);
    }

    public function testSiblingFoldersOfOneNameShareATag(): void
    {
        $root = $this->plan(null, self::folder('Tech'), self::folder('Tech'));

        self::assertSame(['Tech'], $root->folders[0]->destination);
        self::assertSame(['Tech'], $root->folders[1]->destination);
    }

    public function testANumberedNameAlreadyPlannedIsNotReused(): void
    {
        $this->tag('Tech');

        $root = $this->plan('P', self::folder('Tech'), self::folder('Tech (1)'));

        self::assertSame(['P', 'Tech (1)'], $root->folders[0]->destination);
        self::assertSame(['P', 'Tech (1) (1)'], $root->folders[1]->destination);
    }

    public function testNamesMatchWhateverTheirCase(): void
    {
        $this->tag('tech');

        $root = $this->plan('P', self::folder('Tech'));

        self::assertSame(['P', 'Tech (1)'], $root->folders[0]->destination);
    }

    public function testAReusedTagKeepsItsStoredSpelling(): void
    {
        $this->tag('tech');

        $root = $this->plan(null, self::folder('Tech'));

        self::assertSame(['tech'], $root->folders[0]->destination);
    }

    public function testAnAccentMakesADifferentName(): void
    {
        $this->tag('Café');

        $root = $this->plan('P', self::folder('Cafe'));

        self::assertSame(['P', 'Cafe'], $root->folders[0]->destination);
    }

    public function testAFolderBelowTheThirdLevelIsDropped(): void
    {
        $root = $this->plan(null, self::folder('A', self::folder('B', self::folder('C', self::folder('D')))));

        $third = $root->folders[0]->folders[0]->folders[0];
        self::assertFalse($third->dropped);
        self::assertSame(['A', 'B', 'C'], $third->destination);
        self::assertTrue($third->folders[0]->dropped);
        self::assertSame(['A', 'B', 'C'], $third->folders[0]->destination);
    }

    public function testAParentTagCostsTheFileOneLevel(): void
    {
        $root = $this->plan('P', self::folder('A', self::folder('B', self::folder('C'))));

        $second = $root->folders[0]->folders[0];
        self::assertFalse($second->dropped);
        self::assertSame(['P', 'A', 'B'], $second->destination);
        self::assertTrue($second->folders[0]->dropped);
        self::assertSame(['P', 'A', 'B'], $second->folders[0]->destination);
    }

    public function testATypedParentNamedLikeANestedTagIsNumbered(): void
    {
        $this->tag('Reading', $this->tag('Shelf'));

        $root = $this->plan('Reading');

        self::assertSame(['Reading (1)'], $root->destination);
    }

    public function testAParentNamedLikeATopLevelTagIsThatTag(): void
    {
        $this->tag('Reading');

        $root = $this->plan('reading');

        self::assertSame(['Reading'], $root->destination);
    }

    public function testASuffixedNameStaysWithinTheColumn(): void
    {
        $this->tag(str_repeat('a', 100));

        $root = $this->plan('P', self::folder(str_repeat('a', 100)));

        self::assertSame(['P', str_repeat('a', 96) . ' (1)'], $root->folders[0]->destination);
    }

    public function testALongFolderNameIsCutToTheColumn(): void
    {
        $root = $this->plan(null, self::folder(str_repeat('a', 100) . 'b'));

        self::assertSame([str_repeat('a', 100)], $root->folders[0]->destination);
    }

    public function testAFolderTwoLevelsTooDeepTakesTheLastKeptDestination(): void
    {
        $fifth = self::folder('A', self::folder('B', self::folder('C', self::folder('D', self::folder('E')))));

        $root = $this->plan(null, $fifth);

        $dropped = $root->folders[0]->folders[0]->folders[0]->folders[0]->folders[0];
        self::assertTrue($dropped->dropped);
        self::assertSame(['A', 'B', 'C'], $dropped->destination);
    }

    public function testFeedsStayWithTheirFolder(): void
    {
        $feeds = [new OpmlFeedModel('https://a.example/feed.xml', 'A feed', 'rss')];

        $root = $this->plan(null, new OpmlFolderModel('A', $feeds, []));

        self::assertSame($feeds, $root->folders[0]->feeds);
    }

    public function testARenamedFolderTakesTheNewName(): void
    {
        $root = $this->planRenamed(null, ['0' => 'Alpha'], self::folder('A', self::folder('B')));

        self::assertSame(['Alpha'], $root->folders[0]->destination);
        self::assertSame(['Alpha', 'B'], $root->folders[0]->folders[0]->destination);
        self::assertSame('A', $root->folders[0]->name);
    }

    public function testANestedFolderIsRenamedByItsPosition(): void
    {
        $root = $this->planRenamed(null, ['0.0' => 'Beta'], self::folder('A', self::folder('B')));

        self::assertSame(['A', 'Beta'], $root->folders[0]->folders[0]->destination);
    }

    public function testARenameOntoATakenTagNameIsNumbered(): void
    {
        $this->tag('Tech', $this->tag('Other'));

        $root = $this->planRenamed('P', ['0' => 'Tech'], self::folder('X'));

        self::assertSame(['P', 'Tech (1)'], $root->folders[0]->destination);
    }

    public function testARenameOntoAStoredTagAtTheSameSpotReusesIt(): void
    {
        $this->tag('Tech');

        $root = $this->planRenamed(null, ['0' => 'tech'], self::folder('X'));

        self::assertSame(['Tech'], $root->folders[0]->destination);
    }

    public function testAThirdLevelFolderIsRenamedByItsPosition(): void
    {
        $tree = self::folder('A', self::folder('B', self::folder('C')));

        $root = $this->planRenamed(null, ['0.0.0' => 'Gamma'], $tree);

        self::assertSame(['A', 'B', 'Gamma'], $root->folders[0]->folders[0]->folders[0]->destination);
    }

    public function testARenameOntoASiblingsNameMergesThem(): void
    {
        $root = $this->planRenamed(null, ['1' => 'a'], self::folder('A'), self::folder('B'));

        self::assertSame(['A'], $root->folders[0]->destination);
        self::assertSame(['A'], $root->folders[1]->destination);
    }

    public function testAnUnknownPositionIsIgnored(): void
    {
        $root = $this->planRenamed(null, ['7' => 'Z', '0.9' => 'Y'], self::folder('A'));

        self::assertSame(['A'], $root->folders[0]->destination);
    }

    public function testPlanningWritesNothing(): void
    {
        $this->plan('P', self::folder('A'));
        $this->entityManager->flush();

        self::assertSame(0, $this->tagRepository()->countForUser($this->owner->requireId()));
    }

    private function plan(?string $parent, OpmlFolderModel ...$folders): OpmlPlacedFolderModel
    {
        return $this->planRenamed($parent, [], ...$folders);
    }

    /** @param array<int|string, string> $folderNames */
    private function planRenamed(
        ?string $parent,
        array $folderNames,
        OpmlFolderModel ...$folders,
    ): OpmlPlacedFolderModel {
        $root = new OpmlFolderModel(null, [], array_values($folders));
        $choice = new OpmlPlacementChoiceModel($parent, $folderNames);

        return new OpmlPlacementPlanner($this->tagRepository())->plan($this->owner, $root, $choice);
    }

    private static function folder(string $name, OpmlFolderModel ...$folders): OpmlFolderModel
    {
        return new OpmlFolderModel($name, [], array_values($folders));
    }

    private function tag(string $name, ?Tag $parent = null): Tag
    {
        $tag = new Tag($this->owner, $name);
        $tag->setParent($parent);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function tagRepository(): TagRepository
    {
        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);

        return $tags;
    }
}
