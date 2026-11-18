import { EnvironmentInjector, createEnvironmentInjector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpRequest, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { API_BASE_URL } from '../../../core/api';
import { TokenStore } from '../../../core/auth/token.store';
import { EntryDto } from '../../models';
import { SelectionOf } from '../../query/query';
import { EntriesStore } from '../../state/entries.store';
import { StoryOverlay } from '../story-overlay.service';
import { StoryListContext } from './story-list-context';

describe('StoryListContext (#2329)', () => {
  const STORY_URL = 'https://api.test/api/entries/trending-stories/42';
  const storyRequest = (request: HttpRequest<unknown>) => request.url === STORY_URL;
  const story = (over: Partial<SelectionOf<'trending-story'>> = {}) => ({
    kind: 'trending-story' as const,
    id: 42,
    unread: false,
    ...over,
  });
  const storyPage = (entries: EntryDto[]) => ({
    entries,
    nextCursor: null,
    title: 'Harbour strike',
    memberCount: entries.length,
    unreadCount: entries.length,
    lastOpenedAt: null,
    newEntryIds: [],
  });

  let open: ReturnType<typeof signal<SelectionOf<'trending-story'> | null>>;
  let leaveStoryThatIsGone: jest.Mock;
  let ctrl: HttpTestingController;
  let layer: EnvironmentInjector;

  beforeEach(() => {
    open = signal<SelectionOf<'trending-story'> | null>(story());
    leaveStoryThatIsGone = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: StoryOverlay, useValue: { selection: open, leaveStoryThatIsGone } },
      ],
    });
    TestBed.inject(TokenStore).set('account-a.jwt');
    ctrl = TestBed.inject(HttpTestingController);
  });

  /** The story's layer, as a host the spec can take down. */
  function mount(): StoryListContext {
    layer = createEnvironmentInjector([StoryListContext], TestBed.inject(EnvironmentInjector));
    const context = layer.get(StoryListContext);
    TestBed.tick();
    return context;
  }

  const beneath = () => TestBed.inject(EntriesStore);

  function landRouteList(entries: EntryDto[]): void {
    beneath().load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries, nextCursor: null });
  }

  afterEach(() => ctrl.verify());

  it("loads the open story's own rows, in its order, apart from the list beneath", () => {
    const context = mount();

    ctrl.expectOne(storyRequest).flush(storyPage([anEntry({ id: 7 })]));
    expect(context.entries).not.toBe(beneath());
    expect(context.entries.entries().map((entry) => entry.id)).toEqual([7]);

    open.set(story({ order: 'oldest' }));
    TestBed.tick();
    const oldestFirst = ctrl.expectOne((request) => request.url === STORY_URL);
    expect(oldestFirst.request.params.get('order')).toBe('asc');
    oldestFirst.flush(storyPage([]));
  });

  it('holds its story while the layer slides out after the overlay closed', () => {
    const context = mount();
    ctrl.expectOne(storyRequest).flush(storyPage([]));

    open.set(null);
    TestBed.tick();

    expect(context.selection()).toEqual(story());
  });

  it('lets a row leave its own list, not the list beneath, and starts each story with none leaving', () => {
    const context = mount();
    ctrl.expectOne(storyRequest).flush(storyPage([]));
    context.markLeaving(7);

    open.set(story({ id: 43 }));
    TestBed.tick();
    ctrl.expectOne((request) => request.url.endsWith('/trending-stories/43')).flush(storyPage([]));

    expect(context.leavingIds().size).toBe(0);
  });

  it('lays each change to its rows over the list beneath too, which keeps its rows', () => {
    landRouteList([anEntry({ id: 7 }), anEntry({ id: 9 })]);
    const context = mount();
    ctrl.expectOne(storyRequest).flush(storyPage([anEntry({ id: 7 })]));

    context.entries.setState(7, { isKept: true });

    expect(beneath().entry(7)?.isKept).toBe(true);
    expect(
      beneath()
        .entries()
        .map((entry) => entry.id),
    ).toEqual([7, 9]);
    ctrl.expectOne('https://api.test/api/entries/7/state').flush({ state: {} });
  });

  it('shows its own failure on its own banner, not the list beneath', () => {
    landRouteList([]);
    const context = mount();
    ctrl
      .expectOne(storyRequest)
      .flush(
        { type: 'about:blank', title: 'Server error', status: 500 },
        { status: 500, statusText: 'err' },
      );

    expect(context.entries.error()?.status).toBe(500);
    expect(beneath().error()).toBeNull();
    expect(leaveStoryThatIsGone).not.toHaveBeenCalled();
  });

  it('gives way to the list beneath when the story is gone', () => {
    const context = mount();
    ctrl
      .expectOne(storyRequest)
      .flush(
        { type: 'trending_story_not_found', title: 'Trending story not found', status: 404 },
        { status: 404, statusText: 'Not Found' },
      );
    TestBed.tick();

    expect(leaveStoryThatIsGone).toHaveBeenCalled();
    expect(context.entries.error()).toBeNull();
  });

  it('shows its rows read in the list beneath too once marked all read, then reloads only itself', () => {
    landRouteList([anEntry({ id: 7, isHidden: false })]);
    const context = mount();
    ctrl.expectOne(storyRequest).flush(storyPage([anEntry({ id: 7, isHidden: false })]));

    context.showMarkedAllRead();

    expect(beneath().entry(7)?.isHidden).toBe(true);
    ctrl.expectOne(storyRequest).flush(storyPage([]));
    ctrl.expectNone((request) => request.url === 'https://api.test/api/entries');
  });

  it('stops passing changes on once its layer is gone', () => {
    landRouteList([anEntry({ id: 7 })]);
    const context = mount();
    ctrl.expectOne(storyRequest).flush(storyPage([anEntry({ id: 7 })]));
    const rows = context.entries;
    layer.destroy();

    rows.layOver({ targets: new Set([7]), patch: (row) => row });
    rows.markSharedLocally(7);

    expect(beneath().entry(7)?.isShared).toBe(false);
  });
});
