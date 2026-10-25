import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { LayoutService } from '../../layout.service';
import { TagDto } from '../../models';
import { TagsStore } from '../../state/tags.store';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarTagRowMenuComponent } from './sidebar-tag-row-menu.component';

const tagOf = (id: number, parentId: number | null): TagDto => ({
  id,
  name: `t${id}`,
  color: null,
  icon: null,
  position: 0,
  parentId,
});

function openMenuFor(target: TagDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      SidebarRowActions,
      { provide: ActionSheet, useValue: {} },
      { provide: SavedSearchesStore, useValue: {} },
      { provide: LayoutService, useValue: { isCoarse: signal(false) } },
      { provide: ManageActions, useValue: {} },
      {
        provide: TagsStore,
        useValue: { tags: signal([tagOf(1, null), tagOf(2, 1), tagOf(3, 2)]) },
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
});
