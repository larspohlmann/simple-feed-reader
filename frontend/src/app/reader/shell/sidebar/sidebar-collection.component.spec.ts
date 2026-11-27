import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Router, provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { LayoutService } from '../../layout.service';
import { LabelCollection, LabelTally, UnlabeledTally } from '../../labels/labels.models';
import { LabelsStore } from '../../labels/labels.store';
import { Selection } from '../../query/query';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SidebarCollectionComponent } from './sidebar-collection.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

const tally = (id: number, name: string, over: Partial<LabelTally> = {}): LabelTally => ({
  id,
  name,
  favoriteCount: 1,
  keptCount: 1,
  articleCount: 2,
  ...over,
});

describe('SidebarCollectionComponent (#2581)', () => {
  const labels = signal<LabelTally[]>([]);
  const unlabeled = signal<UnlabeledTally>({ favoriteCount: 0, keptCount: 0 });
  const rename = jest.fn();
  const remove = jest.fn();
  const ask = jest.fn();
  const showProblem = jest.fn();
  const openSheet = jest.fn();
  const menuFor = signal<string | null>(null);
  const coarse = signal(false);

  function mount(
    collection: LabelCollection,
    selection: Selection = { kind: 'all', unread: false },
  ): ComponentFixture<SidebarCollectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SidebarCollectionComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([{ path: '**', children: [] }]),
        { provide: LabelsStore, useValue: { labels, unlabeled, rename, remove } },
        {
          provide: SubscriptionsStore,
          useValue: { favoritesCount: signal(7), keptCount: signal(0) },
        },
        { provide: ConfirmService, useValue: { ask } },
        { provide: ToastService, useValue: { showProblem } },
        { provide: ActionSheet, useValue: { open: openSheet } },
        { provide: LayoutService, useValue: { isCoarse: coarse } },
        {
          provide: SidebarRowActions,
          useValue: {
            menuFor,
            toggleMenu: jest.fn(),
            closeMenu: jest.fn(() => menuFor.set(null)),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(SidebarCollectionComponent);
    fixture.componentRef.setInput('collection', collection);
    fixture.componentRef.setInput('selection', selection);
    fixture.detectChanges();
    return fixture;
  }

  const root = (fixture: ComponentFixture<unknown>): HTMLElement => fixture.nativeElement;
  const toggle = (fixture: ComponentFixture<unknown>): void => {
    root(fixture).querySelector<HTMLButtonElement>('.chevzone')?.click();
    fixture.detectChanges();
  };
  const rowNames = (fixture: ComponentFixture<unknown>): string[] =>
    Array.from(root(fixture).querySelectorAll('.label-row .name')).map(
      (name) => name.textContent?.trim() ?? '',
    );
  const openMenu = (fixture: ComponentFixture<unknown>, labelId: number): void => {
    menuFor.set(`label-favorites-${labelId}`);
    fixture.detectChanges();
  };
  const menuItem = (fixture: ComponentFixture<unknown>, text: string): HTMLButtonElement =>
    Array.from(root(fixture).querySelectorAll<HTMLButtonElement>('[role="menuitem"]')).find(
      (item) => item.textContent?.trim() === text,
    )!;

  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    labels.set([]);
    unlabeled.set({ favoriteCount: 0, keptCount: 0 });
    menuFor.set(null);
    coarse.set(false);
  });

  it('heads the block with the collection, its icon, its total and its link', () => {
    const fixture = mount('favorites');

    const link = root(fixture).querySelector('.section-link')!;
    expect(link.querySelector('.label')?.textContent?.trim()).toBe('Favorites');
    expect(link.querySelector('app-icon')?.textContent?.trim()).toBe('star');
    expect(link.querySelector('.section-count')?.textContent?.trim()).toBe('7');
    expect(link.getAttribute('href')).toBe('/?view=favorites');
  });

  it('shows no total for an empty collection', () => {
    const fixture = mount('kept');

    expect(root(fixture).querySelector('.label')?.textContent?.trim()).toBe('Kept');
    expect(root(fixture).querySelector('.section-link app-icon')?.textContent?.trim()).toBe(
      'bookmark',
    );
    expect(root(fixture).querySelector('.section-count')).toBeNull();
  });

  it('marks the head current on the whole collection only', () => {
    expect(
      root(mount('favorites', { kind: 'favorites' }))
        .querySelector('.section-head')
        ?.classList.contains('current'),
    ).toBe(true);
    expect(
      root(mount('favorites', { kind: 'favorites', label: 3 }))
        .querySelector('.section-head')
        ?.classList.contains('current'),
    ).toBe(false);
  });

  it('has no chevron without subfolders', () => {
    labels.set([tally(1, 'Work', { favoriteCount: 0 })]);
    const fixture = mount('favorites');

    expect(root(fixture).querySelector('.chevzone')).toBeNull();
  });

  it('starts collapsed and opens on the chevron', () => {
    labels.set([tally(1, 'Work')]);
    const fixture = mount('favorites');

    expect(root(fixture).querySelector('.label-row')).toBeNull();
    toggle(fixture);
    expect(rowNames(fixture)).toEqual(['Work', 'Unlabeled']);
  });

  it('sorts the rows alphabetically regardless of case, Unlabeled last', () => {
    labels.set([tally(1, 'zebra'), tally(2, 'Apple'), tally(3, 'mango')]);
    unlabeled.set({ favoriteCount: 4, keptCount: 0 });
    const fixture = mount('favorites');
    toggle(fixture);

    expect(rowNames(fixture)).toEqual(['Apple', 'mango', 'zebra', 'Unlabeled']);
  });

  it('lists a label only in the collection it has entries in', () => {
    labels.set([tally(1, 'Work', { favoriteCount: 0, keptCount: 2 }), tally(2, 'Home')]);
    const favorites = mount('favorites');
    toggle(favorites);
    expect(rowNames(favorites)).toEqual(['Home', 'Unlabeled']);

    const kept = mount('kept');
    toggle(kept);
    expect(rowNames(kept)).toEqual(['Home', 'Work', 'Unlabeled']);
  });

  it('leaves Unlabeled out when no label row shows', () => {
    labels.set([tally(1, 'Work', { favoriteCount: 0 })]);
    unlabeled.set({ favoriteCount: 5, keptCount: 0 });
    const fixture = mount('favorites');

    expect(root(fixture).querySelector('.label-row')).toBeNull();
  });

  it('links each row to its label and shows its count in this collection', () => {
    labels.set([tally(3, 'Work', { favoriteCount: 4, keptCount: 9 })]);
    unlabeled.set({ favoriteCount: 0, keptCount: 0 });
    const fixture = mount('favorites');
    toggle(fixture);

    const links = Array.from(root(fixture).querySelectorAll('.label-row .nav'));
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/?view=favorites&label=3',
      '/?view=favorites&label=unlabeled',
    ]);
    expect(links.map((link) => link.querySelector('.count')?.textContent?.trim())).toEqual([
      '4',
      undefined,
    ]);
  });

  it('keeps the open state across a re-create', () => {
    labels.set([tally(1, 'Work')]);
    toggle(mount('favorites'));

    expect(rowNames(mount('favorites'))).toEqual(['Work', 'Unlabeled']);
    expect(root(mount('kept')).querySelector('.label-row')).toBeNull();
  });

  it('still opens and closes when localStorage throws', () => {
    labels.set([tally(1, 'Work')]);
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    try {
      const fixture = mount('favorites');
      expect(root(fixture).querySelector('.label-row')).toBeNull();
      toggle(fixture);
      expect(rowNames(fixture)).toEqual(['Work', 'Unlabeled']);
    } finally {
      getItem.mockRestore();
      setItem.mockRestore();
    }
  });

  it('marks the row the selection is filtered by', () => {
    labels.set([tally(1, 'Home'), tally(2, 'Work')]);
    const fixture = mount('favorites', { kind: 'favorites', label: 2 });
    toggle(fixture);

    const active = Array.from(root(fixture).querySelectorAll('.label-row')).map((row) =>
      row.classList.contains('active'),
    );
    expect(active).toEqual([false, true, false]);
  });

  it('marks Unlabeled on the unlabeled selection, and nothing in the other collection', () => {
    labels.set([tally(1, 'Home')]);
    const fixture = mount('favorites', { kind: 'favorites', label: 'unlabeled' });
    toggle(fixture);
    const kept = mount('kept', { kind: 'favorites', label: 1 });
    toggle(kept);

    const activeIn = (target: ComponentFixture<unknown>): boolean[] =>
      Array.from(root(target).querySelectorAll('.label-row')).map((row) =>
        row.classList.contains('active'),
      );
    expect(activeIn(fixture)).toEqual([false, true]);
    expect(activeIn(kept)).toEqual([false, false]);
  });

  it('offers Rename and Delete on a label row, and nothing on Unlabeled', () => {
    labels.set([tally(1, 'Home')]);
    const fixture = mount('favorites');
    toggle(fixture);

    expect(root(fixture).querySelectorAll('.label-row app-sidebar-row-menu')).toHaveLength(1);
    openMenu(fixture, 1);
    const items = Array.from(root(fixture).querySelectorAll('[role="menuitem"]')).map((item) =>
      item.textContent?.trim(),
    );
    expect(items).toEqual(['Rename', 'Delete']);
  });

  describe('rename', () => {
    function startRename(): ComponentFixture<SidebarCollectionComponent> {
      labels.set([tally(1, 'Home')]);
      const fixture = mount('favorites');
      toggle(fixture);
      openMenu(fixture, 1);
      menuItem(fixture, 'Rename').click();
      fixture.detectChanges();
      return fixture;
    }
    const input = (fixture: ComponentFixture<unknown>): HTMLInputElement | null =>
      root(fixture).querySelector<HTMLInputElement>('.label-row .rename');
    const press = (fixture: ComponentFixture<unknown>, key: string, value?: string): void => {
      const field = input(fixture)!;
      if (value !== undefined) field.value = value;
      field.dispatchEvent(new KeyboardEvent('keydown', { key }));
      fixture.detectChanges();
    };

    it('edits the name in place', () => {
      const fixture = startRename();

      expect(input(fixture)?.value).toBe('Home');
      expect(root(fixture).querySelector('.label-row')?.querySelector('.nav')).toBeNull();
      expect(document.activeElement).toBe(input(fixture));
    });

    it('saves on Enter', () => {
      rename.mockReturnValue(of(undefined));
      const fixture = startRename();

      press(fixture, 'Enter', '  House ');

      expect(rename).toHaveBeenCalledWith(1, 'House');
      expect(input(fixture)).toBeNull();
    });

    it('cancels on Escape', () => {
      const fixture = startRename();

      press(fixture, 'Escape', 'House');

      expect(rename).not.toHaveBeenCalled();
      expect(input(fixture)).toBeNull();
    });

    it('cancels when the field loses focus', () => {
      const fixture = startRename();

      input(fixture)!.value = 'House';
      input(fixture)!.dispatchEvent(new FocusEvent('blur'));
      fixture.detectChanges();

      expect(rename).not.toHaveBeenCalled();
      expect(input(fixture)).toBeNull();
    });

    it('shows a taken name as a toast and keeps editing', () => {
      rename.mockReturnValue(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 409,
              error: { type: 'label_name_taken', title: 'Taken', status: 409 },
            }),
        ),
      );
      const fixture = startRename();

      press(fixture, 'Enter', 'Work');

      expect(showProblem).toHaveBeenCalledWith(expect.objectContaining({ status: 409 }));
      expect(input(fixture)).not.toBeNull();
    });
  });

  describe('delete', () => {
    it('confirms with the number of articles the label is on', () => {
      ask.mockReturnValue(of(false));
      labels.set([tally(1, 'Home', { articleCount: 12 })]);
      const fixture = mount('favorites');
      toggle(fixture);
      openMenu(fixture, 1);

      menuItem(fixture, 'Delete').click();

      expect(ask).toHaveBeenCalledWith(
        expect.objectContaining({
          message: "Delete “Home”? It's on 12 articles.",
          danger: true,
        }),
      );
      expect(remove).not.toHaveBeenCalled();
    });

    it('says one article in the singular', () => {
      ask.mockReturnValue(of(false));
      labels.set([tally(1, 'Home', { articleCount: 1 })]);
      const fixture = mount('favorites');
      toggle(fixture);
      openMenu(fixture, 1);

      menuItem(fixture, 'Delete').click();

      expect(ask).toHaveBeenCalledWith(
        expect.objectContaining({ message: "Delete “Home”? It's on 1 article." }),
      );
    });

    it('deletes on confirm and stays on the list it is on', async () => {
      ask.mockReturnValue(of(true));
      remove.mockReturnValue(of(undefined));
      labels.set([tally(1, 'Home'), tally(2, 'Work')]);
      const fixture = mount('favorites', { kind: 'favorites', label: 2 });
      const router = TestBed.inject(Router);
      await router.navigateByUrl('/?view=favorites&label=2');
      toggle(fixture);
      openMenu(fixture, 1);

      menuItem(fixture, 'Delete').click();
      await fixture.whenStable();

      expect(remove).toHaveBeenCalledWith(1);
      expect(router.url).toBe('/?view=favorites&label=2');
    });

    it('opens the whole collection when the active label is deleted', async () => {
      ask.mockReturnValue(of(true));
      remove.mockReturnValue(of(undefined));
      labels.set([tally(1, 'Home')]);
      const fixture = mount('favorites', { kind: 'kept', label: 1 });
      const router = TestBed.inject(Router);
      await router.navigateByUrl('/?view=kept&label=1');
      toggle(fixture);
      openMenu(fixture, 1);

      menuItem(fixture, 'Delete').click();
      await fixture.whenStable();

      expect(router.url).toBe('/?view=kept');
    });

    it('shows a failed delete as a toast, keeps the row and stays on the list', async () => {
      ask.mockReturnValue(of(true));
      remove.mockReturnValue(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 500,
              error: { type: 'server_error', title: 'Broken', status: 500 },
            }),
        ),
      );
      labels.set([tally(1, 'Home')]);
      const fixture = mount('favorites', { kind: 'favorites', label: 1 });
      const router = TestBed.inject(Router);
      await router.navigateByUrl('/?view=favorites&label=1');
      toggle(fixture);
      openMenu(fixture, 1);

      menuItem(fixture, 'Delete').click();
      await fixture.whenStable();
      fixture.detectChanges();

      expect(showProblem).toHaveBeenCalledWith(expect.objectContaining({ status: 500 }));
      expect(rowNames(fixture)).toEqual(['Home', 'Unlabeled']);
      expect(router.url).toBe('/?view=favorites&label=1');
    });
  });

  describe('on a coarse pointer', () => {
    it('opens the label actions as a sheet', () => {
      coarse.set(true);
      openSheet.mockReturnValue(of('rename'));
      labels.set([tally(1, 'Home')]);
      const fixture = mount('favorites');
      toggle(fixture);

      expect(root(fixture).querySelector('app-sidebar-row-menu')).toBeNull();
      root(fixture).querySelector<HTMLButtonElement>('.label-row .dots')!.click();
      fixture.detectChanges();

      expect(openSheet).toHaveBeenCalledWith(
        expect.objectContaining({
          title: 'Home',
          actions: [
            expect.objectContaining({ id: 'rename', label: 'Rename' }),
            expect.objectContaining({ id: 'delete', label: 'Delete', danger: true }),
          ],
        }),
      );
      expect(root(fixture).querySelector<HTMLInputElement>('.label-row .rename')?.value).toBe(
        'Home',
      );
    });

    it('confirms a delete chosen from the sheet', () => {
      coarse.set(true);
      openSheet.mockReturnValue(of('delete'));
      ask.mockReturnValue(of(true));
      remove.mockReturnValue(of(undefined));
      labels.set([tally(1, 'Home', { articleCount: 3 })]);
      const fixture = mount('favorites');
      toggle(fixture);

      root(fixture).querySelector<HTMLButtonElement>('.label-row .dots')!.click();

      expect(ask).toHaveBeenCalledWith(
        expect.objectContaining({ message: "Delete “Home”? It's on 3 articles." }),
      );
      expect(remove).toHaveBeenCalledWith(1);
    });
  });
});
