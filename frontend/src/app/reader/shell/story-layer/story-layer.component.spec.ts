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
import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry, aStoryCard } from '../../../../testing/entry-testing';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListOrderService } from '../../list/list-order.service';
import { ListPreferences } from '../../list/list-preferences.service';
import { TitleCount } from '../../list/list-header/list-header.component';
import { SelectionOf } from '../../query/query';
import { EntriesStore } from '../../state/entries.store';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { ListContext } from '../../state/list-context';
import { ListItem } from '../../state/list-item';
import { OpenStory } from '../../state/open-story';
import { TrendingFollowAction } from '../../trending/trending-follow-action';
import { TrendingHideAction } from '../../trending/trending-hide-action';
import { StorySources } from '../../trending/trending.models';
import { TestListContext } from '../../../../testing/list-context-testing';
import { ListCover } from '../list-cover';
import { ListHeading } from '../list-heading.service';
import { MarkReadActions } from '../mark-read-actions.service';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';
import { StoryLayerComponent } from './story-layer.component';
import { StoryListContext } from './story-list-context';
import { ArticleGestures } from '../../article/article-layer/article-gestures.service';

const LISTS = new InjectionToken<EntryListStubComponent[]>('LISTS', {
  providedIn: 'root',
  factory: () => [],
});

