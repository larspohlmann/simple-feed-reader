import {
  Component,
  InjectionToken,
  TemplateRef,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListOrderService } from '../../list/list-order.service';
import { ListPreferences } from '../../list/list-preferences.service';
import { TitleCount } from '../../list/list-header/list-header.component';
import { SelectionOf } from '../../query/query';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { ListItem } from '../../state/list-item';
import { OpenStory } from '../../state/open-story';
import { STORY_ENTRIES } from '../../state/story-entries';
import { FollowedStoryDto } from '../../trending/trending.models';
import { MarkReadActions } from '../mark-read-actions.service';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';
import { StoryLayerComponent } from './story-layer.component';

const LISTS = new InjectionToken<EntryListStubComponent[]>('LISTS', {
  providedIn: 'root',
  factory: () => [],
});

@Component({
  selector: 'app-entry-list',
  imports: [NgTemplateOutlet],
  template: `<div class="leading"><ng-container [ngTemplateOutlet]="headerLeading()" /></div>
    <div class="actions"><ng-container [ngTemplateOutlet]="headerActions()" /></div>`,
})
class EntryListStubComponent {
  readonly items = input<ListItem[]>([]);
  readonly selection = input<unknown>();
  readonly title = input('');
  readonly titleCount = input<TitleCount>({ value: 0, counts: 'items' });
  readonly layout = input<unknown>();
  readonly loading = input(false);
  readonly mutating = input(false);
  readonly loadingMore = input(false);
  readonly error = input<unknown>(null);
  readonly hasMore = input(false);
  readonly canMarkAllRead = input(false);
  readonly openEntryId = input<number | null>(null);
  readonly leavingIds = input<unknown>();
  readonly newSinceVisitIds = input<unknown>();
  readonly feedTags = input<unknown>();
  readonly headerLeading = input<TemplateRef<unknown> | null>(null);
  readonly headerActions = input<TemplateRef<unknown> | null>(null);
  readonly cornerTrailing = input<TemplateRef<unknown> | null>(null);
  readonly loadMore = output<void>();
  readonly retry = output<void>();
  readonly dismiss = output<void>();
  readonly markAllRead = output<void>();
  readonly unreadOnlyChange = output<boolean>();
  readonly orderChange = output<'newest' | 'oldest'>();
  readonly markAboveRead = output<number[]>();
  readonly hideAboveMarked = jest.fn();

  constructor() {
    inject(LISTS).push(this);
  }
}

describe('StoryLayerComponent (#2329)', () => {
  const story: SelectionOf<'trending-story'> = { kind: 'trending-story', id: 42, unread: false };
  let close: jest.Mock;
  let toggleOpen: jest.Mock;
  let setOrder: jest.Mock;
  let setUnreadOnly: jest.Mock;
  let confirmMarkAllReadIn: jest.Mock;
  let confirmMarkAboveReadIn: jest.Mock;
  const rows = {
    items: signal<ListItem[]>([anEntry({ id: 7 }), anEntry({ id: 8 })]),
    entries: signal([anEntry({ id: 7 }), anEntry({ id: 8 })]),
    loading: signal(false),
    mutating: signal(false),
    loadingMore: signal(false),
    error: signal(null),
    nextCursor: signal<string | null>(null),
  };

  function mount(
    options: { pageTitle?: string | null; followed?: FollowedStoryDto[] } = {},
    selection: SelectionOf<'trending-story'> = story,
  ): { element: HTMLElement; list: EntryListStubComponent } {
    close = jest.fn();
    toggleOpen = jest.fn();
    setOrder = jest.fn();
    setUnreadOnly = jest.fn();
    confirmMarkAllReadIn = jest.fn();
    confirmMarkAboveReadIn = jest.fn();
    const followed = options.followed ?? [];
    TestBed.configureTestingModule({
      imports: [StoryLayerComponent, provideTranslocoTesting()],
      providers: [
        { provide: StoryOverlay, useValue: { close } },
        { provide: STORY_ENTRIES, useValue: rows },
        { provide: FollowedStoryVisit, useValue: { newSinceVisitIds: signal(new Set()) } },
        { provide: OpenStory, useValue: { titleOf: () => options.pageTitle ?? null } },
        {
          provide: FollowedStoriesStore,
          useValue: { byId: (id: number) => followed.find((row) => row.id === id) ?? null },
        },
        { provide: ListOrderService, useValue: { set: setOrder } },
        { provide: ListPreferences, useValue: { setUnreadOnly } },
        { provide: MarkReadActions, useValue: { confirmMarkAllReadIn, confirmMarkAboveReadIn } },
      ],
    });
    TestBed.overrideComponent(StoryLayerComponent, {
      remove: { imports: [EntryListComponent], providers: [StoryFollowing] },
      add: {
        imports: [EntryListStubComponent],
        providers: [
          { provide: StoryFollowing, useValue: { following: signal(false), toggleOpen } },
        ],
      },
    });
    const fixture = TestBed.createComponent(StoryLayerComponent);
    fixture.componentRef.setInput('selection', selection);
    fixture.detectChanges();
    return { element: fixture.nativeElement as HTMLElement, list: TestBed.inject(LISTS).at(-1)! };
  }

  afterEach(() => TestBed.resetTestingModule());

  it("heads the reader's own list with the story's title and the count of its rows", () => {
    const { list } = mount({ pageTitle: 'Harbour strike' });

    expect(list.title()).toBe('Harbour strike');
    expect(list.titleCount()).toEqual({ value: 2, counts: 'items' });
    expect(list.selection()).toEqual(story);
    expect(list.items().map((item) => item.id)).toEqual([7, 8]);
    expect(list.canMarkAllRead()).toBe(true);
  });

  it('counts the unread rows under "Only unread", as every list does', () => {
    expect(mount({}, { ...story, unread: true }).list.titleCount()).toEqual({
      value: 2,
      counts: 'unread',
    });
  });

  it("falls back to the followed row's headline, then to Story", () => {
    const headed = mount({
      followed: [{ id: 42, headline: 'Storm', newCount: 0, quiet: false, lastJoinedAt: '' }],
    });
    expect(headed.list.title()).toBe('Storm');
    TestBed.resetTestingModule();

    expect(mount().list.title()).toBe('Story');
  });

  it('leads with the way back to the list underneath', () => {
    const { element } = mount();
    const back = element.querySelector<HTMLButtonElement>('.leading .story-back')!;

    expect(back.getAttribute('aria-label')).toBe('Back to list');
    back.click();
    expect(close).toHaveBeenCalled();
  });

  it("offers Follow among the list's actions, and follows the story", () => {
    const follow = mount().element.querySelector<HTMLButtonElement>('.actions .follow-story')!;

    expect(follow.getAttribute('aria-label')).toBe('Follow story');
    follow.click();
    expect(toggleOpen).toHaveBeenCalled();
  });

  it("hands the list's order, unread switch and mark-read to the story's own list", () => {
    const { list } = mount();

    list.orderChange.emit('oldest');
    list.unreadOnlyChange.emit(true);
    list.markAllRead.emit();
    list.markAboveRead.emit([7]);

    expect(setOrder).toHaveBeenCalledWith(story, 'oldest');
    expect(setUnreadOnly).toHaveBeenCalledWith(story, true);
    expect(confirmMarkAllReadIn).toHaveBeenCalledWith({ selection: story, entries: rows });
    expect(confirmMarkAboveReadIn).toHaveBeenCalledWith(
      { selection: story, entries: rows },
      [7],
      expect.any(Function),
    );
  });
});
