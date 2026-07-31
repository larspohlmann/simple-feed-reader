import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { CdkDragDrop } from '@angular/cdk/drag-drop';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { TagsSectionComponent } from './tags-section.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { ReaderApi } from '../reader/reader-api';
import { TagGlyphComponent } from '../shared/tag-glyph/tag-glyph.component';
import { Problem } from '../core/problem';
import { TagDto, SubscriptionDto } from '../reader/models';
import { API_BASE_URL } from '../core/api';
import en from '../../../public/i18n/en.json';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: '#3f8676',
  icon: 'label',
  position: 0,
});

const TAG: TagDto = { id: 1, name: 'Tech', color: '#ff8800', icon: 'memory', position: 0 };

describe('TagsSectionComponent', () => {
  let tags: ReturnType<typeof signal<TagDto[]>>;
  let loading: ReturnType<typeof signal<boolean>>;
  let error: ReturnType<typeof signal<Problem | null>>;
  let subscriptions: ReturnType<typeof signal<SubscriptionDto[]>>;

  const createTag = jest.fn();
  const editTag = jest.fn();
  const deleteTag = jest.fn();
  const reorderTags = jest.fn();
  const tagLoad = jest.fn();
  const subLoad = jest.fn();

  beforeEach(() => {
    createTag.mockReset();
    editTag.mockReset();
    deleteTag.mockReset();
    reorderTags.mockReset();
    tagLoad.mockReset();
    subLoad.mockReset();
  });

  async function render(
    initialTags: TagDto[] = [],
    initialSubs: SubscriptionDto[] = [],
    updateTag: jest.Mock = jest.fn(() => of({ tag: TAG })),
  ) {
    tags = signal<TagDto[]>(initialTags);
    loading = signal(false);
    error = signal<Problem | null>(null);
    subscriptions = signal<SubscriptionDto[]>(initialSubs);

    await TestBed.configureTestingModule({
      imports: [TagsSectionComponent, provideTranslocoTesting()],
      providers: [
        { provide: TagsStore, useValue: { tags, loading, error, load: tagLoad } },
        { provide: SubscriptionsStore, useValue: { subscriptions, load: subLoad } },
        { provide: ManageActions, useValue: { createTag, editTag, deleteTag, reorderTags } },
        { provide: ReaderApi, useValue: { updateTag } },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(TagsSectionComponent);
    fixture.detectChanges();
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }

  it('does not claim the list is empty while it is still loading', async () => {
    const { fixture, el } = await render();
    loading.set(true);
    fixture.detectChanges();

    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });

  it('shows the empty state only once loading has finished with no tags', async () => {
    const { fixture, el } = await render();
    loading.set(false);
    fixture.detectChanges();

    expect(el.querySelector('app-skeleton')).toBeNull();
    expect(el.textContent).toContain(en.settings.tags.none);
  });

  it('shows the tag list once tags arrive', async () => {
    const { fixture, el } = await render();
    tags.set([tag(1, 'Tech')]);
    fixture.detectChanges();

    expect(el.textContent).toContain('Tech');
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });

  it('reports a failed load through the shared error banner, with a retry', async () => {
    const { fixture, el } = await render();
    error.set({
      type: 'about:blank',
      title: 'Server error',
      detail: 'Could not load your tags.',
      status: 500,
    });
    fixture.detectChanges();

    expect(el.querySelector('app-error-banner')).not.toBeNull();
    expect(el.textContent).not.toContain(en.settings.tags.none);
  });

  it('falls back to the generic message when the problem carries no title or detail', async () => {
    const { fixture, el } = await render();
    error.set({ type: 'about:blank', title: '', status: 500 });
    fixture.detectChanges();

    expect(el.textContent).toContain(en.settings.tags.loadFailed);
  });

  it('renders the section inside the shared settings card', async () => {
    const { el } = await render();

    expect(el.querySelector('app-settings-card')).not.toBeNull();
  });

  it('projects the New tag button into the card actions slot', async () => {
    const { el } = await render();

    const card = el.querySelector('app-settings-card');
    expect(card).not.toBeNull();
    expect(card!.querySelector('header.head .new')).not.toBeNull();
  });

  it('loads tags and subscriptions on init', async () => {
    await render([]);
    expect(tagLoad).toHaveBeenCalled();
    expect(subLoad).toHaveBeenCalled();
  });

  it('lists tags and a feed-usage count', async () => {
    const subs: SubscriptionDto[] = [
      {
        id: 1,
        title: 'A',
        customTitle: null,
        feedUrl: 'u',
        siteUrl: null,
        status: 'active',
        sourceFormat: 'xml',
        createdAt: 'x',
        position: 0,
        tags: [tag(1, 'Tech')],
        unreadCount: 0,
      },
    ];
    const { el } = await render([tag(1, 'Tech'), tag(2, 'News')], subs);
    expect(el.textContent).toContain('Tech');
    expect(el.textContent).toContain('News');
    expect(el.textContent).toContain('1 feed');
  });

  it('wires New / Delete to ManageActions, and Edit to the inline editor instead of the dialog', async () => {
    const { fixture, el } = await render([tag(1, 'Tech')]);
    (el.querySelector('.new') as HTMLButtonElement).click();
    const rowButtons = el.querySelectorAll('.tag .acts button');
    (rowButtons[0] as HTMLButtonElement).click(); // edit
    fixture.detectChanges();

    expect(el.querySelector('.tag .editor')).not.toBeNull();
    expect(editTag).not.toHaveBeenCalled();

    fixture.componentInstance.cancelEdit();
    fixture.detectChanges();
    const deleteButton = el.querySelectorAll('.tag .acts button')[1] as HTMLButtonElement;
    deleteButton.click();

    expect(createTag).toHaveBeenCalled();
    expect(deleteTag).toHaveBeenCalledWith(tag(1, 'Tech'));
  });

  it('persists the new order when a row is dropped', async () => {
    const { fixture } = await render();
    tags.set([
      { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
      { id: 2, name: 'News', color: null, icon: null, position: 1 },
      { id: 3, name: 'Fun', color: null, icon: null, position: 2 },
    ]);
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.onTagDrop({ previousIndex: 2, currentIndex: 0 } as CdkDragDrop<TagDto[]>);

    const manage = TestBed.inject(ManageActions);
    expect(manage.reorderTags).toHaveBeenCalledWith([3, 1, 2]);
  });

  it('ignores a drop that does not move the row', async () => {
    const { fixture } = await render();
    tags.set([
      { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
      { id: 2, name: 'News', color: null, icon: null, position: 1 },
    ]);
    fixture.detectChanges();

    fixture.componentInstance.onTagDrop({
      previousIndex: 1,
      currentIndex: 1,
    } as CdkDragDrop<TagDto[]>);

    expect(TestBed.inject(ManageActions).reorderTags).not.toHaveBeenCalled();
  });

  it('gives every row a drag handle', async () => {
    const { fixture, el } = await render();
    tags.set([{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }]);
    fixture.detectChanges();

    expect(el.querySelectorAll('.drag-handle')).toHaveLength(1);
  });

  it('renders the tag glyph tinted with the tag colour for a tag with an icon, and no separate dot', async () => {
    const { fixture, el } = await render();
    tags.set([{ id: 1, name: 'Tech', color: '#3f8676', icon: 'label', position: 0 }]);
    fixture.detectChanges();

    const glyphDebug = fixture.debugElement.query(By.css('app-tag-glyph'));
    expect(glyphDebug).not.toBeNull();
    const glyph = glyphDebug.componentInstance as TagGlyphComponent;
    expect(glyph.name()).toBe('label');
    expect(glyph.color()).toBe('#3f8676');
    expect(el.querySelector('.tag .dot')).toBeNull();
  });

  it('still renders the tag glyph for a tag without an icon', async () => {
    const { fixture, el } = await render();
    tags.set([{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }]);
    fixture.detectChanges();

    expect(el.querySelector('app-tag-glyph')).not.toBeNull();
  });

  it('opens an inline editor on the row and not the dialog', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    expect(el.querySelector('.tag .editor')).not.toBeNull();
    expect(TestBed.inject(ManageActions).editTag).not.toHaveBeenCalled();
  });

  it('saves through updateTag and reloads', async () => {
    const { fixture } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.startEdit(TAG);
    component.draftName.set('Technology');
    component.saveEdit();

    expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(1, {
      name: 'Technology',
      color: '#ff8800',
      icon: 'memory',
    });
    expect(component.editingId()).toBeNull();
    expect(tagLoad).toHaveBeenCalled();
    expect(subLoad).toHaveBeenCalled();
  });

  it('cancels without saving', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.startEdit(TAG);
    component.draftName.set('Technology');
    fixture.detectChanges();
    component.cancelEdit();
    fixture.detectChanges();

    expect(TestBed.inject(ReaderApi).updateTag).not.toHaveBeenCalled();
    expect(component.editingId()).toBeNull();
    expect(el.querySelector('.tag .editor')).toBeNull();
  });

  it('cancels on Escape from the name field', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    const input = el.querySelector('.editor input') as HTMLInputElement;
    expect(input).not.toBeNull();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();

    expect(fixture.componentInstance.editingId()).toBeNull();
    expect(TestBed.inject(ReaderApi).updateTag).not.toHaveBeenCalled();
  });

  it('saves on Enter from the name field', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    const input = el.querySelector('.editor input') as HTMLInputElement;
    expect(input).not.toBeNull();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    fixture.detectChanges();

    expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(1, {
      name: TAG.name,
      color: TAG.color,
      icon: TAG.icon,
    });
  });

  it('changes the colour through the real app-color-field and saves it', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    const swatches = el.querySelectorAll('.editor app-color-field .swatch');
    expect(swatches.length).toBeGreaterThan(0);
    (swatches[0] as HTMLButtonElement).click();
    fixture.detectChanges();

    fixture.componentInstance.saveEdit();

    expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ color: '#3f8676' }),
    );
  });

  it('changes the icon through the real app-icon-picker and saves it', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    const starOption = el.querySelector(
      '.editor app-icon-picker .opt[aria-label="star"]',
    ) as HTMLButtonElement;
    expect(starOption).not.toBeNull();
    starOption.click();
    fixture.detectChanges();

    fixture.componentInstance.saveEdit();

    expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ icon: 'star' }),
    );
  });

  it('maps the icon picker "no icon" choice to null, not an empty string', async () => {
    const { fixture, el } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.detectChanges();

    const noneOption = el.querySelector(
      '.editor app-icon-picker .opt[aria-label="No icon"]',
    ) as HTMLButtonElement;
    expect(noneOption).not.toBeNull();
    noneOption.click();
    fixture.detectChanges();

    fixture.componentInstance.saveEdit();

    expect(TestBed.inject(ReaderApi).updateTag).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ icon: null }),
    );
  });

  it('edits only one row at a time', async () => {
    const { fixture, el } = await render();
    const second: TagDto = { id: 2, name: 'News', color: null, icon: null, position: 1 };
    tags.set([TAG, second]);
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.startEdit(TAG);
    component.startEdit(second);
    fixture.detectChanges();

    expect(component.editingId()).toBe(2);
    expect(el.querySelectorAll('.editor')).toHaveLength(1);
  });

  it('refuses to save an empty name', async () => {
    const { fixture } = await render();
    tags.set([TAG]);
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.startEdit(TAG);
    component.draftName.set('   ');
    component.saveEdit();

    expect(TestBed.inject(ReaderApi).updateTag).not.toHaveBeenCalled();
    expect(component.editingId()).toBe(1);
  });

  it('shows the server-provided message when the save request fails', async () => {
    const failingUpdate = jest.fn(() =>
      throwError(
        () =>
          new HttpErrorResponse({
            status: 422,
            error: { type: 'about:blank', title: 'bad', detail: 'Name is already taken.' },
          }),
      ),
    );
    const { fixture, el } = await render([], [], failingUpdate);
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.componentInstance.saveEdit();
    fixture.detectChanges();

    const banner = el.querySelector('.editor app-error-banner');
    expect(banner).not.toBeNull();
    expect(banner!.textContent).toContain('Name is already taken.');
    expect(fixture.componentInstance.editingId()).toBe(1);
  });

  it('falls back to the generic saveFailed message when the problem carries no usable text', async () => {
    const failingUpdate = jest.fn(() =>
      throwError(
        () =>
          new HttpErrorResponse({
            status: 500,
            error: { type: 'about:blank', title: '' },
          }),
      ),
    );
    const { fixture, el } = await render([], [], failingUpdate);
    tags.set([TAG]);
    fixture.detectChanges();

    fixture.componentInstance.startEdit(TAG);
    fixture.componentInstance.saveEdit();
    fixture.detectChanges();

    const banner = el.querySelector('.editor app-error-banner');
    expect(banner).not.toBeNull();
    expect(banner!.textContent).toContain(en.settings.tags.saveFailed);
  });
});

