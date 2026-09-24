import { TestBed } from '@angular/core/testing';
import { of, throwError, Subject } from 'rxjs';
import { CommentsService } from './comments.service';
import { ReaderApi } from './reader-api';
import { CommentsResponse } from './models';

describe('CommentsService', () => {
  let api: { comments: jest.Mock };
  let service: CommentsService;

  const ok: CommentsResponse = {
    status: 'ok',
    discussionUrl: 'https://t.example/1',
    comments: [
      {
        author: '/u/a',
        authorUrl: null,
        url: null,
        publishedAt: null,
        html: '<p>x</p>',
        byEntryAuthor: false,
      },
    ],
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-09-24T12:00:00Z'));
    api = { comments: jest.fn(() => of(ok)) };
    TestBed.configureTestingModule({ providers: [{ provide: ReaderApi, useValue: api }] });
    service = TestBed.inject(CommentsService);
  });

  afterEach(() => jest.useRealTimers());

  it('starts idle and fetches nothing until asked', () => {
    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(api.comments).not.toHaveBeenCalled();
  });

  it('loads once and serves the cache inside the hour', () => {
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'ok', comments: ok.comments });
  });

  it('refetches once the hour has passed', () => {
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T13:00:01Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('reload bypasses the cache', () => {
    service.load(1);
    service.reload(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('maps throttled to a retry instant', () => {
    api.comments.mockReturnValue(of({ status: 'throttled', discussionUrl: null, retryAfter: 40 }));
    service.load(1);
    expect(service.state(1)()).toEqual({
      status: 'throttled',
      retryAt: Date.parse('2026-09-24T12:00:40Z'),
    });
  });

  it('does not cache a throttled or failed result', () => {
    api.comments.mockReturnValue(of({ status: 'failed', discussionUrl: null }));
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('maps a transport error to failed', () => {
    api.comments.mockReturnValue(throwError(() => new Error('offline')));
    service.load(1);
    expect(service.state(1)()).toEqual({ status: 'failed' });
  });

  it('ignores a second load while one is in flight', () => {
    const pending = new Subject<CommentsResponse>();
    api.comments.mockReturnValue(pending);
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'loading' });
  });
});
