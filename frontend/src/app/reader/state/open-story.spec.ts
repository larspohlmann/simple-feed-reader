import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { EntryQuery } from '../models';
import { EntriesStore } from './entries.store';
import { LandedList } from './landed-list';
import { ListPage } from './list-page';
import { OpenStory } from './open-story';

describe('OpenStory (#2329)', () => {
  const storyList = (storyId: number): EntryQuery => ({ view: 'all', storyId });
  const storyPage = (title: string | null, lastOpenedAt: string | null): ListPage => ({
    items: [],
    nextCursor: null,
    title,
    lastOpenedAt,
    newEntryIds: [],
  });

  let listLanded: Subject<LandedList>;
  let open: OpenStory;

  beforeEach(() => {
    localStorage.clear();
    listLanded = new Subject<LandedList>();
    TestBed.configureTestingModule({
      providers: [{ provide: EntriesStore, useValue: { listLanded } }],
    });
    open = TestBed.inject(OpenStory);
  });

  it("keeps the title the story's page carries", () => {
    listLanded.next({ query: storyList(42), page: storyPage('Harbour strike', null) });

    expect(open.titleOf(42)).toBe('Harbour strike');
    expect(open.titleOf(7)).toBeNull();
  });

  it('counts a story followed when its page carries a last visit', () => {
    listLanded.next({ query: storyList(42), page: storyPage('Harbour strike', null) });
    expect(open.followedOnServer(42)).toBe(false);

    listLanded.next({
      query: storyList(42),
      page: storyPage('Harbour strike', '2026-11-16T08:00:00+00:00'),
    });
    expect(open.followedOnServer(42)).toBe(true);
  });

  it('forgets the story once another list lands or the story fails to load', () => {
    listLanded.next({ query: storyList(42), page: storyPage('Harbour strike', null) });
    listLanded.next({ query: { view: 'all' }, page: { items: [], nextCursor: null } });
    expect(open.titleOf(42)).toBeNull();

    listLanded.next({ query: storyList(42), page: storyPage('Harbour strike', null) });
    listLanded.next({ query: storyList(42), page: null });
    expect(open.titleOf(42)).toBeNull();
  });
});