@Component({
  selector: 'app-entry-list',
  imports: [NgTemplateOutlet],
  template: `<div class="leading"><ng-container [ngTemplateOutlet]="headerLeading()" /></div>
    <div class="actions"><ng-container [ngTemplateOutlet]="headerActions()" /></div>
    <div class="top"><ng-container [ngTemplateOutlet]="topBlock()" /></div>`,
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
  readonly topBlock = input<TemplateRef<unknown> | null>(null);
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
  const touch = (x: number, y: number) =>
    ({
      touches: [{ clientX: x, clientY: y }],
      preventDefault() {
        /* test stub */
      },
    }) as unknown as TouchEvent;

  let close: jest.Mock;
  let overlayOpen: ReturnType<typeof signal<boolean>>;
  let toggleOpen: jest.Mock;
  let hideRun: jest.Mock;
  let setOrder: jest.Mock;
  let setUnreadOnly: jest.Mock;
  let confirmMarkAllRead: jest.Mock;
  let confirmMarkAboveRead: jest.Mock;
  let context: TestListContext;
  const rows = {
    items: signal<ListItem[]>([anEntry({ id: 7 }), anEntry({ id: 8 })]),
    entries: signal([anEntry({ id: 7 }), anEntry({ id: 8 })]),
    loading: signal(false),
    mutating: signal(false),
    loadingMore: signal(false),
    error: signal(null),
    nextCursor: signal<string | null>(null),
  };
  const title = signal<string | null>(null);
  const synopsis = signal<string | null>(null);
  const sources = signal<StorySources | null>(null);
  const heading = {
    title: signal('Harbour strike'),
    titleCount: signal<TitleCount>({ value: 31, counts: 'items' }),
    hasMore: signal(true),
  };

  function mountFixture(): ComponentFixture<StoryLayerComponent> {
    close = jest.fn();
    overlayOpen = signal(true);
    toggleOpen = jest.fn();
    hideRun = jest.fn();
    setOrder = jest.fn();
    setUnreadOnly = jest.fn();
    confirmMarkAllRead = jest.fn();
    confirmMarkAboveRead = jest.fn();
    context = new TestListContext(rows as unknown as EntriesStore, signal(story));
    TestBed.configureTestingModule({
      imports: [StoryLayerComponent, provideTranslocoTesting()],
      providers: [
        ListCover,
        { provide: StoryOverlay, useValue: { close, open: overlayOpen } },
        { provide: TrendingHideAction, useValue: { run: hideRun } },
        { provide: ListOrderService, useValue: { set: setOrder } },
        { provide: ListPreferences, useValue: { setUnreadOnly } },
        {
          provide: MarkReadActions,
          useValue: { confirmMarkAllRead, confirmMarkAboveRead, canMarkAllRead: signal(true) },
        },
      ],
    });
    TestBed.overrideComponent(StoryLayerComponent, {
      remove: {
        imports: [EntryListComponent],
        providers: [
          StoryListContext,
          ListHeading,
          OpenStory,
          FollowedStoryVisit,
          TrendingFollowAction,
          StoryFollowing,
        ],
      },
      add: {
        imports: [EntryListStubComponent],
        providers: [
          { provide: StoryListContext, useValue: context },
          { provide: ListContext, useValue: context },
          { provide: ListHeading, useValue: heading },
          { provide: FollowedStoryVisit, useValue: { newSinceVisitIds: signal(new Set()) } },
          { provide: StoryFollowing, useValue: { following: signal(false), toggleOpen } },
          {
            provide: OpenStory,
            useValue: {
              cardOf: (id: number) =>
                id === story.id && title() !== null
                  ? aStoryCard({
                      id,
                      title: title() ?? undefined,
                      synopsis: synopsis(),
                      outletCount: sources()?.outletCount ?? 0,
                      shareCount: sources()?.shareCount ?? 0,
                    })
                  : null,
              followedOnServer: () => false,
            },
          },
        ],
      },
    });
    const fixture = TestBed.createComponent(StoryLayerComponent);
    fixture.detectChanges();
    return fixture;
  }

  function mount(): { element: HTMLElement; list: EntryListStubComponent } {
    const fixture = mountFixture();
    return { element: fixture.nativeElement as HTMLElement, list: TestBed.inject(LISTS).at(-1)! };
  }

  afterEach(() => {
    title.set(null);
    synopsis.set(null);
    sources.set(null);
    TestBed.resetTestingModule();
  });

  it("heads the reader's own list with the story's heading and lists its own rows", () => {
    const { list } = mount();

    expect(list.title()).toBe('Harbour strike');
    expect(list.titleCount()).toEqual({ value: 31, counts: 'items' });
    expect(list.hasMore()).toBe(true);
    expect(list.selection()).toEqual(story);
    expect(list.items().map((item) => item.id)).toEqual([7, 8]);
    expect(list.canMarkAllRead()).toBe(true);
  });

  it("heads its rows with the story's title and synopsis, as the Trending card does, once the page lands", () => {
    const fixture = mountFixture();
    const list = TestBed.inject(LISTS).at(-1)!;

    title.set('Dockers strike at the harbour');
    synopsis.set('Dockers walked out at dawn. The port is shut.');
    fixture.detectChanges();

    expect(list.topBlock()).not.toBeNull();
    const headline = (fixture.nativeElement as HTMLElement).querySelector('.top app-story-title');
    expect(headline?.querySelector('h3.title')?.textContent?.trim()).toBe(
      'Dockers strike at the harbour',
    );
    expect(headline?.querySelector('p.synopsis')?.textContent?.trim()).toBe(
      'Dockers walked out at dawn. The port is shut.',
    );
  });

  it('heads its rows with the title alone when the story has no synopsis', () => {
    const fixture = mountFixture();

    title.set('Dockers strike at the harbour');
    fixture.detectChanges();

    const headline = (fixture.nativeElement as HTMLElement).querySelector('.top app-story-title');
    expect(headline?.querySelector('h3.title')).not.toBeNull();
    expect(headline?.querySelector('.synopsis')).toBeNull();
  });

  it('renders no headline before the page lands', () => {
    const { element, list } = mount();

    expect(list.topBlock()).toBeNull();
    expect(element.querySelector('app-story-title')).toBeNull();
  });

  it('is the shared story card, with no link and no card of its own', () => {
    const fixture = mountFixture();

    title.set('Dockers strike at the harbour');
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('app-story-title')).not.toBeNull();
    expect(element.querySelector('a.story-link')).toBeNull();
    expect(element.querySelector('.story-card')).toBeNull();
  });

  it('lets rows leave its own list', () => {
    const { list } = mount();

    context.markLeaving(8);
    TestBed.tick();

    expect((list.leavingIds() as ReadonlySet<number>).has(8)).toBe(true);
  });

  it("lays its list over the route's while it is up", () => {
    const fixture = mountFixture();
    const cover = TestBed.inject(ListCover);

    expect(cover.list()?.context).toBe(context);
    expect(cover.list()?.heading).toBe(heading);
    fixture.destroy();
    expect(cover.list()).toBeNull();
  });

  it('uncovers the list beneath as soon as the story starts to leave, not after its slide', () => {
    mountFixture();
    const cover = TestBed.inject(ListCover);

    overlayOpen.set(false);

    expect(cover.list()).toBeNull();
  });

  it('leads with the way back to the list underneath', () => {
    const { element } = mount();
    const back = element.querySelector<HTMLButtonElement>('.leading .story-back')!;

    expect(back.getAttribute('aria-label')).toBe('Back to list');
    back.click();
    expect(close).toHaveBeenCalled();
  });

  describe("the story's title card (#2442)", () => {
    function mountCard(): HTMLElement {
      const fixture = mountFixture();
      title.set('Dockers strike at the harbour');
      synopsis.set('Dockers walked out at dawn.');
      sources.set({ outletCount: 14, shareCount: 8 });
      fixture.detectChanges();
      return (fixture.nativeElement as HTMLElement).querySelector('.top app-story-title')!;
    }

    it('dates the story between the title and the synopsis, and counts its sources and shares at the foot', () => {
      const card = mountCard();

      expect(card.querySelector('h3.title + app-story-dates + p.synopsis')).not.toBeNull();
      expect(card.querySelector('.story-foot app-story-source-count')?.textContent).toContain(
        '14 sources',
      );
      expect(card.querySelector('.story-foot app-story-source-count')?.textContent).toContain(
        '8 shares',
      );
    });

    it('ends with Follow and Hide, and the bar above it holds no Follow', () => {
      const card = mountCard();

      expect(card.lastElementChild?.matches('.story-foot')).toBe(true);
      const labels = [...card.querySelectorAll('.story-foot button')].map((button) =>
        button.getAttribute('aria-label'),
      );
      expect(labels).toEqual(['Follow story', 'Hide story']);
      expect(card.ownerDocument.querySelector('.actions .follow-story')).toBeNull();
    });

    it('follows the open story', () => {
      mountCard().querySelector<HTMLButtonElement>('.story-foot button')!.click();

      expect(toggleOpen).toHaveBeenCalled();
    });

    it('hides the story on the list beneath and closes the layer', () => {
      mountCard().querySelectorAll<HTMLButtonElement>('.story-foot button')[1].click();

      expect(close).toHaveBeenCalled();
      expect(hideRun).toHaveBeenCalledWith(expect.objectContaining({ id: 42, followed: false }));
    });
  });

  it("hands the list's order, unread switch and mark-read on for the story's list", () => {
    const { list } = mount();

    list.orderChange.emit('oldest');
    list.unreadOnlyChange.emit(true);
    list.markAllRead.emit();
    list.markAboveRead.emit([7]);

    expect(setOrder).toHaveBeenCalledWith(story, 'oldest');
    expect(setUnreadOnly).toHaveBeenCalledWith(story, true);
    expect(confirmMarkAllRead).toHaveBeenCalled();
    expect(confirmMarkAboveRead).toHaveBeenCalledWith([7], expect.any(Function));
  });

  describe("the article's back-swipe", () => {
    function swipe(fixture: ComponentFixture<StoryLayerComponent>, deltaX: number): void {
      const gestures = fixture.debugElement.injector.get(ArticleGestures);
      gestures.onTouchStart(touch(0, 0));
      gestures.onTouchMove(touch(deltaX, 6));
      fixture.detectChanges();
      gestures.onTouchEnd();
      fixture.detectChanges();
    }

    it('follows the finger and takes the full-screen story off on a decisive rightward swipe', fakeAsync(() => {
      const fixture = mountFixture();
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();
      const host = fixture.nativeElement as HTMLElement;

      const gestures = fixture.debugElement.injector.get(ArticleGestures);
      gestures.onTouchStart(touch(0, 0));
      gestures.onTouchMove(touch(40, 4));
      fixture.detectChanges();
      expect(host.style.transform).toBe('translate3d(40px, 0px, 0)');
      gestures.onTouchMove(touch(130, 6));
      gestures.onTouchEnd();
      fixture.detectChanges();

      expect(host.style.transform).toContain(`${window.innerWidth}px`);
      expect(close).not.toHaveBeenCalled();
      tick(220);
      expect(close).toHaveBeenCalledTimes(1);
    }));

    it('snaps back on a short swipe', fakeAsync(() => {
      const fixture = mountFixture();
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();

      swipe(fixture, 30);
      tick(220);

      expect(close).not.toHaveBeenCalled();
    }));

    it('leaves a story beside the list in the wide layout alone', fakeAsync(() => {
      const fixture = mountFixture();

      swipe(fixture, 130);
      tick(220);

      expect(close).not.toHaveBeenCalled();
      expect((fixture.nativeElement as HTMLElement).style.transform).toBe('none');
    }));
  });
});
