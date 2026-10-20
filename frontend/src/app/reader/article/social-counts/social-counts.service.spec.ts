import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { TokenStore } from '../../../core/auth/token.store';
import { SocialCountsDto } from '../../entry-social';
import { ReaderApi } from '../../reader-api';
import { EntriesStore } from '../../state/entries.store';
import { anEntry } from '../../../../testing/entry-testing';
import { SocialCountsService } from './social-counts.service';

describe('SocialCountsService', () => {
  let api: { socialCounts: jest.Mock; likeState: jest.Mock };
  let entries: { adoptLike: jest.Mock };
  let tokens: TokenStore;
  let service: SocialCountsService;

  const counts: SocialCountsDto = { likes: 41, reposts: 5, replies: 7, quotes: 2 };
  const post = (id = 1, isLiked = false) =>
    anEntry({ id, social: { network: 'bluesky', isLiked, canLike: true } });

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-13T12:00:00Z'));
    localStorage.clear();
    api = {
      socialCounts: jest.fn(() => of(counts)),
      likeState: jest.fn(() => of({ isLiked: false })),
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
    api.likeState.mockReturnValue(of({ isLiked: true }));

    service.load(post(1, false));

    expect(api.likeState).toHaveBeenCalledWith(1);
    expect(entries.adoptLike).toHaveBeenCalledWith(1, { from: false, to: true });
    expect(service.state(1)()).toMatchObject({ status: 'ok', counts, likedAtLoad: true });
  });

  it('counts from the entry when the like state cannot be had', () => {
    api.likeState.mockReturnValue(throwError(() => new Error('rate limited')));

    service.load(post(1, true));

    expect(entries.adoptLike).not.toHaveBeenCalled();
    expect(service.state(1)()).toMatchObject({ status: 'ok', likedAtLoad: true });
  });

  it('asks no like state for a post the reader cannot like', () => {
    service.load(
      anEntry({ id: 3, social: { network: 'mastodon', isLiked: false, canLike: false } }),
    );

    expect(api.likeState).not.toHaveBeenCalled();
    expect(service.state(3)()).toMatchObject({ status: 'ok', likedAtLoad: false });
  });

  it('asks the like state again on a reload', () => {
    service.load(post());
    service.reload(post());

    expect(api.likeState).toHaveBeenCalledTimes(2);
  });

  it('adopts no late like once another account signs in', () => {
    const late = new Subject<{ isLiked: boolean }>();
    api.likeState.mockReturnValue(late);
    service.load(post(1));

    tokens.set('account-b.jwt');
    TestBed.tick();
    late.next({ isLiked: true });
    late.complete();

    expect(entries.adoptLike).not.toHaveBeenCalled();
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
