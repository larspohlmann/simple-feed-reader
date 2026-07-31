import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { TagsSectionComponent } from './tags-section.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { Problem } from '../core/problem';
import { TagDto, SubscriptionDto } from '../reader/models';
import en from '../../../public/i18n/en.json';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: '#3f8676',
  icon: 'label',
  position: 0,
});

describe('TagsSectionComponent', () => {
  let tags: ReturnType<typeof signal<TagDto[]>>;
  let loading: ReturnType<typeof signal<boolean>>;
  let error: ReturnType<typeof signal<Problem | null>>;
  let subscriptions: ReturnType<typeof signal<SubscriptionDto[]>>;

  const createTag = jest.fn();
  const editTag = jest.fn();
  const deleteTag = jest.fn();
  const tagLoad = jest.fn();
  const subLoad = jest.fn();

  beforeEach(() => {
    createTag.mockReset();
    editTag.mockReset();
    deleteTag.mockReset();
    tagLoad.mockReset();
    subLoad.mockReset();
  });

  async function render(initialTags: TagDto[] = [], initialSubs: SubscriptionDto[] = []) {
    tags = signal<TagDto[]>(initialTags);
    loading = signal(false);
    error = signal<Problem | null>(null);
    subscriptions = signal<SubscriptionDto[]>(initialSubs);

    await TestBed.configureTestingModule({
      imports: [TagsSectionComponent, provideTranslocoTesting()],
      providers: [
        { provide: TagsStore, useValue: { tags, loading, error, load: tagLoad } },
        { provide: SubscriptionsStore, useValue: { subscriptions, load: subLoad } },
        { provide: ManageActions, useValue: { createTag, editTag, deleteTag } },
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

  it('wires New / Edit / Delete to ManageActions', async () => {
    const { el } = await render([tag(1, 'Tech')]);
    (el.querySelector('.new') as HTMLButtonElement).click();
    const rowButtons = el.querySelectorAll('.tag .acts button');
    (rowButtons[0] as HTMLButtonElement).click(); // edit
    (rowButtons[1] as HTMLButtonElement).click(); // delete
    expect(createTag).toHaveBeenCalled();
    expect(editTag).toHaveBeenCalledWith(tag(1, 'Tech'));
    expect(deleteTag).toHaveBeenCalledWith(tag(1, 'Tech'));
  });
});
