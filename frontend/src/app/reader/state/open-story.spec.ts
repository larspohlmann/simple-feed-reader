import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { EntryQuery } from '../models';
import { EntriesStore } from './entries.store';
import { aStoryCard } from '../../../testing/entry-testing';
import { TestListContext } from '../../../testing/list-context-testing';
import { ListContext } from './list-context';
import { LandedList } from './landed-list';
import { ListPage } from './list-page';
import { OpenStory } from './open-story';
import { StoryCard } from '../trending/trending.models';

describe('OpenStory (#2329)', () => {
  const storyList = (storyId: number): EntryQuery => ({ view: 'all', storyId });
  const aCard: StoryCard = aStoryCard({
    id: 7,
    title: 'Harbour strike',
    synopsis: 'Dockers walked out at dawn.',
    outletCount: 9,
    shareCount: 4,
  });
  const storyPage = (lastOpenedAt: string | null): ListPage => ({
    items: [],
    nextCursor: null,
    memberCount: 31,
    unreadCount: 12,
    story: aCard,
    lastOpenedAt,
    newEntryIds: [],
  });

  let listLanded: Subject<LandedList>;
  let open: OpenStory;

  beforeEach(() => {
    localStorage.clear();
    listLanded = new Subject<LandedList>();
    TestBed.configureTestingModule({
      providers: [
        OpenStory,
        {
          provide: ListContext,
          useValue: new TestListContext({ listLanded } as unknown as EntriesStore),
        },
      ],
    });
    open = TestBed.inject(OpenStory);
  });

  it("returns the landed page's story card", () => {
    listLanded.next({ query: storyList(7), page: storyPage(null) });

    expect(open.cardOf(7)).toEqual(aCard);
    expect(open.cardOf(8)).toBeNull();

    listLanded.next({ query: storyList(7), page: { ...storyPage(null), story: null } });
    expect(open.cardOf(7)).toBeNull();
  });

  it("keeps the tally the story's page carries", () => {
    listLanded.next({ query: storyList(42), page: storyPage(null) });

    expect(open.tallyOf(42)).toEqual({ memberCount: 31, unreadCount: 12 });
    expect(open.tallyOf(7)).toBeNull();
  });

  it('counts a story followed when its page carries a last visit', () => {
    listLanded.next({ query: storyList(42), page: storyPage(null) });
    expect(open.followedOnServer(42)).toBe(false);

    listLanded.next({
      query: storyList(42),
      page: storyPage('2026-11-16T08:00:00+00:00'),
    });
    expect(open.followedOnServer(42)).toBe(true);
  });

  it('forgets the story once another list lands or the story fails to load', () => {
    listLanded.next({ query: storyList(42), page: storyPage(null) });
    listLanded.next({ query: { view: 'all' }, page: { items: [], nextCursor: null } });
    expect(open.cardOf(42)).toBeNull();

    listLanded.next({ query: storyList(42), page: storyPage(null) });
    listLanded.next({ query: storyList(42), page: null });
    expect(open.cardOf(42)).toBeNull();
  });
});
