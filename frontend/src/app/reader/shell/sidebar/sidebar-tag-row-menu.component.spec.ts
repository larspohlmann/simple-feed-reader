import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { TagActions } from '../../feeds/manage/tag-actions.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { AllItemsNetworksStore } from '../../state/all-items-networks.store';
import { ForYouNetworksStore } from '../../state/for-you-networks.store';
import { TrendingNetworksStore } from '../../state/trending-networks.store';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { LayoutService } from '../../layout.service';
import { TagDto } from '../../models';
import { TagsStore } from '../../state/tags.store';
import { tagsStoreStub } from '../../testing/tags-store.stub';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarTagRowMenuComponent } from './sidebar-tag-row-menu.component';
import { INCLUDED_EVERYWHERE } from '../../testing/tag.factory';

const tagOf = (id: number, parentId: number | null): TagDto => ({
  id,
  name: `t${id}`,
  color: null,
  icon: null,
  position: 0,
  parentId,
  ...INCLUDED_EVERYWHERE,
});

const setTagInclusion = jest.fn();

function openMenuFor(target: TagDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      SidebarRowActions,
      { provide: ActionSheet, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: ForYouNetworksStore, useValue: {} },
      { provide: AllItemsNetworksStore, useValue: {} },
      { provide: TrendingNetworksStore, useValue: {} },
      { provide: SubscriptionsStore, useValue: { subscriptions: signal([]) } },
      { provide: LayoutService, useValue: { isCoarse: signal(false) } },
      { provide: ManageActions, useValue: {} },
      { provide: TagActions, useValue: { setTagInclusion } },
      {
        provide: TagsStore,
        useValue: tagsStoreStub(signal([tagOf(1, null), tagOf(2, 1), tagOf(3, 2)])),
      },
    ],
  });
  const fixture = TestBed.createComponent(SidebarTagRowMenuComponent);
  fixture.componentRef.setInput('tag', target);
  fixture.detectChanges();
  TestBed.inject(SidebarRowActions).menuFor.set(`tag-${target.id}`);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const itemTexts = (host: HTMLElement): (string | undefined)[] =>
  [...host.querySelectorAll('[role="menuitem"]')].map((item) => item.textContent?.trim());

describe('SidebarTagRowMenuComponent', () => {
  it('offers Create tag here on a root', () => {
    expect(itemTexts(openMenuFor(tagOf(1, null)))).toContain('Create tag here');
  });

  it('offers Create tag here on a child, which still has a level below it', () => {
    expect(itemTexts(openMenuFor(tagOf(2, 1)))).toContain('Create tag here');
  });

  it('offers no Create tag here on a grandchild', () => {
    expect(itemTexts(openMenuFor(tagOf(3, 2)))).not.toContain('Create tag here');
  });

  it('lists the four switches as checkboxes between the actions and Delete', () => {
    const pop = openMenuFor(tagOf(1, null)).querySelector('.pop')!;
    const items = [...pop.children].map((item) =>
      item.tagName === 'HR' ? '<hr>' : item.textContent?.trim(),
    );

    expect(items).toEqual([
      'Edit tag',
      'Create tag here',
      '<hr>',
      'Show in All items',
      'Show in For You',
      'Show in saved searches',
      'Show in Trending',
      '<hr>',
      'Delete tag',
    ]);
    expect(
      [...pop.querySelectorAll<HTMLInputElement>('[role="menuitemcheckbox"]')].map(
        (box) => box.checked,
      ),
    ).toEqual([true, true, true, true]);
  });

  it('switches the tag from a checkbox and keeps the menu open', () => {
    setTagInclusion.mockClear();
    const host = openMenuFor(tagOf(1, null));

    host.querySelectorAll<HTMLInputElement>('[role="menuitemcheckbox"]')[1].click();

    expect(setTagInclusion).toHaveBeenCalledWith(tagOf(1, null), 'forYou', false);
    expect(TestBed.inject(SidebarRowActions).menuFor()).toBe('tag-1');
  });

  it('steps focus to the next item on ArrowDown', () => {
    const pop = openMenuFor(tagOf(1, null)).querySelector<HTMLElement>('[role="menu"]')!;
    const items = [
      ...pop.querySelectorAll<HTMLElement>('[role="menuitem"], [role="menuitemcheckbox"]'),
    ];
    items[1].focus();

    items[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));

    expect(document.activeElement).toBe(items[2]);
  });
});
