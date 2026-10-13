import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { TokenStore } from '../../../core/auth/token.store';
import { SocialCountsDto } from '../../entry-social';
import { ReaderApi } from '../../reader-api';
import { anEntry } from '../../../../testing/entry-testing';
import { SocialCountsService } from './social-counts.service';

describe('SocialCountsService', () => {
  let api: { socialCounts: jest.Mock };
  let tokens: TokenStore;
  let service: SocialCountsService;

  const counts: SocialCountsDto = { likes: 41, reposts: 5, replies: 7, quotes: 2 };
  const post = (id = 1, isLiked = false) =>
    anEntry({ id, social: { network: 'bluesky', isLiked, canLike: true } });

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-13T12:00:00Z'));
    localStorage.clear();
    api = { socialCounts: jest.fn(() => of(counts)) };
    TestBed.configureTestingModule({ providers: [{ provide: ReaderApi, useValue: api }] });
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

  it('asks again once two minutes have passed', () => {
    service.load(post());
    jest.setSystemTime(new Date('2026-10-13T12:02:00Z'));
    service.load(post());

    expect(api.socialCounts).toHaveBeenCalledTimes(2);
  });

  it('remembers whether the post was liked when its counts were asked for', () => {
    service.load(post(1, true));

    expect(service.state(1)()).toMatchObject({ status: 'ok', likedAtLoad: true });
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
    ['answer', (late: Subject<SocialCountsDto>) => late.next(counts)],
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
