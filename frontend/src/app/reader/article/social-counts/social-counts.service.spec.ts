import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { TokenStore } from '../../../core/auth/token.store';
import { SocialCountsDto, SocialViewerDto } from '../../entry-social';
import { HttpErrorResponse } from '@angular/common/http';
import { ReaderApi } from '../../reader-api';
import { EntriesStore } from '../../state/entries.store';
import { anEntry } from '../../../../testing/entry-testing';
import { SocialCountsService } from './social-counts.service';

describe('SocialCountsService', () => {
  let api: {
    socialCounts: jest.Mock;
    socialViewer: jest.Mock;
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
    ...overrides,
  });
  const post = (id = 1, isLiked = false) =>
    anEntry({ id, social: { network: 'bluesky', isLiked, canLike: true } });

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-13T12:00:00Z'));
    localStorage.clear();
    api = {
      socialCounts: jest.fn(() => of(counts)),
      socialViewer: jest.fn(() => of(viewer())),
      repost: jest.fn(() => of({ isReposted: true })),
      unrepost: jest.fn(() => of({ isReposted: false })),
    };
    entries = { adoptLike: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderApi, useValue: api },
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
    expect(api.socialCounts).not.toHaveBeenCalled();
  });

  it('loads once and serves the counts for two minutes', () => {
    service.load(post());
    service.load(post());

    expect(api.socialCounts).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({
      status: 'ok',
      counts,
      likedAtLoad: false,
      repost: { isReposted: false, canRepost: true },
      loadedAt: Date.parse('2026-10-13T12:00:00Z'),
    });
  });

  it('reloads the counts with a refresh', () => {
    service.load(post());
    service.reload(post());

    expect(api.socialCounts).toHaveBeenNthCalledWith(1, 1);
    expect(api.socialCounts).toHaveBeenNthCalledWith(2, 1, true);
  });

  it('ignores a reload while counts are loading', () => {
    api.socialCounts.mockReturnValue(new Subject<SocialCountsDto>());
    service.load(post());
    service.reload(post());

    expect(api.socialCounts).toHaveBeenCalledTimes(1);
  });

  it('asks again once two minutes have passed', () => {
    service.load(post());
    jest.setSystemTime(new Date('2026-10-13T12:02:00Z'));
    service.load(post());

    expect(api.socialCounts).toHaveBeenCalledTimes(2);
  });

  it('asks the network for the like alongside the counts and adopts it', () => {
    api.socialViewer.mockReturnValue(of(viewer({ isLiked: true })));

    service.load(post(1, false));

    expect(api.socialViewer).toHaveBeenCalledWith(1);
    expect(entries.adoptLike).toHaveBeenCalledWith(1, { from: false, to: true });
    expect(service.state(1)()).toMatchObject({ status: 'ok', counts, likedAtLoad: true });
  });

  it('counts from the entry when the like state cannot be had', () => {
    api.socialViewer.mockReturnValue(throwError(() => new Error('rate limited')));

    service.load(post(1, true));

    expect(entries.adoptLike).not.toHaveBeenCalled();
    expect(service.state(1)()).toMatchObject({ status: 'ok', likedAtLoad: true, repost: null });
  });

  it('asks no like state for a post the reader cannot like', () => {
    service.load(
      anEntry({ id: 3, social: { network: 'mastodon', isLiked: false, canLike: false } }),
    );

    expect(api.socialViewer).not.toHaveBeenCalled();
    expect(service.state(3)()).toMatchObject({ status: 'ok', likedAtLoad: false, repost: null });
  });

  it('asks the like state again on a reload', () => {
    service.load(post());
    service.reload(post());

    expect(api.socialViewer).toHaveBeenCalledTimes(2);
  });

  it('adopts no late like once another account signs in', () => {
    const late = new Subject<SocialViewerDto>();
    api.socialViewer.mockReturnValue(late);
    service.load(post(1));

    tokens.set('account-b.jwt');
    TestBed.tick();
    late.next(viewer({ isLiked: true }));
    late.complete();

    expect(entries.adoptLike).not.toHaveBeenCalled();
  });

  it('keeps the repost the network reports and whether it allows one', () => {
    api.socialViewer.mockReturnValue(of(viewer({ isReposted: true, canRepost: false })));

    service.load(post());

    expect(service.state(1)()).toMatchObject({ repost: { isReposted: true, canRepost: false } });
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
        repost: { isReposted: true },
      });

      service.toggleRepost(post(), onError);
      expect(api.unrepost).toHaveBeenCalledWith(1);
      expect(service.state(1)()).toMatchObject({
        counts: { reposts: 5 },
        repost: { isReposted: false },
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
        repost: { isReposted: false },
      });
      expect(onError).toHaveBeenCalledWith(expect.objectContaining({ type: 'social_post_refused' }));
    });

    it('ignores a toggle while the last one is on the wire', () => {
      api.repost.mockReturnValue(new Subject());
      service.load(post());

      service.toggleRepost(post(), onError);
      service.toggleRepost(post(), onError);

      expect(api.repost).toHaveBeenCalledTimes(1);
      expect(api.unrepost).not.toHaveBeenCalled();
    });

    it('does nothing on a post the network keeps from reposts', () => {
      api.socialViewer.mockReturnValue(of(viewer({ canRepost: false })));
      service.load(post());

      service.toggleRepost(post(), onError);

      expect(api.repost).not.toHaveBeenCalled();
    });

    it('does nothing before the viewer state is known', () => {
      api.socialViewer.mockReturnValue(throwError(() => new Error('offline')));
      service.load(post());

      service.toggleRepost(post(), onError);

      expect(api.repost).not.toHaveBeenCalled();
    });
  });

  it('maps any error to failed', () => {
    api.socialCounts.mockReturnValue(throwError(() => new Error('offline')));
    service.load(post());

    expect(service.state(1)()).toEqual({
      status: 'failed',
      failedAt: Date.parse('2026-10-13T12:00:00Z'),
    });
  });

  it('retries a failed load only once a minute has passed', () => {
    api.socialCounts.mockReturnValue(throwError(() => new Error('offline')));
    service.load(post());
    jest.setSystemTime(new Date('2026-10-13T12:00:59Z'));
    service.load(post());
    expect(api.socialCounts).toHaveBeenCalledTimes(1);

    jest.setSystemTime(new Date('2026-10-13T12:01:00Z'));
    service.load(post());
    expect(api.socialCounts).toHaveBeenCalledTimes(2);
  });

  it('ignores a second load while one is in flight', () => {
    api.socialCounts.mockReturnValue(new Subject<SocialCountsDto>());
    service.load(post());
    service.load(post());

    expect(api.socialCounts).toHaveBeenCalledTimes(1);
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
    api.socialCounts.mockReturnValue(late);
    service.load(post(2));
    const held = service.state(2);

    tokens.set('account-b.jwt');
    TestBed.tick();
    settle(late);

    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(held()).toEqual({ status: 'loading' });
  });
});
