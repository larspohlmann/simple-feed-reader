import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component, NgZone, Provider, TemplateRef, ViewChild, signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { EntryListComponent } from './entry-list.component';
import { REFRESH_REVEAL } from './pull-to-refresh';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { CatalogStore } from '../../feeds/catalog/catalog.store';
import { REVEAL_STEP, prefetchMargin } from '../paging';
import { EntryDto, ListOrder, SubscriptionDto } from '../../models';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { MagazineBlock } from '../magazine/magazine-block';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { MagazineStyleService } from '../../../core/preferences/magazine-style.service';
import { MAGAZINE_STYLE_WRITER } from '../../../core/preferences/magazine-style-writer';
import { LayoutService } from '../../layout.service';

class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
  }
  observe(target: Element): void {
    this.targets.add(target);
  }
  unobserve(target: Element): void {
    this.targets.delete(target);
  }
  disconnect(): void {
    this.targets.clear();
  }
  fire(): void {
    this.callback([], this as unknown as ResizeObserver);
  }
}

const memory = { save: jest.fn(), read: jest.fn().mockReturnValue(0) };
// A stub for the two signals `catalogEmpty` reads — keeps the real CatalogStore
// (and its HttpClient chain) out of this component's unit test.
const catalog = { resolved: signal(false), hasEntries: signal(false) };
const subscriptionsStore = { resolved: signal(true), subscriptions: signal<SubscriptionDto[]>([]) };

function subscribedTo(count: number): void {
  subscriptionsStore.subscriptions.set(
    Array.from({ length: count }, (_, index) => ({ id: index + 1 }) as SubscriptionDto),
  );
}

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: `e${id}`,
  url: null,
  author: null,
  summary: 's',
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 1,
  source: 'src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

/** A run mixed enough to collapse: a same-source run folds into a group widget
 *  only once the view has at least 3 distinct active sources. */
const MIXED_SOURCE_RUN_AT = '2026-07-22T11:00:00Z';
const MIXED_SOURCE_RUN: EntryDto[] = [
  ...Array.from({ length: 8 }, (_, index) =>
    entry(index + 1, { subscriptionId: 1, source: 'a', publishedAt: MIXED_SOURCE_RUN_AT }),
  ),
  entry(9, { subscriptionId: 2, source: 'b', publishedAt: MIXED_SOURCE_RUN_AT }),
  entry(10, { subscriptionId: 2, source: 'b', publishedAt: MIXED_SOURCE_RUN_AT }),
  entry(11, { subscriptionId: 3, source: 'c', publishedAt: MIXED_SOURCE_RUN_AT }),
  entry(12, { subscriptionId: 3, source: 'c', publishedAt: MIXED_SOURCE_RUN_AT }),
];

function mount(over: Record<string, unknown> = {}, providers: Provider[] = []) {
  memory.save.mockClear();
  memory.read.mockClear().mockReturnValue(0);
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryListComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([]),
      { provide: ListScrollMemory, useValue: memory },
      { provide: CatalogStore, useValue: catalog },
      { provide: SubscriptionsStore, useValue: subscriptionsStore },
      { provide: MAGAZINE_STYLE_WRITER, useValue: { write: () => of(true) } },
      ...providers,
    ],
  });
  const fixture = TestBed.createComponent(EntryListComponent);
  const inputs = {
    title: 'All items',
    entries: [entry(1), entry(2)],
    loading: false,
    loadingMore: false,
    error: null,
    hasMore: false,
    canMarkAllRead: true,
    selection: { kind: 'all', id: null, unread: true },
    openEntryId: null,
    ...over,
  };
  for (const [key, value] of Object.entries(inputs)) fixture.componentRef.setInput(key, value);
  fixture.detectChanges();
  return fixture;
}

// A standalone host purely to mint a real TemplateRef — NgTemplateOutlet
// accepts one from any module, so it doesn't need to come from the same
// TestBed instance that renders EntryListComponent.
@Component({
  standalone: true,
  template: `<ng-template #tb><p class="top-marker">Top</p></ng-template>`,
})
class TemplateHost {
  @ViewChild('tb', { static: true }) tb!: TemplateRef<unknown>;
}

function topBlockTemplate(): TemplateRef<unknown> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ imports: [TemplateHost] });
  const fixture = TestBed.createComponent(TemplateHost);
  fixture.detectChanges();
  return fixture.componentInstance.tb;
}

/** Drain the animation frames the component schedules, the way the browser does
 *  between two renders. Two deep because the focus pass can be scheduled from
 *  inside a frame. */
const frames = (): Promise<void> =>
  new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));

/** The inline opacity the reading-focus pass writes on each row. jsdom measures
 *  no layout, so every visible row scores 1 — an EMPTY string is the signal
 *  under test: it means the pass never touched that row (#462). */
function rowOpacities(fixture: ComponentFixture<EntryListComponent>): string[] {
  const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows')!;
  return (Array.from(rows.children) as HTMLElement[])
    .filter((child) => !child.classList.contains('foot'))
    .map((child) => child.style.opacity);
}

/** jsdom has no layout, so its scrollTop is permanently 0. Give the scroller a
 *  real one, starting at `top`. */
function fakeScroller(fixture: ComponentFixture<EntryListComponent>, top: number): HTMLElement {
  const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
  let offset = top;
  Object.defineProperty(rows, 'scrollTop', {
    configurable: true,
    get: () => offset,
    set: (value: number) => {
      offset = value;
    },
  });
  return rows;
}

/** Fires the reading-focus applier's own observer on the `.rows` scroller — the
 *  last mock instance watching it, since the applier is rebuilt whenever the
 *  scroller element swaps. */
function fireRowsResize(fixture: ComponentFixture<EntryListComponent>): void {
  const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows')!;
  const obs = MockResizeObserver.instances.find((observer) => observer.targets.has(rows));
  obs?.fire();
}

