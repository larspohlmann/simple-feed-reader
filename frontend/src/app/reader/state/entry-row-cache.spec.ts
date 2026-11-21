import { TestBed } from '@angular/core/testing';
import { Subject, of } from 'rxjs';
import { anEntry } from '../../../testing/entry-testing';
import { ReaderApi } from '../reader-api';
import { SocialPostApi } from '../social-post-api';
import { EntryDto } from '../models';
import { EntryRowCache } from './entry-row-cache';

describe('EntryRowCache', () => {
  let readerApi: { updateState: jest.Mock };
  let socialPosts: { like: jest.Mock; unlike: jest.Mock; vote: jest.Mock };
  let cache: EntryRowCache;

  const social = { network: 'lemmy' as const, isLiked: false, canLike: true, isOwn: false };
  const buildCopy = (
    id: number,
    canonicalItemId: number | null,
    overrides: Partial<EntryDto> = {},
  ) => anEntry({ id, canonicalItemId, social, myVote: 0, score: 10, ...overrides });
  const holdEntries = (...entries: EntryDto[]) => {
    const held = cache.cached(entries);
    cache.hold(held, new Set(entries.map((entry) => entry.id)));
  };
  const readShownEntry = (id: number) => cache.entry(id) as EntryDto;

  beforeEach(() => {
    readerApi = { updateState: jest.fn(() => of({})) };
    socialPosts = {
      like: jest.fn(() => of({ isLiked: true })),
      unlike: jest.fn(() => of({ isLiked: false })),
      vote: jest.fn(() => of({ myVote: 1, score: 11 })),
    };
    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderApi, useValue: readerApi },
        { provide: SocialPostApi, useValue: socialPosts },
      ],
    });
    cache = TestBed.inject(EntryRowCache);
  });

  it('setVote on a copy patches the other copy’s myVote and score', () => {
    holdEntries(buildCopy(1, 7), buildCopy(2, 7), buildCopy(3, 8));

    cache.setVote(readShownEntry(1), 1, jest.fn());

    expect(readShownEntry(2)).toEqual(expect.objectContaining({ myVote: 1, score: 11 }));
    expect(readShownEntry(3)).toEqual(expect.objectContaining({ myVote: 0, score: 10 }));
  });

  it('puts the vote back on every copy when it fails', () => {
    socialPosts.vote.mockReturnValue(new Subject());
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));
    const failing = new Subject<never>();
    socialPosts.vote.mockReturnValue(failing);

    cache.setVote(readShownEntry(1), 1, jest.fn());
    failing.error(new Error('no'));

    expect(readShownEntry(2)).toEqual(expect.objectContaining({ myVote: 0, score: 10 }));
  });

  it('setLiked on a copy patches the other copy and keeps its own network facts', () => {
    holdEntries(buildCopy(1, 7), buildCopy(2, 7, { social: { ...social, isOwn: true } }));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(2).social).toEqual({ ...social, isLiked: true, isOwn: true });
  });

  it('setLiked steps the like count on every copy', () => {
    holdEntries(buildCopy(1, 7, { likeCount: 4 }), buildCopy(2, 7, { likeCount: 4 }));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBe(5);
    expect(readShownEntry(2).likeCount).toBe(5);
  });

  it('an unlike steps the like count down, never below zero', () => {
    holdEntries(buildCopy(1, 7, { likeCount: 0, social: { ...social, isLiked: true } }));

    cache.setLiked(readShownEntry(1), false, jest.fn());

    expect(readShownEntry(1).likeCount).toBe(0);
  });

  it('a like leaves an unknown like count unknown', () => {
    holdEntries(buildCopy(1, 7));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBeNull();
  });

  it('puts the like count back when the like fails', () => {
    const failing = new Subject<never>();
    socialPosts.like.mockReturnValue(failing);
    holdEntries(buildCopy(1, 7, { likeCount: 4 }));

    cache.setLiked(readShownEntry(1), true, jest.fn());
    failing.error(new Error('no'));

    expect(readShownEntry(1).likeCount).toBe(4);
  });

  it('a vote on the wire for one copy holds back a vote on the other', () => {
    socialPosts.vote.mockReturnValue(new Subject());
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));

    cache.setVote(readShownEntry(1), 1, jest.fn());
    cache.setVote(readShownEntry(2), -1, jest.fn());

    expect(socialPosts.vote).toHaveBeenCalledTimes(1);
  });

  it('saveState read on a copy patches the other copy and sends one request', () => {
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));

    cache.saveState(1, { isViewed: true }, {});

    expect(readShownEntry(2)).toEqual(expect.objectContaining({ isViewed: true, isHidden: true }));
    expect(readerApi.updateState).toHaveBeenCalledTimes(1);
  });

  it('saveState leaves the other copy’s favourite alone', () => {
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));

    cache.saveState(1, { isFavorite: true }, {});

    expect(readShownEntry(1).isFavorite).toBe(true);
    expect(readShownEntry(2).isFavorite).toBe(false);
  });

  it('markHiddenLocally restyles every copy', () => {
    holdEntries(buildCopy(1, 7), buildCopy(2, 7), buildCopy(3, 8));

    cache.markHiddenLocally([1]);

    expect([
      readShownEntry(1).isHidden,
      readShownEntry(2).isHidden,
      readShownEntry(3).isHidden,
    ]).toEqual([true, true, false]);
  });

  it('an article’s patch touches only itself', () => {
    holdEntries(buildCopy(1, null), buildCopy(2, null));

    cache.saveState(1, { isViewed: true }, {});
    cache.setVote(readShownEntry(1), 1, jest.fn());

    expect(readShownEntry(2)).toEqual(
      expect.objectContaining({ isViewed: false, isHidden: false, myVote: 0, score: 10 }),
    );
  });
});
