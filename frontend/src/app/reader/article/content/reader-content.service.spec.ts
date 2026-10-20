import { TestBed } from '@angular/core/testing';
import { NEVER, TimeoutError, firstValueFrom, of } from 'rxjs';
import { ReaderContentService } from './reader-content.service';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderApi } from '../../reader-api';
import { A_READER_TOKEN } from '../../../../testing/entry-testing';
import { aReaderArticle, aReaderFailure } from '../../../../testing/reader-content-testing';

const ARTICLE = aReaderArticle({ url: 'https://x/1' });

describe('ReaderContentService', () => {
  let apiGet: jest.Mock;
  let signedApiGet: jest.Mock;
  let cacheGet: jest.Mock;
  let cachePut: jest.Mock;
  let cacheDelete: jest.Mock;

  beforeEach(() => {
    apiGet = jest.fn();
    signedApiGet = jest.fn();
    cacheGet = jest.fn();
    cachePut = jest.fn().mockResolvedValue(undefined);
    cacheDelete = jest.fn().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        ReaderContentService,
        {
          provide: ReaderApi,
          useValue: { readerContent: apiGet, signedReaderContent: signedApiGet },
        },
        {
          provide: ReaderCacheService,
          useValue: { get: cacheGet, put: cachePut, delete: cacheDelete },
        },
      ],
    });
  });

  it('serves a cache hit without calling the API', async () => {
    cacheGet.mockResolvedValue(ARTICLE);
    const service = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(service.load(1));
    expect(result).toEqual(ARTICLE);
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('calls the API when the cache does not answer in time', async () => {
    jest.useFakeTimers();
    cacheGet.mockReturnValue(new Promise(() => undefined)); // stuck behind a blocked upgrade
    apiGet.mockReturnValue(of(ARTICLE));
    const service = TestBed.inject(ReaderContentService);
    const loaded = firstValueFrom(service.load(1));
    // Well under the 30 s load timeout.
    await jest.advanceTimersByTimeAsync(5_000);
    await expect(loaded).resolves.toEqual(ARTICLE);
    jest.useRealTimers();
  });

  it('gives up on a fetch that never answers', async () => {
    jest.useFakeTimers();
    cacheGet.mockResolvedValue(null);
    apiGet.mockReturnValue(NEVER);
    const loaded = firstValueFrom(TestBed.inject(ReaderContentService).load(1));
    const rejection = expect(loaded).rejects.toBeInstanceOf(TimeoutError);
    await jest.advanceTimersByTimeAsync(30_000);
    await rejection;
    jest.useRealTimers();
  });

  it('fetches and caches on a miss', async () => {
    cacheGet.mockResolvedValue(null);
    apiGet.mockReturnValue(of(ARTICLE));
    const service = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(service.load(1));
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('does not cache a failure', async () => {
    cacheGet.mockResolvedValue(null);
    apiGet.mockReturnValue(of(aReaderFailure()));
    const service = TestBed.inject(ReaderContentService);
    await firstValueFrom(service.load(1));
    expect(cachePut).not.toHaveBeenCalled();
  });

  it('reload deletes the cache then fetches from the API even on a prior hit', async () => {
    apiGet.mockReturnValue(of(ARTICLE));
    const service = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(service.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(apiGet).toHaveBeenCalledWith(1);
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('reload does not cache a failed refetch', async () => {
    apiGet.mockReturnValue(of(aReaderFailure()));
    const service = TestBed.inject(ReaderContentService);
    await firstValueFrom(service.reload(1));
    expect(cacheDelete).toHaveBeenCalledWith(1);
    expect(cachePut).not.toHaveBeenCalled();
  });

  describe('signed urls', () => {
    const LINK = { url: 'https://x/story', token: A_READER_TOKEN };

    it('serves a cached signed url without calling the API', async () => {
      cacheGet.mockResolvedValue(ARTICLE);
      const result = await firstValueFrom(TestBed.inject(ReaderContentService).load(LINK));
      expect(result).toEqual(ARTICLE);
      expect(cacheGet).toHaveBeenCalledWith('url:https://x/story');
      expect(signedApiGet).not.toHaveBeenCalled();
    });

    it('fetches a missing signed url and caches it under its url key', async () => {
      cacheGet.mockResolvedValue(null);
      signedApiGet.mockReturnValue(of(ARTICLE));
      const result = await firstValueFrom(TestBed.inject(ReaderContentService).load(LINK));
      expect(result).toEqual(ARTICLE);
      expect(signedApiGet).toHaveBeenCalledWith(LINK);
      expect(cachePut).toHaveBeenCalledWith('url:https://x/story', ARTICLE);
    });

    it('does not cache a failed signed url', async () => {
      cacheGet.mockResolvedValue(null);
      signedApiGet.mockReturnValue(of(aReaderFailure()));
      await firstValueFrom(TestBed.inject(ReaderContentService).load(LINK));
      expect(cachePut).not.toHaveBeenCalled();
    });

    it('reload deletes the url key, then fetches and re-caches', async () => {
      signedApiGet.mockReturnValue(of(ARTICLE));
      const result = await firstValueFrom(TestBed.inject(ReaderContentService).reload(LINK));
      expect(cacheDelete).toHaveBeenCalledWith('url:https://x/story');
      expect(result).toEqual(ARTICLE);
      expect(cachePut).toHaveBeenCalledWith('url:https://x/story', ARTICLE);
    });
  });
});
