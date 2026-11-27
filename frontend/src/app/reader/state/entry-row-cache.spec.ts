import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
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
      like: jest.fn(() => of({ isLiked: true, likeCount: null })),
      unlike: jest.fn(() => of({ isLiked: false, likeCount: null })),
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

  it('leaves the like count alone until the network answers', () => {
    socialPosts.like.mockReturnValue(new Subject());
    holdEntries(buildCopy(1, 7, { likeCount: 4 }), buildCopy(2, 7, { likeCount: 4 }));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBe(4);
    expect(readShownEntry(2).likeCount).toBe(4);
  });
  it('takes the like count the network answers on every copy', () => {
    socialPosts.like.mockReturnValue(of({ isLiked: true, likeCount: 12 }));
    holdEntries(buildCopy(1, 7, { likeCount: 4 }), buildCopy(2, 7, { likeCount: 4 }));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBe(12);
    expect(readShownEntry(2).likeCount).toBe(12);
  });

  it('keeps the stored like count when the network answers none', () => {
    holdEntries(buildCopy(1, 7, { likeCount: 4 }));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBe(4);
  });
  it('takes the score the network answers on every copy', () => {
    socialPosts.vote.mockReturnValue(of({ myVote: 1, score: 40 }));
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));

    cache.setVote(readShownEntry(1), 1, jest.fn());

    expect(readShownEntry(1).score).toBe(40);
    expect(readShownEntry(2).score).toBe(40);
  });

  it('settles a like once its answer lands', () => {
    const answer = new Subject<{ isLiked: boolean; likeCount: number | null }>();
    socialPosts.like.mockReturnValue(answer);
    holdEntries(buildCopy(1, 7));
    const settled = jest.fn();

    cache.setLiked(readShownEntry(1), true, jest.fn()).subscribe({ complete: settled });

    expect(settled).not.toHaveBeenCalled();
    answer.next({ isLiked: true, likeCount: 5 });
    answer.complete();
    expect(settled).toHaveBeenCalled();
  });

  it('settles a like that fails', () => {
    const failing = new Subject<never>();
    socialPosts.like.mockReturnValue(failing);
    holdEntries(buildCopy(1, 7));
    const settled = jest.fn();

    cache.setLiked(readShownEntry(1), true, jest.fn()).subscribe({ complete: settled });
    failing.error(new Error('no'));

    expect(settled).toHaveBeenCalled();
  });

  it('a like leaves an unknown like count unknown', () => {
    holdEntries(buildCopy(1, 7));

    cache.setLiked(readShownEntry(1), true, jest.fn());

    expect(readShownEntry(1).likeCount).toBeNull();
  });

  it('settles a vote once its answer lands, and a dropped vote at once', () => {
    const answer = new Subject<{ myVote: 1; score: number }>();
    socialPosts.vote.mockReturnValue(answer);
    holdEntries(buildCopy(1, 7));
    const settled = jest.fn();
    const dropped = jest.fn();

    cache.setVote(readShownEntry(1), 1, jest.fn()).subscribe({ complete: settled });
    cache.setVote(readShownEntry(1), -1, jest.fn()).subscribe({ complete: dropped });

    expect(settled).not.toHaveBeenCalled();
    expect(dropped).toHaveBeenCalled();
    answer.next({ myVote: 1, score: 11 });
    answer.complete();
    expect(settled).toHaveBeenCalled();
  });

  it('leaves the score alone until the vote answers', () => {
    socialPosts.vote.mockReturnValue(new Subject());
    holdEntries(buildCopy(1, 7), buildCopy(2, 7));

    cache.setVote(readShownEntry(1), 1, jest.fn());

    expect(readShownEntry(1)).toEqual(expect.objectContaining({ myVote: 1, score: 10 }));
    expect(readShownEntry(2)).toEqual(expect.objectContaining({ myVote: 1, score: 10 }));
  });

  it('restores the vote when the vote fails', () => {
    const failing = new Subject<never>();
    socialPosts.vote.mockReturnValue(failing);
    holdEntries(buildCopy(1, 7));

    cache.setVote(readShownEntry(1), 1, jest.fn());
    failing.error(new Error('no'));

    expect(readShownEntry(1)).toEqual(expect.objectContaining({ myVote: 0, score: 10 }));
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

  describe('label changes (#2581)', () => {
    const work = { id: 3, name: 'Work' };
    const home = { id: 4, name: 'Home' };
    const failWith500 = (request: Subject<never>) =>
      request.error(new HttpErrorResponse({ status: 500 }));

    it('shows an attached label at once, on the entry alone and not on its copies', () => {
      holdEntries(buildCopy(1, 7), buildCopy(2, 7));

      cache.attachLabel(1, work, new Subject()).subscribe();

      expect(readShownEntry(1).labels).toEqual([work]);
      expect(readShownEntry(2).labels).toEqual([]);
    });

    it('takes a failed attach back off the row', () => {
      holdEntries(buildCopy(1, null, { labels: [work] }));
      const failing = new Subject<never>();

      cache.attachLabel(1, home, failing).subscribe({ error: jest.fn() });
      failWith500(failing);

      expect(readShownEntry(1).labels).toEqual([work]);
    });

    it('undoes only the failed change when two changes on one row are out', () => {
      holdEntries(buildCopy(1, null, { labels: [work] }));
      const attaching = new Subject<never>();

      cache.attachLabel(1, home, attaching).subscribe({ error: jest.fn() });
      cache.detachLabel(1, work, new Subject()).subscribe();
      failWith500(attaching);

      expect(readShownEntry(1).labels).toEqual([]);
    });

    it('puts a failed detach back beside a change made since', () => {
      holdEntries(buildCopy(1, null, { labels: [work] }));
      const detaching = new Subject<never>();

      cache.detachLabel(1, work, detaching).subscribe({ error: jest.fn() });
      cache.attachLabel(1, home, new Subject()).subscribe();
      failWith500(detaching);

      expect(readShownEntry(1).labels).toEqual([home, work]);
    });

    it('keeps every change on the wire over a reload that lands meanwhile', () => {
      holdEntries(buildCopy(1, null, { labels: [work] }));

      cache.attachLabel(1, home, new Subject()).subscribe();
      cache.detachLabel(1, work, new Subject()).subscribe();
      holdEntries(buildCopy(1, null, { labels: [work] }));

      expect(readShownEntry(1).labels).toEqual([home]);
    });

    it('lets a reload show the server once a change has settled', () => {
      holdEntries(buildCopy(1, null));

      cache.attachLabel(1, work, of(undefined)).subscribe();
      holdEntries(buildCopy(1, null));

      expect(readShownEntry(1).labels).toEqual([]);
    });
  });

  describe('a label renamed or deleted elsewhere (#2581)', () => {
    const work = { id: 3, name: 'Work' };
    const home = { id: 4, name: 'Home' };

    it('shows the new name on every held row, in name order', () => {
      holdEntries(buildCopy(1, null, { labels: [home, work] }), buildCopy(2, null));

      cache.renameLabelLocally(3, 'Errands');

      expect(readShownEntry(1).labels).toEqual([{ id: 3, name: 'Errands' }, home]);
      expect(readShownEntry(2).labels).toEqual([]);
    });

    it('drops the pill from every held row', () => {
      holdEntries(
        buildCopy(1, null, { labels: [home, work] }),
        buildCopy(2, null, { labels: [work] }),
      );

      cache.dropLabelLocally(3);

      expect(readShownEntry(1).labels).toEqual([home]);
      expect(readShownEntry(2).labels).toEqual([]);
    });
  });
});
