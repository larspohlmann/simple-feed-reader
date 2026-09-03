import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { ReaderContentService } from './reader-content.service';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderApi } from './reader-api';
import { ReaderArticle, ReaderContent } from './models';

const ARTICLE: ReaderArticle = {
  status: 'ok',
  url: 'https://x/1',
  title: 'T',
  byline: null,
  siteName: null,
  contentHtml: '<p>b</p>',
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '2026-07-23T00:00:00Z',
};

describe('ReaderContentService', () => {
  let apiGet: jest.Mock;
  let cacheGet: jest.Mock;
  let cachePut: jest.Mock;
  let cacheDelete: jest.Mock;

  beforeEach(() => {
    apiGet = jest.fn();
    cacheGet = jest.fn();
    cachePut = jest.fn().mockResolvedValue(undefined);
    cacheDelete = jest.fn().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        ReaderContentService,
        { provide: ReaderApi, useValue: { readerContent: apiGet } },
        {
          provide: ReaderCacheService,
          useValue: { get: cacheGet, put: cachePut, delete: cacheDelete },
        },
      ],
    });
  });

  it('serves a cache hit without calling the API', async () => {
    cacheGet.mockResolvedValue(ARTICLE);
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.load(1));
    expect(result).toEqual(ARTICLE);
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('calls the API when the cache does not answer in time', async () => {
    jest.useFakeTimers();
    cacheGet.mockReturnValue(new Promise(() => undefined)); // stuck behind a blocked upgrade
    apiGet.mockReturnValue(of(ARTICLE));
    const svc = TestBed.inject(ReaderContentService);
    const loaded = firstValueFrom(svc.load(1));
    // Well under the 30 s the view allows before it gives up on the article.
    await jest.advanceTimersByTimeAsync(5_000);
    await expect(loaded).resolves.toEqual(ARTICLE);
    jest.useRealTimers();
  });

  it('fetches and caches on a miss', async () => {
    cacheGet.mockResolvedValue(null);
    apiGet.mockReturnValue(of(ARTICLE));
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.load(1));
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('does not cache a failure', async () => {
    cacheGet.mockResolvedValue(null);
    const failure: ReaderContent = {
      status: 'failed',
      url: null,
      reason: 'fetch',
      originalHero: null,
    };
    apiGet.mockReturnValue(of(failure));
    const svc = TestBed.inject(ReaderContentService);
    await firstValueFrom(svc.load(1));
    expect(cachePut).not.toHaveBeenCalled();
  });

  it('reload deletes the cache then fetches from the API even on a prior hit', async () => {
    apiGet.mockReturnValue(of(ARTICLE));
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(apiGet).toHaveBeenCalledWith(1);
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('reload does not cache a failed refetch', async () => {
    const failure: ReaderContent = {
      status: 'failed',
      url: null,
      reason: 'fetch',
      originalHero: null,
    };
    apiGet.mockReturnValue(of(failure));
    const svc = TestBed.inject(ReaderContentService);
    await firstValueFrom(svc.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(cachePut).not.toHaveBeenCalled();
  });
});
