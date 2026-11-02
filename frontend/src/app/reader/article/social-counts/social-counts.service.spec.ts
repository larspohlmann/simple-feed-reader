import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { TokenStore } from '../../../core/auth/token.store';
import { SocialCountsDto, SocialViewerDto } from '../../entry-social';
import { HttpErrorResponse } from '@angular/common/http';
import { SocialPostApi } from '../../social-post-api';
import { EntriesStore } from '../../state/entries.store';
import { anEntry } from '../../../../testing/entry-testing';
import { SocialCountsService } from './social-counts.service';
import { RemovedPost, RemovedPosts } from '../../removed-posts.service';

describe('SocialCountsService', () => {
  let api: {
    counts: jest.Mock;
    viewer: jest.Mock;
    repost: jest.Mock;
    unrepost: jest.Mock;
  };
  let entries: { adoptLike: jest.Mock };
  let tokens: TokenStore;
  let service: SocialCountsService;

  const counts: SocialCountsDto = { likes: 41, reposts: 5, replies: 7, quotes: 2 };
  const viewer = (overrides: Partial<SocialViewerDto> = {}): SocialViewerDto => ({
    isLiked: false,
    isReposted: false,
    canRepost: true,
    canReply: true,
    canQuote: true,
    ...overrides,
  });
  const post = (id = 1, isLiked = false) =>
    anEntry({ id, social: { network: 'bluesky', isLiked, canLike: true, isOwn: false } });

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-13T12:00:00Z'));
    localStorage.clear();
    api = {
      counts: jest.fn(() => of(counts)),
      viewer: jest.fn(() => of(viewer())),
      repost: jest.fn(() => of({ isReposted: true })),
      unrepost: jest.fn(() => of({ isReposted: false })),
    };
    entries = { adoptLike: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: SocialPostApi, useValue: api },
        { provide: EntriesStore, useValue: entries },
      ],
    });
    tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
    service = TestBed.inject(SocialCountsService);
  });

  afterEach(() => jest.useRealTimers());

  it('starts idle and asks nothing until a row is seen', () => {
    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(api.counts).not.toHaveBeenCalled();
  });

  it('loads once and serves the counts for two minutes', () => {
    service.load(post());
    service.load(post());

    expect(api.counts).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({
      status: 'ok',
      counts,
      likedAtLoad: false,
      actions: { isReposted: false, canRepost: true, canReply: true, canQuote: true },
      loadedAt: Date.parse('2026-10-13T12:00:00Z'),
    });
  });

  it('reloads the counts with a refresh', () => {
    service.load(post());
    service.reload(post());

    expect(api.counts).toHaveBeenNthCalledWith(1, 1);
    expect(api.counts).toHaveBeenNthCalledWith(2, 1, true);
  });

  it('ignores a reload while counts are loading', () => {
    api.counts.mockReturnValue(new Subject<SocialCountsDto>());
    service.load(post());
    service.reload(post());

    expect(api.counts).toHaveBeenCalledTimes(1);
  });

  it('asks again once two minutes have passed', () => {
    service.load(post());
    jest.setSystemTime(new Date('2026-10-13T12:02:00Z'));
    service.load(post());

    expect(api.counts).toHaveBeenCalledTimes(2);
  });

  it('asks the network for the like alongside the counts and adopts it', () => {
    api.viewer.mockReturnValue(of(viewer({ isLiked: true })));

    service.load(post(1, false));

    expect(api.viewer).toHaveBeenCalledWith(1);
    expect(entries.adoptLike).toHaveBeenCalledWith(1, { from: false, to: true });
    expect(service.state(1)()).toMatchObject({ status: 'ok', counts, likedAtLoad: true });
  });

  it('counts from the entry when the like state cannot be had', () => {
    api.viewer.mockReturnValue(throwError(() => new Error('rate limited')));

    service.load(post(1, true));

    expect(entries.adoptLike).not.toHaveBeenCalled();
    expect(service.state(1)()).toMatchObject({ status: 'ok', likedAtLoad: true, actions: null });
  });

  it('asks no like state for a post the reader cannot like', () => {
    service.load(
      anEntry({
        id: 3,
        social: { network: 'mastodon', isLiked: false, canLike: false, isOwn: false },
      }),
    );

    expect(api.viewer).not.toHaveBeenCalled();
    expect(service.state(3)()).toMatchObject({ status: 'ok', likedAtLoad: false, actions: null });
  });

  it('asks the like state again on a reload', () => {
    service.load(post());
    service.reload(post());

    expect(api.viewer).toHaveBeenCalledTimes(2);
  });

  it('adopts no late like once another account signs in', () => {
    const late = new Subject<SocialViewerDto>();
    api.viewer.mockReturnValue(late);
    service.load(post(1));

    tokens.set('account-b.jwt');
    TestBed.tick();
    late.next(viewer({ isLiked: true }));
    late.complete();

    expect(entries.adoptLike).not.toHaveBeenCalled();
  });

  it('keeps the repost the network reports and whether it allows one', () => {
    api.viewer.mockReturnValue(of(viewer({ isReposted: true, canRepost: false })));

    service.load(post());

    expect(service.state(1)()).toMatchObject({ actions: { isReposted: true, canRepost: false } });
  });

  describe('toggleRepost', () => {
    const onError = jest.fn();

    beforeEach(() => onError.mockReset());

    it('reposts at once, the count following, and undoes it on the next toggle', () => {
      service.load(post());

      service.toggleRepost(post(), onError);
      expect(api.repost).toHaveBeenCalledWith(1);
      expect(service.state(1)()).toMatchObject({
        counts: { reposts: 6 },
        actions: { isReposted: true },
      });

      service.toggleRepost(post(), onError);
      expect(api.unrepost).toHaveBeenCalledWith(1);
      expect(service.state(1)()).toMatchObject({
        counts: { reposts: 5 },
        actions: { isReposted: false },
      });
    });

    it('puts the repost back and reports the problem when the network refuses', () => {
      api.repost.mockReturnValue(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 409,
              error: { type: 'social_post_refused', title: 'Refused', detail: 'No boosts' },
            }),
        ),
      );
      service.load(post());

      service.toggleRepost(post(), onError);

      expect(service.state(1)()).toMatchObject({
        counts: { reposts: 5 },
        actions: { isReposted: false },
      });
      expect(onError).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'social_post_refused' }),
      );
    });

    it('ignores a toggle while the last one is on the wire', () => {
      api.repost.mockReturnValue(new Subject());
      service.load(post());

      service.toggleRepost(post(), onError);
      service.toggleRepost(post(), onError);

      expect(api.repost).toHaveBeenCalledTimes(1);
      expect(api.unrepost).not.toHaveBeenCalled();
    });

    it('holds a reload back until the repost settles', () => {
      api.repost.mockReturnValue(new Subject());
      service.load(post());
      service.toggleRepost(post(), onError);

      service.reload(post());

      expect(api.counts).toHaveBeenCalledTimes(1);
    });

    it('does nothing on a post the network keeps from reposts', () => {
      api.viewer.mockReturnValue(of(viewer({ canRepost: false })));
      service.load(post());

      service.toggleRepost(post(), onError);

      expect(api.repost).not.toHaveBeenCalled();
    });

    it('does nothing before the viewer state is known', () => {
      api.viewer.mockReturnValue(throwError(() => new Error('offline')));
      service.load(post());

      service.toggleRepost(post(), onError);

      expect(api.repost).not.toHaveBeenCalled();
    });
  });

  describe('a post deleted on its network (#1975)', () => {
    const problem = (type: string, status: number) =>
      throwError(() => new HttpErrorResponse({ status, error: { type, title: 'x', status } }));
    let announced: RemovedPost[];

    beforeEach(() => {
      announced = [];
      TestBed.inject(RemovedPosts).removed$.subscribe((removed) => announced.push(removed));
    });

    it('announces the removal the counts report, for a reader without an account there', () => {
      api.counts.mockReturnValue(problem('social_post_removed', 410));

      service.load(
        anEntry({
          id: 1,
          social: { network: 'mastodon', isLiked: false, canLike: false, isOwn: false },
        }),
      );

      expect(announced).toEqual([{ id: 1, network: 'mastodon' }]);
      expect(api.viewer).not.toHaveBeenCalled();
      expect(service.state(1)()).toMatchObject({ status: 'failed' });
    });

    it('announces the removal the viewer reports', () => {
      api.viewer.mockReturnValue(problem('social_post_removed', 410));

      service.load(post(1));

      expect(announced).toEqual([{ id: 1, network: 'bluesky' }]);
      expect(service.state(1)()).toMatchObject({ status: 'failed' });
    });

    it('announces nothing once another account has signed in', () => {
      const late = new Subject<SocialCountsDto>();
      api.counts.mockReturnValue(late);
      service.load(post(1));

      tokens.set('account-b.jwt');
      TestBed.tick();
      late.error(new HttpErrorResponse({ status: 410, error: { type: 'social_post_removed' } }));

      expect(announced).toEqual([]);
    });

    it('announces nothing for a post the reader merely cannot find', () => {
      api.viewer.mockReturnValue(problem('social_post_not_found', 404));

      service.load(post(1));

      expect(announced).toEqual([]);
      expect(service.state(1)()).toMatchObject({ status: 'ok', actions: null });
    });
  });

  it('maps any error to failed', () => {
    api.counts.mockReturnValue(throwError(() => new Error('offline')));
    service.load(post());

    expect(service.state(1)()).toEqual({
      status: 'failed',
      failedAt: Date.parse('2026-10-13T12:00:00Z'),
    });
  });

  it('retries a failed load only once a minute has passed', () => {
    api.counts.mockReturnValue(throwError(() => new Error('offline')));
    service.load(post());
    jest.setSystemTime(new Date('2026-10-13T12:00:59Z'));
    service.load(post());
    expect(api.counts).toHaveBeenCalledTimes(1);

    jest.setSystemTime(new Date('2026-10-13T12:01:00Z'));
    service.load(post());
    expect(api.counts).toHaveBeenCalledTimes(2);
  });

  it('ignores a second load while one is in flight', () => {
    api.counts.mockReturnValue(new Subject<SocialCountsDto>());
    service.load(post());
    service.load(post());

    expect(api.counts).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'loading' });
  });

  it.each([
    [
      'answer',
      (late: Subject<SocialCountsDto>) => {
        late.next(counts);
        late.complete();
      },
    ],
    ['error', (late: Subject<SocialCountsDto>) => late.error(new Error('offline'))],
  ])('forgets every count, and drops a late %s, once another account signs in', (_, settle) => {
    const late = new Subject<SocialCountsDto>();
    service.load(post(1));
    api.counts.mockReturnValue(late);
    service.load(post(2));
    const held = service.state(2);

    tokens.set('account-b.jwt');
    TestBed.tick();
    settle(late);

    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(held()).toEqual({ status: 'loading' });
  });

  it('steps the replies count for a reply the reader published or deleted, never below zero', () => {
    service.load(post());
    service.stepReplies(1, true);
    expect(service.state(1)()).toMatchObject({ counts: { replies: 8 } });

    service.stepReplies(1, false);
    service.stepReplies(1, false);
    expect(service.state(1)()).toMatchObject({ counts: { replies: 6 } });
    service.stepReplies(2, true);
    expect(service.state(2)()).toEqual({ status: 'idle' });
  });

  it('steps the quotes count for a quote the reader published, where the network counts quotes', () => {
    service.load(post(1));
    service.stepQuotes(1);
    expect(service.state(1)()).toMatchObject({ counts: { quotes: 3 } });

    api.counts.mockReturnValue(of({ ...counts, quotes: null }));
    service.load(post(2));
    service.stepQuotes(2);
    expect(service.state(2)()).toMatchObject({ counts: { quotes: null } });
  });
});