describe('EntryListComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    subscriptionsStore.resolved.set(true);
    subscribedTo(1);
    MockResizeObserver.instances = [];
    (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
  });

  // #321: the for-you block is now projected into the top of whichever
  // content branch is live, so it scrolls away with the list instead of
  // sitting in a permanently reserved bar above it.
  describe('topBlock', () => {
    it('renders above the list rows', () => {
      const tb = topBlockTemplate();
      const element = mount({ topBlock: tb, layout: 'list' }).nativeElement as HTMLElement;
      const rows = element.querySelector('.rows')!;
      expect(rows.querySelector('.top-marker')).not.toBeNull();
      // It scrolls with the rows: it lives inside the scroller, not before it.
      // The projected node sits in the wrapper this component puts around the
      // outlet, which is what carries the column's sizing to it.
      expect(rows.firstElementChild!.classList).toContain('top-block');
      expect(rows.firstElementChild!.firstElementChild!.classList).toContain('top-marker');
    });

    it('renders above the magazine rows', () => {
      const tb = topBlockTemplate();
      const element = mount({ topBlock: tb, layout: 'magazine' }).nativeElement as HTMLElement;
      const rows = element.querySelector('.rows.magazine')!;
      expect(rows.querySelector('.top-marker')).not.toBeNull();
      expect(rows.firstElementChild!.classList).toContain('top-block');
      expect(rows.firstElementChild!.firstElementChild!.classList).toContain('top-marker');
    });

    it('renders above the empty state, so the run button still shows there', () => {
      const tb = topBlockTemplate();
      const element = mount({ topBlock: tb, entries: [] }).nativeElement as HTMLElement;
      expect(element.querySelector('.top-marker')).not.toBeNull();
      expect(element.querySelector('.empty')).not.toBeNull();
    });

    it('renders nothing extra when no topBlock is provided', () => {
      const element = mount().nativeElement as HTMLElement;
      expect(element.querySelector('.top-marker')).toBeNull();
    });
  });

  describe('recommendation strip', () => {
    const recommended = [
      entry(1, { recommendationReason: 'because you read src', recommendationScore: 910 }),
      entry(2, { recommendationReason: 'similar to your favorites' }),
    ];
    const forYou = { kind: 'for-you', id: null, unread: false };

    it('shows the reason on each for-you entry in the list layout', () => {
      const element = mount({ entries: recommended, selection: forYou, layout: 'list' })
        .nativeElement as HTMLElement;
      const reasons = element.querySelectorAll('app-recommendation-strip .reason');
      expect(reasons.length).toBe(2);
      expect(reasons[0].textContent).toContain('because you read src');
      expect(
        element.querySelector('app-recommendation-strip .reason .score')!.textContent,
      ).toContain('91');
    });

    it('shows the reason on each for-you entry in the magazine layout', () => {
      const element = mount({ entries: recommended, selection: forYou, layout: 'magazine' })
        .nativeElement as HTMLElement;
      const reasons = element.querySelectorAll('app-recommendation-strip .reason');
      expect(reasons.length).toBe(2);
      expect(reasons[0].textContent).toContain('because you read src');
    });

    it('stays inert on a non-for-you view', () => {
      const element = mount({
        entries: [entry(1), entry(2)],
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.reason')).toBeNull();
    });
  });

  describe('run-boundary dividers (#348)', () => {
    const forYou = { kind: 'for-you', id: null, unread: false };
    // Run 9 is the newest (its id is what the header names); run 7 is older.
    const NEWEST = '2026-08-09T10:00:00+00:00';
    const OLDER = '2026-08-07T09:05:00+00:00';
    const twoRuns = [
      entry(1, { runId: 9, runGeneratedAt: NEWEST }),
      entry(2, { runId: 9, runGeneratedAt: NEWEST }),
      entry(3, { runId: 7, runGeneratedAt: OLDER }),
    ];

    it('shows a divider at the older run and none above the newest', () => {
      const element = mount({
        entries: twoRuns,
        selection: forYou,
        newestRunId: 9,
        layout: 'list',
      }).nativeElement as HTMLElement;

      // One divider only — for the older run.
      expect(element.querySelectorAll('app-run-header').length).toBe(1);
      // It is not the first child of the scroller (the newest run's rows are).
      const rows = element.querySelector('.rows')!;
      expect(rows.firstElementChild!.tagName.toLowerCase()).not.toBe('app-run-header');
    });

    it('shows a divider on the top block when it is not the newest run', () => {
      // Header names run 9, but run 9 left nothing visible: the first visible
      // entry is run 7, so it gets its own divider.
      const element = mount({
        entries: [entry(3, { runId: 7, runGeneratedAt: OLDER })],
        selection: forYou,
        newestRunId: 9,
        layout: 'list',
      }).nativeElement as HTMLElement;

      expect(element.querySelectorAll('app-run-header').length).toBe(1);
    });

    it('shows no divider on a non-for-you view', () => {
      const element = mount({
        entries: [entry(1), entry(2)],
        selection: { kind: 'all', id: null, unread: true },
        layout: 'list',
      }).nativeElement as HTMLElement;

      expect(element.querySelector('app-run-header')).toBeNull();
    });

    it('shows a divider at the older run in the magazine layout', () => {
      const element = mount({
        entries: twoRuns,
        selection: forYou,
        newestRunId: 9,
        layout: 'magazine',
      }).nativeElement as HTMLElement;

      const rows = element.querySelector('.rows.magazine')!;
      expect(rows.querySelectorAll('app-run-header').length).toBe(1);
      // No magazine block is emitted before the newest run's first block.
      expect(rows.firstElementChild!.tagName.toLowerCase()).not.toBe('app-run-header');
    });
  });

  // #325: the shell projects the For You run/stop button here, right-aligned in
  // the header, without this generic list knowing what the action is.
  describe('headerActions', () => {
    it('renders the projected action inside the list header tools', () => {
      const actions = topBlockTemplate();
      const element = mount({ headerActions: actions }).nativeElement as HTMLElement;
      expect(element.querySelector('.list-header .tools .top-marker')).not.toBeNull();
    });

    it('renders nothing in the header when no headerActions is provided', () => {
      const element = mount().nativeElement as HTMLElement;
      expect(element.querySelector('.list-header .top-marker')).toBeNull();
    });
  });

  describe('titleLeading', () => {
    it('renders the projected content before the title', () => {
      const element = mount({ titleLeading: topBlockTemplate() }).nativeElement as HTMLElement;
      expect(element.querySelector('.title-row .top-marker')).not.toBeNull();
    });

    it('renders nothing before the title when no titleLeading is provided', () => {
      const element = mount().nativeElement as HTMLElement;
      expect(element.querySelector('.title-row .top-marker')).toBeNull();
    });
  });

  it('renders a row per entry and the header title', () => {
    const element = mount().nativeElement as HTMLElement;
    expect(element.querySelector('.list-header')!.textContent).toContain('All items');
    expect(element.querySelectorAll('app-entry-row').length).toBe(2);
  });

  // #581: the shell assembles the search title's muted lead, quoted term and
  // count pill, so this list only renders the split; every other selection
  // keeps the plain title.
  describe('the split search title (#581 follow-up)', () => {
    it('renders the muted prefix, the prominent term, and the count pill for a search selection', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
        searchTitlePrefix: 'Results for',
        searchTitleTerm: '"punk"',
        searchCountLabel: '2',
      }).nativeElement as HTMLElement;

      const heading = element.querySelector('.list-header h2')!;
      const prefix = heading.querySelector('.results-prefix');
      const term = heading.querySelector('.results-term');
      const count = heading.querySelector('.results-count');
      expect(prefix?.textContent).toBe('Results for');
      expect(term?.textContent).toBe('"punk"');
      expect(count?.textContent).toBe('2');

      // The prefix and term spans are adjacent in the template, and Angular
      // drops a purely-whitespace text node between two elements — so
      // asserting each span's own text separately (above) cannot catch a
      // missing space between them. Assert the heading's combined,
      // whitespace-collapsed text instead: it must read one sentence, not
      // "for" running straight into the opening quote.
      expect(heading.textContent?.replace(/\s+/g, ' ')).toContain('Results for "');
    });

    it('places a mobile-only line break after the search title prefix', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
        searchTitlePrefix: 'Results for',
        searchTitleTerm: '"punk"',
        searchCountLabel: '2',
      }).nativeElement as HTMLElement;

      const heading = element.querySelector('.list-header h2')!;
      expect(heading.querySelector('.results-prefix + .compact-search-title-break')).not.toBeNull();
    });

    it('renders a trailing + on the count pill when another page is still out there', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
        searchTitlePrefix: 'Results for',
        searchTitleTerm: '"punk"',
        searchCountLabel: '50+',
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.list-header h2 .results-count')?.textContent).toBe('50+');
    });

    it('renders no count pill while the search is still loading', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
        searchTitlePrefix: 'Results for',
        searchTitleTerm: '"punk"',
        searchCountLabel: null,
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.list-header h2 .results-count')).toBeNull();
    });

    it('renders the plain title, with no split spans, for a non-search selection', () => {
      const element = mount().nativeElement as HTMLElement;
      const heading = element.querySelector('.list-header h2')!;
      expect(heading.querySelector('.results-prefix')).toBeNull();
      expect(heading.querySelector('.results-term')).toBeNull();
      expect(heading.querySelector('.results-count')).toBeNull();
      expect(heading.textContent).toContain('All items');
    });
  });

  // The heading says how much the list holds, the same number the sidebar row
  // beside it shows (#709). The shell resolves it once and hands it down; this
  // list only renders it.
  describe('the list count (#709)', () => {
    it('shows how much the list holds beside its name', () => {
      const element = mount({ titleCount: { value: 12, counts: 'unread' } })
        .nativeElement as HTMLElement;

      expect(element.querySelector('.list-header .title-count')?.textContent?.trim()).toBe('12');
    });

    it('shows no count for a list with nothing in it', () => {
      const element = mount({ titleCount: { value: 0, counts: 'unread' } })
        .nativeElement as HTMLElement;

      expect(element.querySelector('.list-header .title-count')).toBeNull();
    });

    // Outside the h2, beside it: the h2 ellipsises, so a long feed name would
    // clip the count away — the same arrangement the whole-word badge uses.
    it('keeps the count out of the ellipsised title so a long name cannot clip it', () => {
      const element = mount({
        title: 'A feed name far longer than this header has ever been able to show',
        titleCount: { value: 12, counts: 'unread' },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.list-header h2 .title-count')).toBeNull();
      expect(element.querySelector('.list-header .title-row > .title-count')).not.toBeNull();
    });

    // The visible pill is a bare number; on its own it would announce as one.
    // The heading carries the phrase instead, so heading navigation hears what
    // the number counts.
    it('names an unread count in the heading for a screen reader', () => {
      const element = mount({ titleCount: { value: 12, counts: 'unread' } })
        .nativeElement as HTMLElement;

      expect(element.querySelector('.list-header h2 .sr-only')?.textContent).toContain('12 unread');
    });

    it('names an item count where the list counts items rather than unread', () => {
      const element = mount({
        selection: { kind: 'kept', id: null, unread: false },
        titleCount: { value: 3, counts: 'items' },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.list-header h2 .sr-only')?.textContent).toContain('3 items');
    });

    it('leaves a search the result count it already carries, and adds no second one', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
        searchTitlePrefix: 'Results for',
        searchTitleTerm: '"punk"',
        searchCountLabel: '2',
        titleCount: { value: 12, counts: 'unread' },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.list-header .title-count')).toBeNull();
      expect(element.querySelector('.list-header .results-count')?.textContent).toBe('2');
    });
  });

  // A tag's heading carries the same glyph and colour its sidebar row does, so
  // the list a reader lands in is recognisably the tag they clicked.
  describe('the tag heading', () => {
    const tag = { id: 4, name: 'Wissenschaft', color: '#c2410c', icon: 'science', position: 0 };
    // The same colour as the DOM reports it back: a style binding is normalised.
    const TAG_RGB = 'rgb(194, 65, 12)';

    it('shows the tag glyph in the tag colour beside the name', () => {
      const element = mount({ title: tag.name, titleTag: tag }).nativeElement as HTMLElement;
      const heading = element.querySelector('.list-header h2')!;
      expect(heading.textContent).toContain('Wissenschaft');

      const icon = heading.querySelector<HTMLElement>('app-tag-glyph app-icon')!;
      expect(icon.textContent!.trim()).toBe('science');
      expect(icon.style.color).toBe(TAG_RGB);
    });

    it('falls back to the colour dot for a tag with no glyph of its own', () => {
      const element = mount({ title: tag.name, titleTag: { ...tag, icon: null } })
        .nativeElement as HTMLElement;
      const heading = element.querySelector('.list-header h2')!;
      expect(heading.querySelector('app-tag-glyph app-icon')).toBeNull();
      expect(heading.querySelector<HTMLElement>('app-tag-glyph .dot')!.style.background).toBe(
        TAG_RGB,
      );
    });

    it('shows no glyph for a heading that names no tag', () => {
      const element = mount().nativeElement as HTMLElement;
      expect(element.querySelector('.list-header h2 app-tag-glyph')).toBeNull();
    });
  });

  // Each fixed view's heading carries the very icon its sidebar row shows, so
  // the list a reader lands in reads as the row they clicked (#411).
  describe('the fixed-view heading icon (#411)', () => {
    const iconByKind: readonly (readonly [string, string])[] = [
      ['all', 'inbox'],
      ['favorites', 'star'],
      ['kept', 'bookmark'],
      ['viewed', 'history'],
      ['for-you', 'auto_awesome'],
      ['search', 'search'],
    ];

    for (const [kind, iconName] of iconByKind) {
      it(`shows the ${kind} heading with the ${iconName} icon`, () => {
        const element = mount({ selection: { kind, id: null, unread: true, term: 'daft' } })
          .nativeElement as HTMLElement;
        // A direct child of the h2, so it is never confused with the icon a tag
        // glyph nests, nor with the header's tool buttons further down.
        const icon = element.querySelector<HTMLElement>('.list-header h2 > app-icon');
        expect(icon).not.toBeNull();
        expect(icon!.textContent!.trim()).toBe(iconName);
      });
    }

    it('shows no fixed-view icon for a tag heading — its glyph stands in', () => {
      const tag = { id: 4, name: 'Wissenschaft', color: '#c2410c', icon: 'science', position: 0 };
      const element = mount({ selection: { kind: 'tag', id: 4, unread: true }, titleTag: tag })
        .nativeElement as HTMLElement;
      expect(element.querySelector('.list-header h2 > app-icon')).toBeNull();
    });

    it('shows no fixed-view icon for a subscription heading — its favicon stands in', () => {
      const element = mount({ selection: { kind: 'subscription', id: 5, unread: true } })
        .nativeElement as HTMLElement;
      expect(element.querySelector('.list-header h2 > app-icon')).toBeNull();
    });
  });

  // #87: the collapsing list header floats over reserved padding; it must never
  // shrink the list's own box and resize the scroller mid-gesture.
  describe('collapsing list header', () => {
    it('publishes the expanded bar height and keeps it while collapsed', () => {
      const fixture = mount({ layout: 'list' });
      const host = fixture.nativeElement as HTMLElement;
      // jsdom has no ResizeObserver, so stand in for the measurement.
      fixture.componentInstance.headerHeight.set(53);
      fixture.detectChanges();
      expect(host.style.getPropertyValue('--list-bar-h')).toBe('53px');

      // The reservation must not follow the bar down: the scroller's padding is
      // computed from this value, and shrinking it would move every row.
      fixture.componentInstance.collapsed.set(true);
      fixture.detectChanges();
      expect(host.style.getPropertyValue('--list-bar-h')).toBe('53px');
      expect(host.querySelector('.list-header')!.classList).toContain('collapsed');
    });

    it('keeps the scroller reservation constant whether or not an error shows (#996)', () => {
      // The scroller always reserves header clearance itself, so an error
      // appearing never hands the reservation to the banner or reflows the pane.
      const withError = mount({
        layout: 'list',
        error: { type: 'about:blank', title: 'Request failed', status: 502 },
      }).nativeElement as HTMLElement;
      expect(withError.querySelector('.rows')!.classList).not.toContain('after-banner');

      const clean = mount({ layout: 'list' }).nativeElement as HTMLElement;
      expect(clean.querySelector('.rows')!.classList).not.toContain('after-banner');
    });
  });

  describe('error banner (#996)', () => {
    const failure = { type: 'about:blank', title: 'Request failed', status: 502 };

    it('routes the error through the shared banner with a friendly message and status', () => {
      const element = mount({ layout: 'list', error: failure }).nativeElement as HTMLElement;
      const banner = element.querySelector('app-error-banner');
      expect(banner).not.toBeNull();
      const text = banner!.querySelector('.text')!.textContent!;
      expect(text).toContain('502');
      expect(text).not.toContain('Request failed'); // the bare fallback never reaches the user
    });

    it('shows an offline message when the server was unreachable', () => {
      const element = mount({
        layout: 'list',
        error: { type: 'about:blank', title: 'Could not reach the server', status: 0 },
      }).nativeElement as HTMLElement;
      const text = element.querySelector('app-error-banner .text')!.textContent!;
      expect(text).toContain('Could not reach the server');
    });

    it('emits retry when the banner action is used', () => {
      const fixture = mount({ layout: 'list', error: failure });
      const retried = jest.fn();
      fixture.componentInstance.retry.subscribe(retried);

      (
        fixture.nativeElement.querySelector('app-error-banner .action') as HTMLButtonElement
      ).click();
      expect(retried).toHaveBeenCalledTimes(1);
    });

    it('emits dismiss when the banner dismiss control is used', () => {
      const fixture = mount({ layout: 'list', error: failure });
      const dismissed = jest.fn();
      fixture.componentInstance.dismiss.subscribe(dismissed);

      (
        fixture.nativeElement.querySelector('app-error-banner .dismiss') as HTMLButtonElement
      ).click();
      expect(dismissed).toHaveBeenCalledTimes(1);
    });

    it('shows no banner when there is no error', () => {
      const element = mount({ layout: 'list', error: null }).nativeElement as HTMLElement;
      expect(element.querySelector('app-error-banner')).toBeNull();
    });
  });

  it('keeps the current rows rendered and marks them reloading while a reload is on the wire', () => {
    const element = mount({ loading: true, entries: [entry(1), entry(2)], layout: 'list' })
      .nativeElement as HTMLElement;
    expect(element.querySelector('.skeleton')).toBeNull();
    const rows = element.querySelector('.rows')!;
    expect(rows.classList).toContain('reloading');
    expect(rows.querySelectorAll('app-entry-row').length).toBe(2);
  });

  it('makes the retained rows inert while a reload is on the wire', () => {
    // Without this the stale rows stay clickable: a row click during a view
    // switch opens the PREVIOUS view's entry and marks it read (#254).
    const element = mount({ loading: true, entries: [entry(1), entry(2)], layout: 'list' })
      .nativeElement as HTMLElement;
    const rows = element.querySelector('.rows')!;
    expect(rows.hasAttribute('inert')).toBe(true);
    expect(rows.getAttribute('aria-busy')).toBe('true');
  });

  it('drops the inert guard once the rows are current', () => {
    const element = mount({ loading: false, entries: [entry(1)], layout: 'list' })
      .nativeElement as HTMLElement;
    const rows = element.querySelector('.rows')!;
    expect(rows.hasAttribute('inert')).toBe(false);
  });

  describe('a reload that a search is typing its way through', () => {
    const typing = {
      loading: true,
      entries: [entry(1), entry(2)],
      layout: 'list',
      selection: { kind: 'search', id: null, unread: false, term: 'punk' },
    };

    it('leaves the retained rows undimmed and clickable', () => {
      const element = mount(typing).nativeElement as HTMLElement;
      const rows = element.querySelector('.rows')!;
      expect(rows.classList).not.toContain('reloading');
      expect(rows.hasAttribute('inert')).toBe(false);
    });

    it('still reports the rows as busy to assistive technology', () => {
      const element = mount(typing).nativeElement as HTMLElement;
      expect(element.querySelector('.rows')!.getAttribute('aria-busy')).toBe('true');
    });

    it('never raises the loading overlay, however long the search runs', () => {
      jest.useFakeTimers();
      try {
        const fixture = mount(typing);
        jest.advanceTimersByTime(5000);
        fixture.detectChanges();
        expect(
          (fixture.nativeElement as HTMLElement).querySelector('app-loading-overlay.shown'),
        ).toBeNull();
      } finally {
        jest.useRealTimers();
      }
    });

    it('still raises the loading overlay for a reload outside a search', () => {
      jest.useFakeTimers();
      try {
        const fixture = mount({ loading: true, entries: [entry(1), entry(2)], layout: 'list' });
        jest.advanceTimersByTime(5000);
        fixture.detectChanges();
        expect(
          (fixture.nativeElement as HTMLElement).querySelector('app-loading-overlay.shown'),
        ).not.toBeNull();
      } finally {
        jest.useRealTimers();
      }
    });
  });

  it('shows skeletons while loading and an empty state when empty', () => {
    expect(
      (mount({ loading: true, entries: [] }).nativeElement as HTMLElement).querySelector(
        '.skeleton',
      ),
    ).not.toBeNull();
    expect(
      (mount({ loading: false, entries: [] }).nativeElement as HTMLElement).querySelector('.empty'),
    ).not.toBeNull();
  });

  it('shows the search empty state with the term, and no catalog link', () => {
    const element = mount({
      loading: false,
      entries: [],
      selection: { kind: 'search', id: null, unread: false, term: 'angular' },
    }).nativeElement as HTMLElement;
    const empty = element.querySelector('.empty')!;
    expect(empty.textContent).toContain('Nothing matches "angular".');
    expect(empty.textContent).not.toContain('Nothing here yet.');
    expect(empty.querySelector('a')).toBeNull();
  });

  describe('the combined saved-search list empty state (#769)', () => {
    it('says there are no saved searches yet when the account keeps none', () => {
      const element = mount({
        loading: false,
        entries: [],
        selection: { kind: 'saved-searches', id: null, unread: false },
        savedSearchCount: 0,
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.empty')!.textContent).toContain('No saved searches yet');
    });

    it('says the list is empty when saved searches exist but match nothing', () => {
      const element = mount({
        loading: false,
        entries: [],
        selection: { kind: 'saved-searches', id: null, unread: false },
        savedSearchCount: 2,
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.empty')!.textContent).not.toContain('No saved searches yet');
    });
  });

  it(
    'derives highlighting terms from a trailing-space term without a trailing empty ' +
      "entry (#408 follow-up: the trailing space is the server's whole-word signal, " +
      'not a word of its own)',
    () => {
      const fixture = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk ' },
      });
      expect(fixture.componentInstance.searchTerms()).toEqual(['punk']);
    },
  );

  // #432: Meilisearch tolerates typos, so the literal typed term may appear
  // nowhere in a row that legitimately matched — rows must mark what the
  // engine matched, not only what was typed.
  describe('searchTerms prefers matchedWords (#432)', () => {
    it('marks the words the engine matched when the page carries them', () => {
      const fixture = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'recieve' },
        matchedWords: ['receive'],
      });
      expect(fixture.componentInstance.searchTerms()).toEqual(['receive']);
    });

    it(
      'falls back to the terms split from the selection when the page carries none — ' +
        "exactly as before this feature, which is the database LIKE fallback's normal answer",
      () => {
        const fixture = mount({
          selection: { kind: 'search', id: null, unread: false, term: 'punk rock' },
          matchedWords: [],
        });
        expect(fixture.componentInstance.searchTerms()).toEqual(['punk', 'rock']);
      },
    );

    it('defaults to the selection terms when matchedWords is not bound at all', () => {
      const fixture = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
      });
      expect(fixture.componentInstance.searchTerms()).toEqual(['punk']);
    });
  });

  describe('whole-word search badge (#408 follow-up)', () => {
    it('renders the badge for a whole-word (trailing-space) search selection', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk ' },
      }).nativeElement as HTMLElement;
      const badge = element.querySelector('.whole-word-badge');
      expect(badge).not.toBeNull();
      expect(badge!.textContent).toContain('Whole words');
    });

    it('does not render the badge for a substring search selection', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.whole-word-badge')).toBeNull();
    });

    it('does not render the badge for a non-search selection', () => {
      const element = mount({
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.whole-word-badge')).toBeNull();
    });

    // Round 2: aria-describedby only speaks on focus, so a screen-reader
    // user navigating by headings never heard it. The mode is now part of
    // the heading's own accessible name via a visually-hidden phrase.
    it('announces the mode as part of the heading, not only via focus', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk ' },
      }).nativeElement as HTMLElement;
      const heading = element.querySelector('.list-header h2')!;
      expect(heading.querySelector('.sr-only')!.textContent).toContain('Whole words');
    });

    it('marks the visible badge aria-hidden so it is not announced a second time', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk ' },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.whole-word-badge')!.getAttribute('aria-hidden')).toBe('true');
    });

    it('puts no whole-word phrase in the heading for a substring search', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'punk' },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.list-header h2 .sr-only')).toBeNull();
    });
  });

  describe('phrase search badge (#702)', () => {
    it('renders the phrase badge for a quoted search selection', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: '"climate change"' },
      }).nativeElement as HTMLElement;
      const badge = element.querySelector('.phrase-badge');
      expect(badge).not.toBeNull();
      expect(badge!.textContent).toContain('Phrase');
      expect(badge!.getAttribute('aria-hidden')).toBe('true');
      expect(element.querySelector('.list-header h2 .sr-only')!.textContent).toContain('Phrase');
    });

    it('renders no phrase badge for an unquoted search', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: 'climate change' },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.phrase-badge')).toBeNull();
    });

    it('shows only the phrase badge when a phrase also carries a trailing space', () => {
      const element = mount({
        selection: { kind: 'search', id: null, unread: false, term: '"climate change" ' },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.phrase-badge')).not.toBeNull();
      expect(element.querySelector('.whole-word-badge')).toBeNull();
    });
  });

  it('hides the whole-word-mode trailing space from the empty-state message (#408 follow-up)', () => {
    const element = mount({
      loading: false,
      entries: [],
      selection: { kind: 'search', id: null, unread: false, term: 'punk ' },
    }).nativeElement as HTMLElement;
    const empty = element.querySelector('.empty')!;
    expect(empty.textContent).toContain('Nothing matches "punk".');
    expect(empty.textContent).not.toContain('punk "');
  });

  it('keeps the existing empty state and its catalog link for a non-search selection', () => {
    const element = mount({
      loading: false,
      entries: [],
      selection: { kind: 'all', id: null, unread: false },
    }).nativeElement as HTMLElement;
    const empty = element.querySelector('.empty')!;
    expect(empty.textContent).toContain('Nothing here yet.');
    expect(empty.querySelector('a')).not.toBeNull();
  });

  describe('the suggested-feeds link in the empty state', () => {
    const emptyAll = {
      loading: false,
      entries: [],
      selection: { kind: 'all', id: null, unread: true },
    };

    it('offers the catalog to an account with only a few subscriptions', () => {
      subscribedTo(4);
      const element = mount(emptyAll).nativeElement as HTMLElement;

      expect(element.querySelector('.empty a')).not.toBeNull();
    });

    it('leaves it out once the account follows five or more feeds', () => {
      subscribedTo(5);
      const element = mount(emptyAll).nativeElement as HTMLElement;

      expect(element.querySelector('.empty a')).toBeNull();
    });

    it('leaves it out until the subscriptions have loaded', () => {
      subscriptionsStore.resolved.set(false);
      subscribedTo(0);
      const element = mount(emptyAll).nativeElement as HTMLElement;

      expect(element.querySelector('.empty a')).toBeNull();
    });
  });

  describe('the caught-up illustration (#1198)', () => {
    it('sits in the empty state of an unread selection', () => {
      const element = mount({
        loading: false,
        entries: [],
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      const empty = element.querySelector('.empty')!;

      expect(empty.textContent).toContain("You're all caught up.");
      expect(empty.querySelector('app-caught-up-illustration')).not.toBeNull();
    });

    it('stays out of the "Nothing here yet." state', () => {
      const element = mount({
        loading: false,
        entries: [],
        selection: { kind: 'all', id: null, unread: false },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('app-caught-up-illustration')).toBeNull();
    });

    it('stays out of an unread search that matches nothing', () => {
      const element = mount({
        loading: false,
        entries: [],
        selection: { kind: 'search', id: null, unread: true, term: 'angular' },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('app-caught-up-illustration')).toBeNull();
    });
  });

  it('emits loadMore from the fallback button and markAllRead', () => {
    const fixture = mount({ hasMore: true });
    let more = 0,
      mar = 0;
    fixture.componentInstance.loadMore.subscribe(() => more++);
    fixture.componentInstance.markAllRead.subscribe(() => mar++);
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('.load-more') as HTMLButtonElement).click();
    (element.querySelector('.mark-all') as HTMLButtonElement).click();
    expect([more, mar]).toEqual([1, 1]);
  });

  it('keeps the sentinel foot inside the scroll container', () => {
    const element = mount({ hasMore: true }).nativeElement as HTMLElement;
    // The observer root is .rows, so the sentinel must be a descendant of it.
    expect(element.querySelector('.rows .load-more')).not.toBeNull();
  });

  // #91: on a slow backend a fixed 300px lead means scrolling into the spinner.
  // The lead scales with the scroller so it is the same number of screens
  // whatever the window height.
  it('observes the sentinel a viewport-scaled distance ahead', () => {
    const seen: IntersectionObserverInit[] = [];
    const real = globalThis.IntersectionObserver;
    globalThis.IntersectionObserver = class {
      constructor(_callback: IntersectionObserverCallback, init?: IntersectionObserverInit) {
        seen.push(init ?? {});
      }
      readonly observe = jest.fn();
      readonly unobserve = jest.fn();
      readonly disconnect = jest.fn();
      takeRecords(): [] {
        return [];
      }
    } as unknown as typeof IntersectionObserver;

    try {
      // No sentinel yet, so no observer — this lets us fake the scroller's
      // height (jsdom lays nothing out) before the one we care about is made.
      const fixture = mount({ hasMore: false });
      const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows')!;
      Object.defineProperty(rows, 'clientHeight', { value: 900, configurable: true });

      fixture.componentRef.setInput('hasMore', true);
      fixture.detectChanges();

      expect(seen.at(-1)).toMatchObject({ root: rows, rootMargin: prefetchMargin(900) });
      expect(seen.at(-1)!.rootMargin).not.toBe('300px');
    } finally {
      globalThis.IntersectionObserver = real;
    }
  });

  it('hides mark-all-read when not applicable', () => {
    const element = mount({ canMarkAllRead: false }).nativeElement as HTMLElement;
    expect(element.querySelector('.mark-all')).toBeNull();
  });

  describe('unread filter switch', () => {
    it('fills the circle and marks the switch on while filtered to unread', () => {
      const element = mount({ selection: { kind: 'all', id: null, unread: true } })
        .nativeElement as HTMLElement;
      const sw = element.querySelector('.unread-switch')!;
      expect(sw.getAttribute('aria-checked')).toBe('true');
      const icon = sw.querySelector('app-icon')!;
      expect(icon.textContent?.trim()).toBe('circle');
      expect(icon.classList.contains('filled')).toBe(true);
      expect(sw.querySelector('.txt')?.textContent?.trim()).toBe('only unread');
    });

    it('empties the circle and marks the switch off while showing all', () => {
      const element = mount({ selection: { kind: 'all', id: null, unread: false } })
        .nativeElement as HTMLElement;
      const sw = element.querySelector('.unread-switch')!;
      expect(sw.getAttribute('aria-checked')).toBe('false');
      const icon = sw.querySelector('app-icon')!;
      expect(icon.textContent?.trim()).toBe('circle');
      expect(icon.classList.contains('filled')).toBe(false);
      expect(sw.querySelector('.txt')?.textContent?.trim()).toBe('All posts');
    });

    it('shows the switch for every browsable list and a search, not the state views', () => {
      for (const kind of ['all', 'tag', 'subscription', 'for-you', 'search'] as const) {
        const element = mount({ selection: { kind, id: null, unread: true, term: 'x' } })
          .nativeElement as HTMLElement;
        expect(element.querySelector('.unread-switch')).not.toBeNull();
      }
      for (const kind of ['favorites', 'kept', 'viewed'] as const) {
        const element = mount({
          selection: { kind, id: null, unread: false },
          canMarkAllRead: false,
        }).nativeElement as HTMLElement;
        expect(element.querySelector('.unread-switch')).toBeNull();
      }
    });

    it('shows the switch for an individual saved-search result', () => {
      const element = mount({
        selection: { kind: 'saved-search', id: 7, unread: false },
      }).nativeElement as HTMLElement;

      expect(element.querySelector('.unread-switch')).not.toBeNull();
    });

    it('asks for the opposite state when clicked', () => {
      for (const unread of [true, false]) {
        const fixture = mount({ selection: { kind: 'all', id: null, unread } });
        const asked: boolean[] = [];
        fixture.componentInstance.unreadOnlyChange.subscribe((value) => asked.push(value));

        (fixture.nativeElement.querySelector('.unread-switch') as HTMLButtonElement).click();

        expect(asked).toEqual([!unread]);
      }
    });
  });

  describe('list order toggle', () => {
    const toggle = (fixture: ComponentFixture<EntryListComponent>) =>
      fixture.nativeElement.querySelector('.list-order') as HTMLButtonElement;

    it('shows newest first and asks for oldest first', () => {
      const fixture = mount({ selection: { kind: 'tag', id: 3, unread: false } });
      const asked: ListOrder[] = [];
      fixture.componentInstance.orderChange.subscribe((order) => asked.push(order));

      expect(toggle(fixture).querySelector('.txt')?.textContent?.trim()).toBe('Newest first');
      expect(toggle(fixture).querySelector('app-icon')?.textContent?.trim()).toBe('arrow_downward');
      toggle(fixture).click();

      expect(asked).toEqual(['oldest']);
    });

    it('shows oldest first and asks for newest first', () => {
      const fixture = mount({ selection: { kind: 'tag', id: 3, unread: false, order: 'oldest' } });
      const asked: ListOrder[] = [];
      fixture.componentInstance.orderChange.subscribe((order) => asked.push(order));

      expect(toggle(fixture).querySelector('.txt')?.textContent?.trim()).toBe('Oldest first');
      expect(toggle(fixture).querySelector('app-icon')?.textContent?.trim()).toBe('arrow_upward');
      expect(toggle(fixture).getAttribute('aria-label')).toBe(
        'Oldest first, switch to newest first',
      );
      toggle(fixture).click();

      expect(asked).toEqual(['newest']);
    });

    it('offers the toggle on every list but for you', () => {
      const kinds = ['all', 'tag', 'subscription', 'favorites', 'kept', 'viewed'] as const;
      for (const kind of [...kinds, 'saved-searches', 'saved-search', 'search'] as const) {
        const fixture = mount({
          selection: { kind, id: 1, unread: false, term: 'x' },
          canMarkAllRead: false,
        });
        expect(toggle(fixture)).not.toBeNull();
      }
      const forYou = mount({ selection: { kind: 'for-you', id: null, unread: false } });
      expect(toggle(forYou)).toBeNull();
    });

    it('sits directly before the unread switch', () => {
      const fixture = mount({ selection: { kind: 'all', id: null, unread: false } });
      expect(toggle(fixture).nextElementSibling?.classList.contains('unread-switch')).toBe(true);
    });
  });

  it('emits refresh when the scoped refresh button is clicked', () => {
    const fixture = mount();
    let hits = 0;
    fixture.componentInstance.refresh.subscribe(() => hits++);
    (fixture.nativeElement.querySelector('.refresh') as HTMLButtonElement).click();
    expect(hits).toBe(1);
  });

  it('disables the refresh button while a run is in progress', () => {
    const fixture = mount({ refreshing: true });
    expect((fixture.nativeElement.querySelector('.refresh') as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('hides the scoped refresh button in the cross-feed saved views', () => {
    for (const kind of ['favorites', 'kept'] as const) {
      const element = mount({
        selection: { kind, id: null, unread: false },
        canMarkAllRead: false,
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.refresh')).toBeNull();
    }
  });

  it('renders no saved-search button of its own — it is a shell headerActions command', () => {
    const element = mount({
      selection: { kind: 'search', id: null, unread: false, term: 'climate' },
    }).nativeElement as HTMLElement;
    expect(element.querySelector('button.save-search')).toBeNull();
  });

  // #105: the gesture had no coverage at all, which is how a threshold that
  // needed ~400px of finger travel shipped. Drive the real listeners on the
  // scroller rather than the handler methods — the wiring is half the feature.
  describe('pull-to-refresh (mobile)', () => {
    // jsdom has no TouchEvent constructor; a plain Event with a touches list is
    // what the handlers actually read.
    function touch(type: string, y: number): Event {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'touches', { value: [{ clientX: 0, clientY: y }] });
      return event;
    }

    /** Drag `distance` px down from the top of the list and let go. */
    function pullBy(fixture: ReturnType<typeof mount>, distance: number, release = true) {
      const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      rows.dispatchEvent(touch('touchstart', 100));
      rows.dispatchEvent(touch('touchmove', 100 + distance));
      fixture.detectChanges();
      if (release) rows.dispatchEvent(touch('touchend', 100 + distance));
      return rows;
    }

    it('refreshes on a pull a thumb can actually make', () => {
      const fixture = mount();
      let hits = 0;
      fixture.componentInstance.refresh.subscribe(() => hits++);
      pullBy(fixture, 140);
      expect(hits).toBe(1);
    });

    it('does not refresh on a short pull', () => {
      const fixture = mount();
      let hits = 0;
      fixture.componentInstance.refresh.subscribe(() => hits++);
      pullBy(fixture, 40);
      expect(hits).toBe(0);
    });

    it('shows the indicator, armed only once the pull is far enough', () => {
      const fixture = mount();
      pullBy(fixture, 40, false);
      const chip = (fixture.nativeElement as HTMLElement).querySelector('.pull-indicator');
      expect(chip).not.toBeNull(); // visible feedback from the first pixels
      expect(chip!.classList).not.toContain('armed');

      pullBy(fixture, 140, false);
      expect(
        (fixture.nativeElement as HTMLElement).querySelector('.pull-indicator')!.classList,
      ).toContain('armed');
    });

    it('ignores the gesture in the cross-feed saved views', () => {
      const fixture = mount({ selection: { kind: 'favorites', id: null, unread: false } });
      let hits = 0;
      fixture.componentInstance.refresh.subscribe(() => hits++);
      pullBy(fixture, 140);
      expect(hits).toBe(0);
    });

    it('shows the spinner but no label during the pull (the label is for the running refresh)', () => {
      const fixture = mount();
      pullBy(fixture, 140, false);
      const chip = (fixture.nativeElement as HTMLElement).querySelector('.pull-indicator')!;
      expect(chip).not.toBeNull();
      expect(chip.querySelector('.label')).toBeNull();
    });
  });

  it('labels the refresh button with a refresh icon and text', () => {
    const element = mount().nativeElement as HTMLElement;
    const button = element.querySelector('.refresh') as HTMLButtonElement;
    expect(button.querySelector('app-icon[name="refresh"]')).not.toBeNull();
    expect(button.querySelector('.txt')).not.toBeNull();
  });

  it('shows a last-refreshed hint for a single-feed selection', () => {
    const element = mount({
      selection: { kind: 'subscription', id: 7, unread: true },
      lastRefreshed: '2026-07-25T08:00:00Z',
    }).nativeElement as HTMLElement;
    expect(element.querySelector('.last-refreshed')).not.toBeNull();
  });

  it('shows a last-refreshed hint for the for-you list', () => {
    const element = mount({
      selection: { kind: 'for-you', id: null, unread: false },
      lastRefreshed: '2026-08-08T09:00:00Z',
    }).nativeElement as HTMLElement;
    expect(element.querySelector('.last-refreshed')).not.toBeNull();
  });

  it('shows no last-refreshed hint for all/tag or a never-fetched feed', () => {
    expect(
      (
        mount({
          selection: { kind: 'all', id: null, unread: true },
          lastRefreshed: '2026-07-25T08:00:00Z',
        }).nativeElement as HTMLElement
      ).querySelector('.last-refreshed'),
    ).toBeNull();
    expect(
      (
        mount({ selection: { kind: 'subscription', id: 7, unread: true }, lastRefreshed: null })
          .nativeElement as HTMLElement
      ).querySelector('.last-refreshed'),
    ).toBeNull();
  });

  it('shows a next-refresh hint beside the last-refreshed one for a single feed', () => {
    const element = mount({
      selection: { kind: 'subscription', id: 7, unread: true },
      lastRefreshed: '2026-07-25T08:00:00Z',
      nextRefresh: '2099-07-25T09:00:00Z',
    }).nativeElement as HTMLElement;
    expect(element.querySelector('.next-refresh')).not.toBeNull();
  });

  it('shows no next-refresh hint for the for-you list or a gone feed', () => {
    expect(
      (
        mount({
          selection: { kind: 'for-you', id: null, unread: false },
          lastRefreshed: '2026-08-08T09:00:00Z',
          nextRefresh: null,
        }).nativeElement as HTMLElement
      ).querySelector('.next-refresh'),
    ).toBeNull();
    expect(
      (
        mount({
          selection: { kind: 'subscription', id: 7, unread: true },
          lastRefreshed: '2026-07-25T08:00:00Z',
          nextRefresh: null,
        }).nativeElement as HTMLElement
      ).querySelector('.next-refresh'),
    ).toBeNull();
  });

  it('renders planned magazine blocks when layout is magazine', () => {
    // The grouped run must not sit at the very start — the planner leads with
    // featured blocks, never a group. Lead with distinct sources so the
    // collapse-enable gate (>= MIN_VIEW_SOURCES active within 24h) is on, and
    // keep the run >= RUN_MIN so it collapses.
    const lead = [1, 2, 3, 4, 5, 6].map((id) =>
      entry(id, { subscriptionId: id, source: `lead${id}` }),
    );
    const run = [11, 12, 13, 14, 15, 16, 17, 18].map((id) =>
      entry(id, { subscriptionId: 99, source: 'Burst' }),
    );
    const tail = [21, 22, 23, 24, 25, 26, 27].map((id) =>
      entry(id, { subscriptionId: id, source: `tail${id}` }),
    );
    const element = mount({
      layout: 'magazine',
      entries: [...lead, ...run, ...tail],
    }).nativeElement as HTMLElement;
    expect(element.querySelector('app-source-group')).not.toBeNull();
    expect(element.querySelector('.rows.magazine')).not.toBeNull();
  });

  it('renders flat rows when layout is list', () => {
    const element = mount({ layout: 'list' }).nativeElement as HTMLElement;
    expect(element.querySelectorAll('app-entry-row').length).toBe(2);
    expect(element.querySelector('app-source-group')).toBeNull();
  });

  describe('airy style (#723)', () => {
    it('draws the magazine boxed by default', () => {
      const fixture = mount({ layout: 'magazine' });
      const rows = fixture.debugElement.query(By.css('.rows.magazine'));

      expect(rows.nativeElement.classList).not.toContain('airy');
    });

    it('marks the magazine airy when the account chose it', () => {
      const fixture = mount({ layout: 'magazine' });
      TestBed.inject(MagazineStyleService).set('airy');
      fixture.detectChanges();

      const rows = fixture.debugElement.query(By.css('.rows.magazine'));
      expect(rows.nativeElement.classList).toContain('airy');
    });
  });

  describe('search forces the list layout (#408)', () => {
    it('renders rows, not magazine blocks, for a search selection even when layout is magazine', () => {
      const element = mount({
        layout: 'magazine',
        selection: { kind: 'search', id: null, unread: false, term: 'fox' },
      }).nativeElement as HTMLElement;
      expect(element.querySelectorAll('app-entry-row').length).toBe(2);
      expect(element.querySelector('.rows.magazine')).toBeNull();
    });

    it('still renders magazine blocks for a non-search selection under the magazine layout', () => {
      const element = mount({
        layout: 'magazine',
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('.rows.magazine')).not.toBeNull();
    });
  });

  describe('search result live region (#408)', () => {
    it('announces the loaded count for a search selection', () => {
      const element = mount({
        entries: [entry(1), entry(2), entry(3)],
        selection: { kind: 'search', id: null, unread: false, term: 'fox' },
      }).nativeElement as HTMLElement;
      const region = element.querySelector('[aria-live="polite"]');
      expect(region).not.toBeNull();
      expect(region!.textContent).toContain('3 results');
    });

    it('renders no live region for a non-search selection', () => {
      const element = mount({
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(element.querySelector('[aria-live="polite"]')).toBeNull();
    });
  });

  it('does not collapse the list header by default', () => {
    const element = mount().nativeElement as HTMLElement;
    expect(element.querySelector('.list-header')!.classList).not.toContain('collapsed');
  });

  it('collapses the list header when the collapsed state is set (scrolled down on mobile)', () => {
    const fixture = mount();
    fixture.componentInstance.collapsed.set(true);
    fixture.detectChanges();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.list-header')!.classList,
    ).toContain('collapsed');
  });

  it('re-expands the list header when the selection changes', () => {
    const fixture = mount();
    fixture.componentInstance.collapsed.set(true);
    fixture.detectChanges();
    fixture.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
    fixture.detectChanges();
    expect(fixture.componentInstance.collapsed()).toBe(false);
  });

  it('remembers the scroll offset per selection as the list is scrolled', () => {
    const fixture = mount();
    fixture.componentInstance.scrolling.onScroll({
      target: { scrollTop: 480 },
    } as unknown as Event);
    expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 480);
  });

  // #501: driven through the real element, not the handler — the wiring is the fix.
  describe('scroll events from the rows element', () => {
    it('reach the handler outside the Angular zone', () => {
      const fixture = mount();
      const zones: boolean[] = [];
      memory.save.mockImplementationOnce(() => zones.push(NgZone.isInAngularZone()));

      fakeScroller(fixture, 480).dispatchEvent(new Event('scroll'));

      expect(zones).toEqual([false]);
    });

    it('still drive the back-to-top state the template reads', () => {
      const fixture = mount();

      fakeScroller(fixture, 900).dispatchEvent(new Event('scroll'));

      expect(fixture.componentInstance.scrolling.showToTop()).toBe(true);
    });

    it('request the reading-focus frame outside the Angular zone', () => {
      const requestedInZone: boolean[] = [];
      const raf = jest.spyOn(window, 'requestAnimationFrame').mockImplementation(() => {
        requestedInZone.push(NgZone.isInAngularZone());
        return 1;
      });
      try {
        // The focus effect schedules the first pass during the initial render.
        mount();
        expect(requestedInZone.length).toBeGreaterThan(0);
        expect(requestedInZone).not.toContain(true);
      } finally {
        raf.mockRestore();
      }
    });
  });

  it('restores the saved offset once a fresh load completes (return after a resume-reload)', () => {
    // Mount mid-load (skeletons, no scroll container yet) as on a fresh page boot.
    const fixture = mount({ loading: true, entries: [] });
    memory.read.mockReturnValue(420);
    const apply = jest.spyOn(fixture.componentInstance.scrolling, 'applyScroll');

    // The first page lands: loading clears and the rows render.
    fixture.componentRef.setInput('loading', false);
    fixture.componentRef.setInput('entries', [entry(1), entry(2)]);
    fixture.detectChanges();

    expect(memory.read).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true });
    expect(apply).toHaveBeenCalledWith(expect.anything(), 420);
  });

  it('does not restore scroll while the list is still loading', () => {
    memory.read.mockReturnValue(420);
    const fixture = mount({ loading: true, entries: [] });
    const apply = jest.spyOn(fixture.componentInstance.scrolling, 'applyScroll');
    fixture.detectChanges();
    expect(apply).not.toHaveBeenCalled();
  });

  // #267. The outgoing list stays on screen while the next query runs (#254), so
  // the scroll container survives a view switch and carries the previous view's
  // offset with it. Every case below is about handing the incoming view its own
  // place instead of inheriting that one.
  describe('scroll position across a view switch', () => {
    /** Play a load through to completion, which is what marks the rendered rows
     *  as belonging to the current selection. */
    function completeLoad(fixture: ReturnType<typeof mount>): void {
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      fixture.componentRef.setInput('loading', false);
      fixture.detectChanges();
    }

    it('returns to the top when the incoming view has no remembered offset', () => {
      const fixture = mount();
      const rows = fakeScroller(fixture, 900);
      memory.read.mockReturnValue(0);

      completeLoad(fixture);

      expect(rows.scrollTop).toBe(0);
    });

    it('lands on the incoming view’s remembered offset', () => {
      const fixture = mount();
      const rows = fakeScroller(fixture, 900);
      memory.read.mockReturnValue(420);

      completeLoad(fixture);

      expect(rows.scrollTop).toBe(420);
    });

    it('moves the retained list at once, without waiting for its page', () => {
      const fixture = mount();
      const rows = fakeScroller(fixture, 900);
      memory.read.mockReturnValue(0);

      fixture.componentRef.setInput('selection', { kind: 'tag', id: 4, unread: true });
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();

      expect(rows.scrollTop).toBe(0);
    });

    it('does not write the outgoing list’s scroll into the incoming view’s key', () => {
      const fixture = mount();
      completeLoad(fixture);
      fixture.componentRef.setInput('selection', { kind: 'tag', id: 4, unread: true });
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      memory.save.mockClear();

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 480 },
      } as unknown as Event);

      expect(memory.save).not.toHaveBeenCalled();
    });

    // The guard above must not swallow a scroll during a refresh of the very
    // same view: those rows are the user's own, and dropping the save would
    // yank them back to the pre-refresh offset when the response lands.
    it('keeps remembering the offset while the same view refreshes', () => {
      const fixture = mount();
      completeLoad(fixture);
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      memory.save.mockClear();

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 480 },
      } as unknown as Event);

      expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 480);
    });
  });

  describe('back to top', () => {
    /** Stub the scroller: jsdom implements neither scrollTo nor real scrolling. */
    function stubScroller(fixture: ReturnType<typeof mount>) {
      const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;
      return { scrollTo };
    }

    it('shows the button only once the list is scrolled well down', () => {
      const fixture = mount();
      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('app-to-top-button')).toBeNull();

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);
      fixture.detectChanges();
      expect(element.querySelector('app-to-top-button')).not.toBeNull();

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 100 },
      } as unknown as Event);
      fixture.detectChanges();
      expect(element.querySelector('app-to-top-button')).toBeNull();
    });

    it('scrolls the container to the top, expands the bar and forgets the offset', () => {
      const fixture = mount();
      const { scrollTo } = stubScroller(fixture);
      fixture.componentInstance.collapsed.set(true);
      memory.save.mockClear();

      fixture.componentInstance.scrollToTop();

      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
      expect(fixture.componentInstance.collapsed()).toBe(false);
      expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 0);
    });

    it('clicking the button scrolls to the top', () => {
      const fixture = mount();
      const { scrollTo } = stubScroller(fixture);
      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);
      fixture.detectChanges();

      (
        (fixture.nativeElement as HTMLElement).querySelector(
          'app-to-top-button button',
        ) as HTMLButtonElement
      ).click();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    // #98: the button unmounts once showToTop flips false, which would otherwise
    // drop keyboard focus to <body> and strand a keyboard/screen-reader user.
    it('moves focus to the list title instead of dropping it to the body', () => {
      const fixture = mount();
      stubScroller(fixture);
      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);
      fixture.detectChanges();

      (
        (fixture.nativeElement as HTMLElement).querySelector(
          'app-to-top-button button',
        ) as HTMLButtonElement
      ).click();

      expect(document.activeElement).toBe(
        (fixture.nativeElement as HTMLElement).querySelector('.list-header .heading h2'),
      );
    });

    it('scrolls the magazine layout’s own container too', () => {
      // The magazine branch renders a different #rows element; scrollToTop has to
      // resolve the live one at call time rather than caching it.
      const fixture = mount({ layout: 'magazine' });
      const { scrollTo } = stubScroller(fixture);

      fixture.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('hides the button again when the selection changes', () => {
      const fixture = mount();
      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);
      fixture.detectChanges();
      expect(
        (fixture.nativeElement as HTMLElement).querySelector('app-to-top-button'),
      ).not.toBeNull();

      fixture.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
      fixture.detectChanges();
      expect(fixture.componentInstance.scrolling.showToTop()).toBe(false);
    });

    it('keeps the bar expanded as the smooth scroll travels back up', () => {
      const fixture = mount();
      stubScroller(fixture);
      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 3000 },
      } as unknown as Event);
      fixture.componentInstance.scrollToTop();
      // The animation's own first event, still deep in the list. Against a zeroed
      // baseline this would read as a 2900px scroll *down* and re-collapse the bar.
      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 2900 },
      } as unknown as Event);
      expect(fixture.componentInstance.collapsed()).toBe(false);
    });

    it('does nothing when there is no scroll container yet', () => {
      const fixture = mount({ entries: [] });
      memory.save.mockClear();
      expect(() => fixture.componentInstance.scrollToTop()).not.toThrow();
      expect(memory.save).not.toHaveBeenCalled();
    });
  });

  // #1080: the lower-left "mark everything above as read" button. jsdom lays
  // nothing out, so geometry is driven through fakes standing in for the
  // `#rows`/`#listHdr` viewChild signals, exactly like the real elements they
  // replace (getBoundingClientRect, querySelector/All, getAttribute).
  describe('mark-above-read button (#1080)', () => {
    const measuredEntry = (id: string, bottom: number): HTMLElement =>
      ({
        getAttribute: () => id,
        getBoundingClientRect: () => ({ bottom }),
      }) as unknown as HTMLElement;

    /** Stubs the component's `rows`/`listHdr` viewChild signals with fakes whose
     *  geometry is scripted: `fold.scrollerTop`/`fold.headerBottom` place the fold line,
     *  `entries` become the tagged rows `querySelector(All)` finds. */
    function stubGeometry(
      fixture: ReturnType<typeof mount>,
      entries: HTMLElement[],
      fold: { scrollerTop?: number; headerBottom?: number } = {},
    ): void {
      const scroller = {
        getBoundingClientRect: () => ({ top: fold.scrollerTop ?? 0 }),
        querySelector: () => entries[0] ?? null,
        querySelectorAll: () => entries as unknown as NodeListOf<Element>,
      } as unknown as HTMLElement;
      const header = {
        getBoundingClientRect: () => ({ bottom: fold.headerBottom ?? 100 }),
      } as unknown as HTMLElement;

      jest
        .spyOn(fixture.componentInstance as unknown as { rows: () => unknown }, 'rows')
        .mockReturnValue({ nativeElement: scroller });
      jest
        .spyOn(fixture.componentInstance as unknown as { listHdr: () => unknown }, 'listHdr')
        .mockReturnValue({ nativeElement: header });
    }

    it('collects ids of entries fully above the fold at click', () => {
      const fixture = mount();
      // fold line = max(scroller.top=0, listHdr.bottom=100) = 100
      stubGeometry(fixture, [
        measuredEntry('1', 40),
        measuredEntry('2', 90),
        measuredEntry('3', 150),
      ]);

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      fixture.componentInstance.onMarkAboveRead();

      expect(emitted).toEqual([[1, 2]]);
    });

    it('expands an above-fold entry with its hidden duplicate copies (#1080)', () => {
      const fixture = mount({
        entries: [entry(1, { duplicates: [entry(101), entry(102)] }), entry(2)],
      });
      stubGeometry(fixture, [measuredEntry('1', 40)]);

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      fixture.componentInstance.onMarkAboveRead();

      expect(emitted).toEqual([[1, 101, 102]]);
    });

    it('emits nothing when no entry has cleared the fold', () => {
      const fixture = mount();
      stubGeometry(fixture, [measuredEntry('1', 150)]);

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      fixture.componentInstance.onMarkAboveRead();

      expect(emitted).toEqual([]);
    });

    it('shows the button only once scrolled down and an entry sits above the fold', () => {
      const fixture = mount();
      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('.mark-above')).toBeNull();

      fixture.componentInstance.scrolling.showToTop.set(true);
      fixture.detectChanges();
      expect(element.querySelector('.mark-above')).toBeNull();

      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.detectChanges();
      expect(element.querySelector('.mark-above')).not.toBeNull();
    });

    it('hides the button once scrolled back up, even with entries above the fold', () => {
      const fixture = mount();
      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.mark-above')).toBeNull();
    });

    it('hides both buttons once the list empties to the caught-up state (#1322)', () => {
      const fixture = mount();
      const element = fixture.nativeElement as HTMLElement;
      fixture.componentInstance.scrolling.showToTop.set(true);
      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.detectChanges();
      expect(element.querySelector('app-to-top-button')).not.toBeNull();
      expect(element.querySelector('.mark-above')).not.toBeNull();

      fixture.componentRef.setInput('entries', []);
      fixture.detectChanges();

      expect(element.querySelector('app-to-top-button')).toBeNull();
      expect(element.querySelector('.mark-above')).toBeNull();
    });

    it('emits the collected ids when the rendered button is clicked', () => {
      const fixture = mount();
      stubGeometry(fixture, [measuredEntry('7', 40)]);
      fixture.componentInstance.scrolling.showToTop.set(true);
      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.detectChanges();

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      (fixture.nativeElement.querySelector('.mark-above') as HTMLButtonElement).click();

      expect(emitted).toEqual([[7]]);
    });

    // A collapsed source-group widget renders only its preview rows; the folded
    // tail has no DOM node to measure. Once the whole preview is above the fold
    // the widget was scrolled past as a unit, so its tail goes with it —
    // otherwise hiding the preview surfaces the tail above the boundary.
    it('marks the folded tail of a group whose whole preview sits above the fold', () => {
      const fixture = mount({
        entries: MIXED_SOURCE_RUN,
        selection: { kind: 'all', id: null, unread: true },
        layout: 'magazine',
      });
      const group = fixture.componentInstance.content
        .visibleBlocks()
        .find((block) => block.kind === 'group') as Extract<MagazineBlock, { kind: 'group' }>;
      const preview = group.entries.slice(0, group.previewCount).map((groupEntry) => groupEntry.id);
      const tail = group.entries.slice(group.previewCount).map((groupEntry) => groupEntry.id);
      expect(tail.length).toBeGreaterThan(0);
      stubGeometry(fixture, [
        ...preview.map((id) => measuredEntry(String(id), 40)),
        measuredEntry('9', 150),
      ]);

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      fixture.componentInstance.onMarkAboveRead();

      expect(emitted).toEqual([[...preview, ...tail]]);
    });

    it('keeps the folded tail of a group while one of its preview rows straddles the fold', () => {
      const fixture = mount({
        entries: MIXED_SOURCE_RUN,
        selection: { kind: 'all', id: null, unread: true },
        layout: 'magazine',
      });
      const group = fixture.componentInstance.content
        .visibleBlocks()
        .find((block) => block.kind === 'group') as Extract<MagazineBlock, { kind: 'group' }>;
      const preview = group.entries.slice(0, group.previewCount).map((groupEntry) => groupEntry.id);
      const [last, ...above] = [...preview].reverse();
      stubGeometry(fixture, [
        ...above.reverse().map((id) => measuredEntry(String(id), 40)),
        measuredEntry(String(last), 150),
      ]);

      const emitted: number[][] = [];
      fixture.componentInstance.markAboveRead.subscribe((ids) => emitted.push(ids));
      fixture.componentInstance.onMarkAboveRead();

      expect(emitted).toEqual([above]);
    });

    it('labels the button with the done_all icon', () => {
      const fixture = mount();
      fixture.componentInstance.scrolling.showToTop.set(true);
      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.detectChanges();

      const button = (fixture.nativeElement as HTMLElement).querySelector('.mark-above')!;
      expect(button.querySelector('app-icon[name="done_all"]')).not.toBeNull();
    });

    // The scroll path resolves the scroller from the `#rows` viewChild — the
    // same source `collectAboveFoldIds`/`foldTop` use for the click path — not
    // from the scroll event's own target, so the probe is driven the same way
    // `stubGeometry` drives the click-time collection above.
    it('flags hasAboveFold from a scroll event once the first entry clears the fold', () => {
      const fixture = mount();
      stubGeometry(fixture, [measuredEntry('1', 40)]);

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);

      expect(fixture.componentInstance.scrolling.hasAboveFold()).toBe(true);
    });

    it('leaves hasAboveFold false below the back-to-top threshold, without measuring', () => {
      const fixture = mount();
      stubGeometry(fixture, [measuredEntry('1', 40)]);

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 100 },
      } as unknown as Event);

      expect(fixture.componentInstance.scrolling.hasAboveFold()).toBe(false);
    });

    it('leaves hasAboveFold false while the boundary entry has not cleared the fold', () => {
      const fixture = mount();
      stubGeometry(fixture, [measuredEntry('1', 150)]);

      fixture.componentInstance.scrolling.onScroll({
        target: { scrollTop: 900 },
      } as unknown as Event);

      expect(fixture.componentInstance.scrolling.hasAboveFold()).toBe(false);
    });

    it('does not throw for a scroll event carrying only scrollTop', () => {
      // Several pre-existing scroll tests drive onRowsScroll with a bare
      // {scrollTop} object as the event target; resolving the scroller from
      // the viewChild instead of that target must tolerate it.
      const fixture = mount();
      expect(() =>
        fixture.componentInstance.scrolling.onScroll({
          target: { scrollTop: 900 },
        } as unknown as Event),
      ).not.toThrow();
    });

    it('resets hasAboveFold when the selection changes', () => {
      const fixture = mount();
      fixture.componentInstance.scrolling.hasAboveFold.set(true);
      fixture.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
      fixture.detectChanges();
      expect(fixture.componentInstance.scrolling.hasAboveFold()).toBe(false);
    });
  });

  describe('back to top under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: realMatchMedia,
      });
    });

    it('jumps instead of animating', () => {
      // The component reads the flag once, in a field initialiser — so the stub
      // has to be in place before mount(), not before the click.
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: query.includes('prefers-reduced-motion'),
          media: query,
          onchange: null,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }),
      });

      const fixture = mount();
      const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;

      fixture.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
  });

  describe('refresh reveal', () => {
    it('holds the reveal open while a refresh runs and closes it after', () => {
      const fixture = mount({ refreshing: true });
      expect(fixture.componentInstance.pull.revealOffset()).toBe(REFRESH_REVEAL);

      fixture.componentRef.setInput('refreshing', false);
      fixture.detectChanges();
      expect(fixture.componentInstance.pull.revealOffset()).toBe(0);
    });

    it('carries no transform at rest, so a long list is not promoted to its own layer', () => {
      const fixture = mount();
      expect(fixture.componentInstance.pull.revealTransform()).toBe('none');

      fixture.componentRef.setInput('refreshing', true);
      fixture.detectChanges();
      expect(fixture.componentInstance.pull.revealTransform()).toBe(
        `translateY(${REFRESH_REVEAL}px)`,
      );
    });

    it('opens the reveal from a button refresh with no pull, and labels it', () => {
      // The list-header button and the sidebar button both just flip refreshing();
      // the reveal reads that, not the gesture, so no pull is involved here.
      const element = mount({ refreshing: true }).nativeElement as HTMLElement;
      expect(element.querySelector('.pull-indicator')).not.toBeNull();
      expect(element.querySelector('.pull-indicator .label')).not.toBeNull();
    });

    it('does not paint the tray over the skeleton while a refresh runs during the initial load', () => {
      const element = mount({ loading: true, entries: [], refreshing: true })
        .nativeElement as HTMLElement;
      expect(element.querySelector('.pull-indicator')).toBeNull();
    });

    it('does not paint the tray over the empty state while a refresh runs', () => {
      const element = mount({ loading: false, entries: [], refreshing: true })
        .nativeElement as HTMLElement;
      expect(element.querySelector('.pull-indicator')).toBeNull();
    });
  });

  describe('refresh reveal under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    beforeEach(() => {
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: query.includes('prefers-reduced-motion'),
          media: query,
          onchange: null,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }),
      });
    });

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', { writable: true, value: realMatchMedia });
    });

    it('does not reveal, even while refreshing', () => {
      const fixture = mount({ refreshing: true });
      expect(fixture.componentInstance.pull.revealOffset()).toBe(0);
      expect((fixture.nativeElement as HTMLElement).querySelector('.pull-indicator')).toBeNull();
    });
  });

  describe('for-you grouping', () => {
    const now = '2026-07-22T11:00:00Z';
    const run = [
      ...Array.from({ length: 8 }, (_, index) =>
        entry(index + 1, { subscriptionId: 1, source: 'a', publishedAt: now }),
      ),
      entry(9, { subscriptionId: 2, source: 'b', publishedAt: now }),
      entry(10, { subscriptionId: 2, source: 'b', publishedAt: now }),
      entry(11, { subscriptionId: 3, source: 'c', publishedAt: now }),
      entry(12, { subscriptionId: 3, source: 'c', publishedAt: now }),
    ];

    it('collapses a same-source run in an aggregated view', () => {
      const fixture = mount({
        entries: run,
        selection: { kind: 'all', id: null, unread: false },
        layout: 'magazine',
      });
      expect(
        fixture.componentInstance.content.blocks().some((block) => block.kind === 'group'),
      ).toBe(true);
    });

    it('never collapses the for-you list', () => {
      const fixture = mount({
        entries: run,
        selection: { kind: 'for-you', id: null, unread: false },
        layout: 'magazine',
      });
      expect(
        fixture.componentInstance.content.blocks().some((block) => block.kind === 'group'),
      ).toBe(false);
    });
  });

  // #462: rows that appear after mount get the focus pass too, not only a
  // scroll, a resize or the first frame; they must not stay undimmed.
  describe('reading focus', () => {
    const loaded = [entry(1), entry(2), entry(3)];

    it('clears dimming from the open list when disabled', async () => {
      const fixture = mount({ entries: loaded });
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');

      TestBed.inject(ReadingFocusService).setEnabled(false);
      fixture.detectChanges();

      expect(rowOpacities(fixture)).toEqual(['', '', '']);
    });

    it('restores dimming in the open list when enabled again', async () => {
      const fixture = mount({ entries: loaded });
      await frames();
      const readingFocus = TestBed.inject(ReadingFocusService);
      readingFocus.setEnabled(false);
      fixture.detectChanges();
      expect(rowOpacities(fixture)).toEqual(['', '', '']);

      readingFocus.setEnabled(true);
      fixture.detectChanges();
      await frames();

      expect(rowOpacities(fixture)).not.toContain('');
    });

    it('fades the rows that arrive after the load finishes', async () => {
      const fixture = mount({ loading: true, entries: [] });
      // Let the frame scheduled at mount drain while the request is still in
      // flight, which is where the skeleton has no rows to fade.
      await frames();
      fixture.componentRef.setInput('loading', false);
      fixture.componentRef.setInput('entries', loaded);
      fixture.detectChanges();
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });

    it('fades the incoming rows when both lists sit at the top', async () => {
      const fixture = mount({ entries: loaded });
      await frames();
      // A view switch keeps the outgoing rows on screen until the new page lands
      // (#254). Neither list has a remembered offset, so no scroll event fires.
      fixture.componentRef.setInput('selection', { kind: 'tag', id: 7, unread: true });
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      await frames();
      fixture.componentRef.setInput('loading', false);
      fixture.componentRef.setInput('entries', [entry(11), entry(12), entry(13)]);
      fixture.detectChanges();
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });

    it('fades the rows appended by load-more', async () => {
      const fixture = mount({ entries: loaded, hasMore: true });
      await frames();
      fixture.componentRef.setInput('entries', [...loaded, entry(4), entry(5)]);
      fixture.detectChanges();
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });

    // The central subscriber (#478): a saved-view row collapses in place, moving
    // the rows below it without firing a scroll. Blank the marks the first pass
    // wrote, then prove the resize pass re-touches every row.
    function blankOpacities(fixture: ComponentFixture<EntryListComponent>): HTMLElement {
      const rows = (fixture.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      for (const child of Array.from(rows.children) as HTMLElement[]) child.style.opacity = '';
      return rows;
    }

    it('recomputes focus when a row-collapse animation settles', async () => {
      const fixture = mount({ entries: loaded });
      await frames();
      blankOpacities(fixture);
      fireRowsResize(fixture);
      fixture.detectChanges();
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });

    it('recomputes focus on a view change, before the new page lands (#462)', async () => {
      const fixture = mount({ entries: loaded });
      await frames();
      blankOpacities(fixture);
      fixture.componentRef.setInput('selection', { kind: 'favorites', id: null, unread: false });
      fixture.detectChanges();
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });

    // A density switch (boxed <-> airy) keeps the same #rows element and only
    // toggles a class on it, resizing every row; the applier's own ResizeObserver
    // is what catches it, not a signal this component tracks.
    it('recomputes focus when the magazine density switches boxed <-> airy', async () => {
      const fixture = mount({ entries: loaded, layout: 'magazine' });
      await frames();
      blankOpacities(fixture);
      TestBed.inject(MagazineStyleService).set('airy');
      fixture.detectChanges();
      fireRowsResize(fixture);
      await frames();
      expect(rowOpacities(fixture)).not.toContain('');
    });
  });

  // #501: rendering a whole load-more page in one tick stalls the main thread
  // for hundreds of ms on a long list; iOS keeps scrolling into unpainted rows.
  describe('load-more reveal', () => {
    const rowCount = (fixture: ComponentFixture<EntryListComponent>): number =>
      (fixture.nativeElement as HTMLElement).querySelectorAll('.rows app-entry-row').length;
    const page = (from: number, count: number): EntryDto[] =>
      Array.from({ length: count }, (_, index) => entry(from + index));

    async function settle(fixture: ComponentFixture<EntryListComponent>): Promise<void> {
      for (let index = 0; index < 12; index++) {
        await frames();
        fixture.detectChanges();
      }
    }

    it('reveals an appended page over several frames instead of one tick', async () => {
      const first = page(1, 3);
      const fixture = mount({ entries: first, hasMore: true });
      fixture.componentRef.setInput('entries', [...first, ...page(4, REVEAL_STEP * 3)]);
      fixture.detectChanges();
      expect(rowCount(fixture)).toBe(3);

      await frames();
      fixture.detectChanges();
      const afterOneFrame = rowCount(fixture);
      expect(afterOneFrame).toBeGreaterThan(3);
      expect(afterOneFrame).toBeLessThan(3 + REVEAL_STEP * 3);

      await settle(fixture);
      expect(rowCount(fixture)).toBe(3 + REVEAL_STEP * 3);
    });

    it('renders a replaced list at once', () => {
      const fixture = mount({ entries: page(1, 3) });
      fixture.componentRef.setInput('entries', page(100, REVEAL_STEP * 3));
      fixture.detectChanges();
      expect(rowCount(fixture)).toBe(REVEAL_STEP * 3);
    });

    it('converges on the same magazine plan a one-shot render produces', async () => {
      const all = page(1, 6 + REVEAL_STEP * 2);
      const fixture = mount({ entries: all.slice(0, 6), hasMore: true, layout: 'magazine' });
      fixture.componentRef.setInput('entries', all);
      fixture.componentRef.setInput('hasMore', false);
      fixture.detectChanges();
      await settle(fixture);
      const revealed = (fixture.nativeElement as HTMLElement).querySelectorAll(
        '.magazine-slot',
      ).length;

      const oneShot = mount({ entries: all, hasMore: false, layout: 'magazine' });
      expect(revealed).toBe(
        (oneShot.nativeElement as HTMLElement).querySelectorAll('.magazine-slot').length,
      );
    });

    it('fades the rows of an appended page once they are all revealed', async () => {
      const first = page(1, 3);
      const fixture = mount({ entries: first, hasMore: true });
      await frames();
      fixture.componentRef.setInput('entries', [...first, ...page(4, REVEAL_STEP * 2)]);
      fixture.detectChanges();
      await settle(fixture);
      expect(rowOpacities(fixture)).toHaveLength(3 + REVEAL_STEP * 2);
      expect(rowOpacities(fixture)).not.toContain('');
    });
  });

  describe('freeze & remove after mark-above-read (#1080)', () => {
    it('drops a hidden id from visibleRunGroups while keeping the rest, in order', () => {
      const fixture = mount({ entries: [entry(1), entry(2), entry(3)] });

      fixture.componentInstance.content.hiddenAboveIds.set(new Set([2]));

      expect(
        fixture.componentInstance.content
          .visibleRunGroups()[0]
          .entries.map((groupEntry) => groupEntry.id),
      ).toEqual([1, 3]);
    });

    it('drops a hidden single-entry magazine block entirely', () => {
      const fixture = mount({ entries: [entry(1), entry(2), entry(3)], layout: 'magazine' });
      const before = fixture.componentInstance.content.blocks();
      const targetId = (before[0] as Extract<MagazineBlock, { entry: EntryDto }>).entry.id;

      fixture.componentInstance.content.hiddenAboveIds.set(new Set([targetId]));
      const after = fixture.componentInstance.content.visibleBlocks();

      expect(after.length).toBe(before.length - 1);
      expect(
        after.some(
          (block) =>
            block.kind !== 'group' && block.kind !== 'run-header' && block.entry.id === targetId,
        ),
      ).toBe(false);
    });

    it('shrinks a group block to its remaining entries instead of dropping it', () => {
      const fixture = mount({
        entries: MIXED_SOURCE_RUN,
        selection: { kind: 'all', id: null, unread: false },
        layout: 'magazine',
      });
      const groupBefore = fixture.componentInstance.content
        .blocks()
        .find((block) => block.kind === 'group') as Extract<MagazineBlock, { kind: 'group' }>;
      expect(groupBefore).toBeDefined();
      const hiddenId = groupBefore.entries[0].id;

      fixture.componentInstance.content.hiddenAboveIds.set(new Set([hiddenId]));
      const groupAfter = fixture.componentInstance.content
        .visibleBlocks()
        .find((block) => block.kind === 'group') as Extract<MagazineBlock, { kind: 'group' }>;

      expect(groupAfter).toBeDefined();
      expect(groupAfter.entries.map((groupEntry) => groupEntry.id)).not.toContain(hiddenId);
      expect(groupAfter.entries.length).toBe(groupBefore.entries.length - 1);
    });

    it('drops a group block entirely once every one of its entries is hidden', () => {
      const fixture = mount({
        entries: MIXED_SOURCE_RUN,
        selection: { kind: 'all', id: null, unread: false },
        layout: 'magazine',
      });
      const groupBefore = fixture.componentInstance.content
        .blocks()
        .find((block) => block.kind === 'group') as Extract<MagazineBlock, { kind: 'group' }>;

      fixture.componentInstance.content.hiddenAboveIds.set(
        new Set(groupBefore.entries.map((groupEntry) => groupEntry.id)),
      );

      expect(
        fixture.componentInstance.content.visibleBlocks().some((block) => block.kind === 'group'),
      ).toBe(false);
    });

    it('adds the given ids to hiddenAboveIds and scrolls the boundary to the top', async () => {
      const fixture = mount({ entries: [entry(1), entry(2), entry(3)] });
      const scroller = fakeScroller(fixture, 400);

      fixture.componentInstance.hideAboveMarked([1, 2]);

      expect(fixture.componentInstance.content.hiddenAboveIds()).toEqual(new Set([1, 2]));
      expect(memory.save).toHaveBeenCalledWith(fixture.componentInstance.selection(), 0);
      await frames();
      expect(scroller.scrollTop).toBe(0);
    });

    it('lowers both corner buttons: nothing is above the fold once the boundary is at the top', () => {
      const fixture = mount({ entries: [entry(1), entry(2)] });
      fixture.componentInstance.scrolling.showToTop.set(true);
      fixture.componentInstance.scrolling.hasAboveFold.set(true);

      fixture.componentInstance.hideAboveMarked([1]);

      expect(fixture.componentInstance.scrolling.showToTop()).toBe(false);
      expect(fixture.componentInstance.scrolling.hasAboveFold()).toBe(false);
    });

    it('drops the divider of a run whose blocks are all hidden (magazine)', () => {
      const fixture = mount({
        entries: [
          entry(1, { runId: 9, runGeneratedAt: '2026-08-09T10:00:00+00:00' }),
          entry(2, { runId: 7, runGeneratedAt: '2026-08-07T09:05:00+00:00' }),
        ],
        selection: { kind: 'for-you', id: null, unread: true },
        newestRunId: 9,
        layout: 'magazine',
      });
      expect((fixture.nativeElement as HTMLElement).querySelectorAll('app-run-header').length).toBe(
        1,
      );

      fixture.componentInstance.hideAboveMarked([2]);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).querySelector('app-run-header')).toBeNull();
    });

    it('keeps hiddenAboveIds across a layout toggle — the overlay is keyed by id', () => {
      const fixture = mount({ entries: [entry(1), entry(2)] });
      fixture.componentInstance.hideAboveMarked([1]);

      fixture.componentRef.setInput('layout', 'magazine');
      fixture.detectChanges();

      expect(fixture.componentInstance.content.hiddenAboveIds()).toEqual(new Set([1]));
    });

    it('counts the hidden rows out of visibleEntryCount', () => {
      const fixture = mount({ entries: [entry(1), entry(2)] });
      fixture.componentInstance.hideAboveMarked([1, 2]);

      expect(fixture.componentInstance.content.visibleEntryCount()).toBe(0);
    });

    it('resets hiddenAboveIds when the selection changes', () => {
      const fixture = mount({ entries: [entry(1), entry(2)] });
      fixture.componentInstance.hideAboveMarked([1]);
      expect(fixture.componentInstance.content.hiddenAboveIds().size).toBe(1);

      fixture.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
      fixture.detectChanges();

      expect(fixture.componentInstance.content.hiddenAboveIds().size).toBe(0);
    });

    it('resets hiddenAboveIds on a genuine reload', () => {
      const fixture = mount({ entries: [entry(1), entry(2)] });
      fixture.componentInstance.hideAboveMarked([1]);
      expect(fixture.componentInstance.content.hiddenAboveIds().size).toBe(1);

      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      fixture.componentRef.setInput('entries', [entry(3), entry(4)]);
      fixture.componentRef.setInput('loading', false);
      fixture.detectChanges();

      expect(fixture.componentInstance.content.hiddenAboveIds().size).toBe(0);
    });
  });

  describe('the list progress rail (#1392)', () => {
    const entriesFrom = (from: number, count: number): EntryDto[] =>
      Array.from({ length: count }, (_, index) => entry(from + index));
    const TEN_ENTRIES = entriesFrom(1, 10);
    const wideLayout: Provider = {
      provide: LayoutService,
      useValue: { isWide: signal(true), isNarrow: signal(false), isCoarse: signal(false) },
    };

    afterEach(() => jest.restoreAllMocks());

    /** jsdom has no layout: give the scroller a height and its row slots a span
     *  from `rowsTop` to `rowsBottom` that moves with `scrollTop`. */
    function layOut(
      fixture: ComponentFixture<EntryListComponent>,
      rowsTop: number,
      rowsBottom: number,
    ): HTMLElement {
      const scroller = fakeScroller(fixture, 0);
      Object.defineProperty(scroller, 'clientHeight', { value: 500, configurable: true });
      scroller.getBoundingClientRect = () => ({ top: 0, bottom: 500 }) as DOMRect;
      const slots = Array.from(scroller.querySelectorAll<HTMLElement>('.row-slot'));
      const slotHeight = (rowsBottom - rowsTop) / slots.length;
      slots.forEach((slot, index) => {
        slot.getBoundingClientRect = () => {
          const top = rowsTop + index * slotHeight - scroller.scrollTop;
          return { top, bottom: top + slotHeight } as DOMRect;
        };
      });
      return scroller;
    }

    function scrollTo(
      fixture: ComponentFixture<EntryListComponent>,
      scroller: HTMLElement,
      top: number,
    ): void {
      scroller.scrollTop = top;
      scroller.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
    }

    function rail(fixture: ComponentFixture<EntryListComponent>): HTMLElement | null {
      return (fixture.nativeElement as HTMLElement).querySelector('app-progress-rail:not(.idle)');
    }

    const fill = (fixture: ComponentFixture<EntryListComponent>): string =>
      rail(fixture)!.style.getPropertyValue('--rail-fill');

    /** Appends a page whose reveal never gets a frame, so it stays partial. */
    function appendUnrevealed(
      fixture: ComponentFixture<EntryListComponent>,
      hasMore: boolean,
    ): void {
      jest.spyOn(window, 'requestAnimationFrame').mockReturnValue(1);
      fixture.componentRef.setInput('entries', [...TEN_ENTRIES, ...entriesFrom(11, 10)]);
      fixture.componentRef.setInput('hasMore', hasMore);
      fixture.detectChanges();
    }

    const pagedList = {
      entries: TEN_ENTRIES,
      hasMore: true,
      titleCount: { value: 30, counts: 'items' },
    };
    const search = { kind: 'search', id: null, unread: false, term: 'punk' };

    it('fills by the estimated length of a paged list', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);
      expect(rail(fixture)).not.toBeNull();

      scrollTo(fixture, scroller, 1300);

      expect(fill(fixture)).toBe('50');
    });

    it('runs along the bottom on a wide layout, keeping the scrollbar', () => {
      const fixture = mount(pagedList, [wideLayout]);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);
      scrollTo(fixture, scroller, 1300);

      expect(rail(fixture)!.classList).toContain('horizontal');
      expect(fill(fixture)).toBe('50');
      expect(scroller.classList).not.toContain('scrollbar-hidden');
    });

    it('stands on the right edge in place of the scrollbar on a phone', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);

      expect(rail(fixture)!.classList).not.toContain('horizontal');
      expect(scroller.classList).toContain('scrollbar-hidden');
    });

    it('is not shown for a paged search, which has no total', () => {
      const fixture = mount({ ...pagedList, selection: search });
      scrollTo(fixture, layOut(fixture, 100, 1100), 0);
      expect(rail(fixture)).toBeNull();
    });

    it('keeps the scrollbar of a paged search, which never gets a rail', () => {
      const fixture = mount({ ...pagedList, selection: search });
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);
      expect(scroller.classList).not.toContain('scrollbar-hidden');
    });

    it('goes idle when the rows give way to the empty state', () => {
      const fixture = mount(pagedList);
      scrollTo(fixture, layOut(fixture, 100, 1100), 0);
      expect(rail(fixture)).not.toBeNull();

      fixture.componentRef.setInput('entries', []);
      fixture.componentRef.setInput('hasMore', false);
      fixture.detectChanges();

      expect(rail(fixture)).toBeNull();
    });

    it('is shown for a search that has loaded every result', () => {
      const fixture = mount({ ...pagedList, hasMore: false, selection: search });
      scrollTo(fixture, layOut(fixture, 100, 1100), 0);
      expect(rail(fixture)).not.toBeNull();
    });

    it('is not shown when the rows fit the scroller', () => {
      const fixture = mount({ ...pagedList, hasMore: false });
      scrollTo(fixture, layOut(fixture, 100, 400), 0);
      expect(rail(fixture)).toBeNull();
    });

    it('holds the total while reading lowers the count', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);

      fixture.componentRef.setInput('titleCount', { value: 25, counts: 'items' });
      fixture.detectChanges();
      scrollTo(fixture, scroller, 1300);

      expect(fill(fixture)).toBe('50');
    });

    it('takes the total afresh on each load', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);

      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      scrollTo(fixture, scroller, 0);
      fixture.componentRef.setInput('loading', false);
      fixture.componentRef.setInput('titleCount', { value: 20, counts: 'items' });
      fixture.detectChanges();
      scrollTo(fixture, scroller, 1300);

      expect(fill(fixture)).toBe('81.25');
    });

    it('does not jump ahead while an appended page is still being revealed', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 1300);
      appendUnrevealed(fixture, true);
      scrollTo(fixture, scroller, 1300);
      expect(fill(fixture)).toBe('50');
    });

    it('does not take a partly revealed last page for the end of the list', () => {
      const fixture = mount(pagedList);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 1300);
      appendUnrevealed(fixture, false);
      scrollTo(fixture, scroller, 1300);
      expect(fill(fixture)).toBe('81.25');
    });
  });
});
