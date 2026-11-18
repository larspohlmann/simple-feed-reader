import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Subject } from 'rxjs';
import { EntryDto, EntryQuery } from '../models';
import { anEntry } from '../../../testing/entry-testing';
import { STORY_ENTRIES } from './story-entries';
import { LandedList } from './landed-list';
import { FollowedStoriesStore } from './followed-stories.store';
import { FollowedStoryVisit } from './followed-story-visit';
import { ListPage } from './list-page';

describe('FollowedStoryVisit (#2128)', () => {
  const lastOpenedAt = '2026-11-16T08:00:00+00:00';
  const before = '2026-11-16T07:00:00+00:00';
  const since = '2026-11-16T09:00:00+00:00';
  const story: EntryQuery = { view: 'all', storyId: 42 };
  const anotherList: EntryQuery = { view: 'all' };
  const member = (id: number, joinedAt: string): EntryDto => anEntry({ id, joinedAt });

  let listLanded: Subject<LandedList>;
  let entries: ReturnType<typeof signal<EntryDto[]>>;
  let markOpened: jest.Mock;
  let visit: FollowedStoryVisit;

  beforeEach(() => {
    localStorage.clear();
    listLanded = new Subject<LandedList>();
    entries = signal<EntryDto[]>([]);
    markOpened = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        { provide: STORY_ENTRIES, useValue: { listLanded, entries } },
        { provide: FollowedStoriesStore, useValue: { markOpened } },
      ],
    });
    visit = TestBed.inject(FollowedStoryVisit);
  });

  const landStory = (rows: EntryDto[], openedAt: string = lastOpenedAt) => {
    entries.set(rows);
    const page: ListPage = {
      items: rows,
      nextCursor: null,
      lastOpenedAt: openedAt,
      newEntryIds: [],
    };
    listLanded.next({ query: story, page });
  };
  const landAnotherList = () => {
    entries.set([anEntry({ id: 1 })]);
    listLanded.next({ query: anotherList, page: { items: entries(), nextCursor: null } });
  };
  const failToLoad = (query: EntryQuery) => {
    entries.set([]);
    listLanded.next({ query, page: null });
  };
  const newIds = () => [...visit.newSinceVisitIds()].sort();

  it('marks what joined since the last visit', () => {
    landStory([member(1, since), member(2, since), member(3, before)]);

    expect(newIds()).toEqual([1, 2]);
    expect(markOpened).toHaveBeenCalledWith(42);
  });

  it('records nothing before the first page has landed', () => {
    expect(markOpened).not.toHaveBeenCalled();
    expect(newIds()).toEqual([]);
  });

  it('records the visit once per story visit', () => {
    landStory([member(1, since)]);
    landStory([member(1, since)], since);

    expect(markOpened).toHaveBeenCalledTimes(1);
  });

  it('marks the rows of a later page that joined since the visit began', () => {
    landStory([member(1, since), member(2, since)]);

    entries.update((rows) => [...rows, member(4, since), member(5, before)]);

    expect(newIds()).toEqual([1, 2, 4]);
  });

  it("keeps the visit's marks across a reload whose lastOpenedAt has moved", () => {
    landStory([member(1, since), member(2, since), member(3, before)]);

    const joinedDuringTheVisit = '2026-11-16T11:00:00+00:00';
    landStory(
      [member(9, joinedDuringTheVisit), member(1, since), member(2, since), member(3, before)],
      '2026-11-16T10:00:00+00:00',
    );

    expect(newIds()).toEqual([1, 2, 9]);
    expect(markOpened).toHaveBeenCalledTimes(1);
  });

  it('ends the visit when another list lands', () => {
    landStory([member(1, since)]);

    landAnotherList();
    expect(newIds()).toEqual([]);

    landStory([member(1, since), member(2, before)], since);
    expect(newIds()).toEqual([]);
    expect(markOpened).toHaveBeenCalledTimes(2);
  });

  it('starts a new visit after a failed load of another list', () => {
    landStory([member(1, since)]);

    failToLoad(anotherList);
    landStory([member(1, since), member(2, before)], since);

    expect(newIds()).toEqual([]);
    expect(markOpened).toHaveBeenCalledTimes(2);
  });

  it('records no visit for a followed story that fails to load', () => {
    failToLoad(story);

    expect(markOpened).not.toHaveBeenCalled();
    expect(newIds()).toEqual([]);
  });

  it('starts a new visit after the followed story failed to load', () => {
    landStory([member(1, since)]);

    failToLoad(story);
    landStory([member(1, since), member(2, before)], since);

    expect(newIds()).toEqual([]);
    expect(markOpened).toHaveBeenCalledTimes(2);
  });

  it('hands out one empty set outside a visit, so the list does not re-segment on every load', () => {
    const outside = visit.newSinceVisitIds();

    landAnotherList();

    expect(visit.newSinceVisitIds()).toBe(outside);
  });

  it('keeps the marked set while a later load marks the same rows', () => {
    landStory([member(1, since)]);
    const marked = visit.newSinceVisitIds();

    entries.set([member(1, since), member(2, before)]);

    expect(visit.newSinceVisitIds()).toBe(marked);
  });
});