/** Drives the real ManageActions.reorderTags() -> ReaderApi -> TagsStore path
 *  (no stub in between) so a rejected write's reconcile-from-server behaviour
 *  is genuinely exercised, not merely assumed from ManageActions' own spec
 *  (which only ever flushes success responses). */
describe('TagsSectionComponent reorder reconciliation', () => {
  let ctrl: HttpTestingController;
  let tagsStore: TagsStore;
  let fixture: ReturnType<typeof TestBed.createComponent<TagsSectionComponent>>;

  const serverTags: TagDto[] = [
    { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
    { id: 2, name: 'News', color: null, icon: null, position: 1 },
    { id: 3, name: 'Fun', color: null, icon: null, position: 2 },
  ];

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [TagsSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: SubscriptionsStore,
          useValue: { subscriptions: signal<SubscriptionDto[]>([]), load: jest.fn() },
        },
      ],
    });

    ctrl = TestBed.inject(HttpTestingController);
    tagsStore = TestBed.inject(TagsStore);
    fixture = TestBed.createComponent(TagsSectionComponent);
    fixture.detectChanges();

    // Satisfy the ngOnInit load() the real TagsStore issues.
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: serverTags });
    fixture.detectChanges();
  });

  afterEach(() => ctrl.verify());

  it('reconciles to the server order when the reorder request is rejected', () => {
    fixture.componentInstance.onTagDrop({
      previousIndex: 2,
      currentIndex: 0,
    } as CdkDragDrop<TagDto[]>);

    // Optimistic reorder applied immediately, before the server responds.
    expect(tagsStore.tags().map((t) => t.id)).toEqual([3, 1, 2]);

    ctrl
      .expectOne('https://api.test/api/tags/reorder')
      .flush('nope', { status: 500, statusText: 'Server Error' });

    // ManageActions.reloadAfter() re-syncs from the server on error.
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: serverTags });

    expect(tagsStore.tags().map((t) => t.id)).toEqual([1, 2, 3]);
  });
});
