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
  const copy = (id: number, canonicalItemId: number | null, overrides: Partial<EntryDto> = {}) =>
    anEntry({ id, canonicalItemId, social, myVote: 0, score: 10, ...overrides });
  const hold = (...entries: EntryDto[]) => {
    const held = cache.cached(entries);
    cache.hold(held, new Set(entries.map((entry) => entry.id)));
  };
  const shown = (id: number) => cache.entry(id) as EntryDto;

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
    hold(copy(1, 7), copy(2, 7), copy(3, 8));

    cache.setVote(shown(1), 1, jest.fn());

    expect(shown(2)).toEqual(expect.objectContaining({ myVote: 1, score: 11 }));
    expect(shown(3)).toEqual(expect.objectContaining({ myVote: 0, score: 10 }));
  });

  it('puts the vote back on every copy when it fails', () => {
    socialPosts.vote.mockReturnValue(new Subject());
    hold(copy(1, 7), copy(2, 7));
    const failing = new Subject<never>();
    socialPosts.vote.mockReturnValue(failing);

    cache.setVote(shown(1), 1, jest.fn());
    failing.error(new Error('no'));

    expect(shown(2)).toEqual(expect.objectContaining({ myVote: 0, score: 10 }));
  });

  it('setLiked on a copy patches the other copy and keeps its own network facts', () => {
    hold(copy(1, 7), copy(2, 7, { social: { ...social, isOwn: true } }));

    cache.setLiked(shown(1), true, jest.fn());

    expect(shown(2).social).toEqual({ ...social, isLiked: true, isOwn: true });
  });

  it('a vote on the wire for one copy holds back a vote on the other', () => {
    socialPosts.vote.mockReturnValue(new Subject());
    hold(copy(1, 7), copy(2, 7));

    cache.setVote(shown(1), 1, jest.fn());
    cache.setVote(shown(2), -1, jest.fn());

    expect(socialPosts.vote).toHaveBeenCalledTimes(1);
  });

  it('saveState read on a copy patches the other copy and sends one request', () => {
    hold(copy(1, 7), copy(2, 7));

    cache.saveState(1, { isViewed: true }, {});

    expect(shown(2)).toEqual(expect.objectContaining({ isViewed: true, isHidden: true }));
    expect(readerApi.updateState).toHaveBeenCalledTimes(1);
  });

  it('saveState leaves the other copy’s favourite alone', () => {
    hold(copy(1, 7), copy(2, 7));

    cache.saveState(1, { isFavorite: true }, {});

    expect(shown(1).isFavorite).toBe(true);
    expect(shown(2).isFavorite).toBe(false);
  });

  it('markHiddenLocally restyles every copy', () => {
    hold(copy(1, 7), copy(2, 7), copy(3, 8));

    cache.markHiddenLocally([1]);

    expect([shown(1).isHidden, shown(2).isHidden, shown(3).isHidden]).toEqual([true, true, false]);
  });

  it('an article’s patch touches only itself', () => {
    hold(copy(1, null), copy(2, null));

    cache.saveState(1, { isViewed: true }, {});
    cache.setVote(shown(1), 1, jest.fn());

    expect(shown(2)).toEqual(
      expect.objectContaining({ isViewed: false, isHidden: false, myVote: 0, score: 10 }),
    );
  });
});
