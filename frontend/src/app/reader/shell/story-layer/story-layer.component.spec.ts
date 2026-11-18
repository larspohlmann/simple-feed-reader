import { Component, InjectionToken, inject, input, output, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { ArticleLayerComponent } from '../../article/article-layer/article-layer.component';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListOrderService } from '../../list/list-order.service';
import { ListItem } from '../../state/list-item';
import { SelectionOf } from '../../query/query';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { OpenStory } from '../../state/open-story';
import { StoryListStore } from '../../state/story-list.store';
import { STORY_ICON } from '../../trending/story';
import { FollowedStoryDto } from '../../trending/trending.models';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';
import { StoryLayerComponent } from './story-layer.component';

const LAYERS = new InjectionToken<ArticleLayerStubComponent[]>('LAYERS', {
  providedIn: 'root',
  factory: () => [],
});
const LISTS = new InjectionToken<EntryListStubComponent[]>('LISTS', {
  providedIn: 'root',
  factory: () => [],
});

@Component({
  selector: 'app-article-layer',
  template: `<div class="mini"><ng-content select="[articleMini]" /></div>
    <div class="bar"><ng-content select="[articleBar]" /></div>
    <ng-content />`,
})
class ArticleLayerStubComponent {
  constructor() {
    inject(LAYERS).push(this);
  }

  readonly fullscreen = input(false);
  readonly progressRail = input<unknown>();
  readonly backLabel = input('');
  readonly resetKey = input('');
  readonly back = output<void>();
}

@Component({ selector: 'app-entry-list', template: '' })
class EntryListStubComponent {
  constructor() {
    inject(LISTS).push(this);
  }

  readonly headerless = input(false);
  readonly items = input<ListItem[]>([]);
  readonly selection = input<unknown>();
  readonly title = input('');
  readonly layout = input<unknown>();
  readonly loading = input(false);
  readonly loadingMore = input(false);
  readonly error = input<unknown>(null);
  readonly hasMore = input(false);
  readonly canMarkAllRead = input(false);
  readonly openEntryId = input<number | null>(null);
  readonly leavingIds = input<unknown>();
  readonly newSinceVisitIds = input<unknown>();
  readonly feedTags = input<unknown>();
  readonly loadMore = output<void>();
  readonly retry = output<void>();
  readonly dismiss = output<void>();
}

describe('StoryLayerComponent (#2329)', () => {
  const story: SelectionOf<'trending-story'> = { kind: 'trending-story', id: 42 };
  let close: jest.Mock;
  let toggleOpen: jest.Mock;
  let setOrder: jest.Mock;
  let following: ReturnType<typeof signal<boolean>>;

  function mount(
    options: {
      fullscreen?: boolean;
      pageTitle?: string | null;
      followed?: FollowedStoryDto[];
    } = {},
    selection: SelectionOf<'trending-story'> = story,
  ): HTMLElement {
    close = jest.fn();
    toggleOpen = jest.fn();
    setOrder = jest.fn();
    following = signal(false);
    const followed = options.followed ?? [];
    TestBed.configureTestingModule({
      imports: [StoryLayerComponent, provideTranslocoTesting()],
      providers: [
        { provide: StoryOverlay, useValue: { close } },
        {
          provide: StoryListStore,
          useValue: {
            items: signal([anEntry({ id: 7 })]),
            loading: signal(false),
            loadingMore: signal(false),
            error: signal(null),
            hasMore: signal(false),
          },
        },
        { provide: FollowedStoryVisit, useValue: { newSinceVisitIds: signal(new Set()) } },
        { provide: OpenStory, useValue: { titleOf: () => options.pageTitle ?? null } },
        {
          provide: FollowedStoriesStore,
          useValue: { byId: (id: number) => followed.find((row) => row.id === id) ?? null },
        },
        { provide: ListOrderService, useValue: { set: setOrder } },
      ],
    });
    TestBed.overrideComponent(StoryLayerComponent, {
      remove: { imports: [ArticleLayerComponent, EntryListComponent], providers: [StoryFollowing] },
      add: {
        imports: [ArticleLayerStubComponent, EntryListStubComponent],
        providers: [{ provide: StoryFollowing, useValue: { following, toggleOpen } }],
      },
    });
    const fixture = TestBed.createComponent(StoryLayerComponent);
    fixture.componentRef.setInput('selection', selection);
    fixture.componentRef.setInput('fullscreen', options.fullscreen ?? false);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  afterEach(() => TestBed.resetTestingModule());

  it("names the story in the article's bar: its glyph, then its title", () => {
    const bar = mount({ pageTitle: 'Harbour strike' }).querySelector('.bar .bar-name')!;

    expect(bar.querySelector('app-icon')?.textContent?.trim()).toBe(STORY_ICON);
    expect(bar.querySelector('.bar-title')?.textContent?.trim()).toBe('Harbour strike');
  });

  it('names it in the strip above the bar when full-screen, as an article does', () => {
    const element = mount({ fullscreen: true, pageTitle: 'Harbour strike' });

    expect(element.querySelector('.bar .bar-name')).toBeNull();
    expect(element.querySelector('.mini .mini-title')?.textContent?.trim()).toBe('Harbour strike');
  });

  it("falls back to the followed row's headline, then to Story", () => {
    const headed = mount({
      followed: [{ id: 42, headline: 'Storm', newCount: 0, quiet: false, lastJoinedAt: '' }],
    });
    expect(headed.querySelector('.bar-title')?.textContent?.trim()).toBe('Storm');
    TestBed.resetTestingModule();

    expect(mount().querySelector('.bar-title')?.textContent?.trim()).toBe('Story');
  });

  it('offers Follow at the end of the bar, and follows the story', () => {
    const element = mount();
    const follow = element.querySelector<HTMLButtonElement>('.bar .follow-story')!;

    expect(follow.getAttribute('aria-label')).toBe('Follow story');
    follow.click();
    expect(toggleOpen).toHaveBeenCalled();
  });

  it('flips the order it keeps for the story', () => {
    mount().querySelector<HTMLButtonElement>('.bar .list-order')!.click();

    expect(setOrder).toHaveBeenCalledWith(story, 'oldest');
  });

  it('closes onto the list underneath', () => {
    mount();
    const layer = TestBed.inject(LAYERS).at(-1)!;

    layer.back.emit();

    expect(close).toHaveBeenCalled();
  });

  it("hands the story's rows to a list without a header of its own", () => {
    mount();
    const list = TestBed.inject(LISTS).at(-1)!;

    expect(list.headerless()).toBe(true);
    expect(list.items().map((item) => item.id)).toEqual([7]);
    expect(list.selection()).toEqual(story);
  });
});
