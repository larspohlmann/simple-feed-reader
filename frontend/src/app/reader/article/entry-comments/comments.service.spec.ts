import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError, Subject } from 'rxjs';
import { CommentsService, CommentsState } from './comments.service';
import { ReaderApi } from '../../reader-api';
import { SocialPostApi } from '../../social-post-api';
import { CommentsResponse, EntryCommentPostDto } from '../../entry-comment';
import { aCommentPost, anEntryComment } from '../../../../testing/entry-testing';

describe('CommentsService', () => {
  let api: { comments: jest.Mock };
  let service: CommentsService;

  const ok: CommentsResponse = {
    status: 'ok',
    comments: [anEntryComment({ author: '/u/a' })],
    omitted: 3,
    fetchedAt: '2026-09-24T11:59:00Z',
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-09-24T12:00:00Z'));
    api = { comments: jest.fn(() => of(ok)) };
    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderApi, useValue: api },
        { provide: SocialPostApi, useValue: api },
      ],
    });
    service = TestBed.inject(CommentsService);
  });

  afterEach(() => jest.useRealTimers());

  describe('toggleLike', () => {
    const address = 'at://did:plc:ann/app.bsky.feed.post/2';
    const post: EntryCommentPostDto = {
      address,
      counts: { likes: 4, reposts: 0, replies: 1, quotes: 0 },
      isLiked: false,
      isReposted: false,
      canRepost: true,
      canReply: true,
      isOwn: false,
    };
    let likes: { likeComment: jest.Mock; unlikeComment: jest.Mock };
    let answer: Subject<{ isLiked: boolean }>;
    let onError: jest.Mock;

    beforeEach(() => {
      answer = new Subject();
      likes = { likeComment: jest.fn(() => answer), unlikeComment: jest.fn(() => answer) };
      Object.assign(api, likes);
      api.comments.mockReturnValue(
        of({ ...ok, comments: [anEntryComment({ post }), anEntryComment()] }),
      );
      onError = jest.fn();
      service.load(1);
    });

    const shownPost = () => {
      const state = service.state(1)();
      return state.status === 'ok' ? state.comments[0].post : undefined;
    };

    it('likes the comment at once and counts the like', () => {
      service.toggleLike(1, post, onError);

      expect(likes.likeComment).toHaveBeenCalledWith(1, address);
      expect(shownPost()).toEqual({ ...post, isLiked: true, counts: { ...post.counts, likes: 5 } });
    });

    it('takes a like back through the unlike call', () => {
      service.toggleLike(1, post, onError);
      answer.next({ isLiked: true });
      answer.complete();
      answer = new Subject();
      likes.unlikeComment.mockReturnValue(answer);

      service.toggleLike(1, { ...post, isLiked: true }, onError);

      expect(likes.unlikeComment).toHaveBeenCalledWith(1, address);
      expect(shownPost()).toEqual(post);
    });

    it('ignores a second toggle while the first is on the wire', () => {
      service.toggleLike(1, post, onError);
      service.toggleLike(1, { ...post, isLiked: true }, onError);

      expect(likes.unlikeComment).not.toHaveBeenCalled();
      expect(shownPost()?.isLiked).toBe(true);
    });

    it('puts the like back and reports the problem when the call fails', () => {
      service.toggleLike(1, post, onError);

      answer.error(new HttpErrorResponse({ status: 409, error: { title: 'No account' } }));

      expect(shownPost()).toEqual(post);
      expect(onError).toHaveBeenCalledTimes(1);
    });

    it('leaves the other comments alone', () => {
      service.toggleLike(1, post, onError);

      const state = service.state(1)();
      expect(state.status === 'ok' && state.comments[1].post).toBeNull();
    });
  });

  describe('toggleRepost', () => {
    const address = 'https://hachyderm.io/users/ann/statuses/200';
    const post: EntryCommentPostDto = {
      address,
      counts: { likes: 4, reposts: 2, replies: 1, quotes: null },
      isLiked: true,
      isReposted: false,
      canRepost: true,
      canReply: true,
      isOwn: false,
    };
    let reposts: { repostComment: jest.Mock; unrepostComment: jest.Mock };
    let answer: Subject<{ isReposted: boolean }>;
    let onError: jest.Mock;

    beforeEach(() => {
      answer = new Subject();
      reposts = { repostComment: jest.fn(() => answer), unrepostComment: jest.fn(() => answer) };
      Object.assign(api, reposts);
      api.comments.mockReturnValue(of({ ...ok, comments: [anEntryComment({ post })] }));
      onError = jest.fn();
      service.load(1);
    });

    const shownPost = () => {
      const state = service.state(1)();
      return state.status === 'ok' ? state.comments[0].post : undefined;
    };

    it('reposts the comment at once and counts the repost', () => {
      service.toggleRepost(1, post, onError);

      expect(reposts.repostComment).toHaveBeenCalledWith(1, address);
      expect(shownPost()).toEqual({
        ...post,
        isReposted: true,
        counts: { ...post.counts, reposts: 3 },
      });
    });

    it('undoes a repost through the unrepost call', () => {
      service.toggleRepost(1, { ...post, isReposted: true }, onError);

      expect(reposts.unrepostComment).toHaveBeenCalledWith(1, address);
    });

    it('puts the repost back and reports the problem when the call fails', () => {
      service.toggleRepost(1, post, onError);

      answer.error(new HttpErrorResponse({ status: 409, error: { title: 'Refused' } }));

      expect(shownPost()).toEqual(post);
      expect(onError).toHaveBeenCalledTimes(1);
    });

    it('ignores a second toggle while the first is on the wire', () => {
      service.toggleRepost(1, post, onError);
      service.toggleRepost(1, { ...post, isReposted: true }, onError);

      expect(reposts.unrepostComment).not.toHaveBeenCalled();
    });

    it('lets a like through while a repost of the same comment is on the wire', () => {
      const likeComment = jest.fn(() => new Subject());
      Object.assign(api, { likeComment, unlikeComment: jest.fn() });
      service.toggleRepost(1, post, onError);

      service.toggleLike(1, { ...post, isLiked: false }, onError);

      expect(likeComment).toHaveBeenCalledWith(1, address);
    });

    it('holds a reload back until the repost settles', () => {
      service.toggleRepost(1, post, onError);

      service.reload(1);

      expect(api.comments).toHaveBeenCalledTimes(1);
    });

    it('does nothing on a comment the network keeps from reposts', () => {
      service.toggleRepost(1, { ...post, canRepost: false }, onError);

      expect(reposts.repostComment).not.toHaveBeenCalled();
      expect(shownPost()).toEqual(post);
    });
  });

  it('starts idle and fetches nothing until asked', () => {
    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(api.comments).not.toHaveBeenCalled();
  });

  it('loads once and serves the cache inside the hour', () => {
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({
      status: 'ok',
      comments: ok.comments,
      omitted: 3,
      fetchedAt: '2026-09-24T11:59:00Z',
      loadedAt: Date.parse('2026-09-24T12:00:00Z'),
    });
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

  it('asks the server to refresh on reload only', () => {
    service.load(1);
    service.reload(1);
    expect(api.comments).toHaveBeenNthCalledWith(1, 1);
    expect(api.comments).toHaveBeenNthCalledWith(2, 1, true);
  });

  it('keeps the fetch time and the omitted count of an ok answer', () => {
    service.load(1);
    expect(service.state(1)()).toMatchObject({
      status: 'ok',
      omitted: 3,
      fetchedAt: '2026-09-24T11:59:00Z',
    });
  });

  it('maps throttled to a retry instant', () => {
    api.comments.mockReturnValue(of({ status: 'throttled', retryAfter: 40 }));
    service.load(1);
    expect(service.state(1)()).toEqual({
      status: 'throttled',
      retryAt: Date.parse('2026-09-24T12:00:40Z'),
    });
  });

  it('does not retry a failed load within the minute', () => {
    api.comments.mockReturnValue(of({ status: 'failed' }));
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T12:00:59Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
  });

  it('loads a failed entry again once a minute has passed', () => {
    api.comments.mockReturnValue(of({ status: 'failed' }));
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T12:01:00Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('does not retry a throttled load before its wait is over', () => {
    api.comments.mockReturnValue(of({ status: 'throttled', retryAfter: 40 }));
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T12:00:39Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
  });

  it('loads a throttled entry again once its wait is over', () => {
    api.comments.mockReturnValue(of({ status: 'throttled', retryAfter: 40 }));
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T12:00:40Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('retries a failed or throttled load when asked to', () => {
    api.comments.mockReturnValue(of({ status: 'failed' }));
    service.load(1);
    service.reload(1);
    api.comments.mockReturnValue(of({ status: 'throttled', retryAfter: 40 }));
    service.load(2);
    service.reload(2);
    expect(api.comments).toHaveBeenCalledTimes(4);
  });

  it('maps a transport error to failed', () => {
    api.comments.mockReturnValue(throwError(() => new Error('offline')));
    service.load(1);
    expect(service.state(1)()).toEqual({
      status: 'failed',
      failedAt: Date.parse('2026-09-24T12:00:00Z'),
    });
  });

  it('ignores a second load while one is in flight', () => {
    const pending = new Subject<CommentsResponse>();
    api.comments.mockReturnValue(pending);
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'loading' });
  });

  describe('a reply the reader changed', () => {
    it('shows a reply the reader published and hides one they deleted', () => {
      api.comments.mockReturnValue(
        of({
          status: 'ok',
          comments: [anEntryComment({ html: 'old' })],
          omitted: 0,
          fetchedAt: 'now',
        }),
      );
      service.load(1);
      const reply = anEntryComment({
        html: 'mine',
        post: aCommentPost({ address: 'at://mine', isOwn: true }),
      });

      service.insertReply(1, null, reply);
      expect(htmlOf(service.state(1)())).toEqual(['mine', 'old']);

      service.removeReply(1, 'at://mine');
      expect(htmlOf(service.state(1)())).toEqual(['old']);
    });

    it('leaves a thread that is not loaded alone', () => {
      service.insertReply(2, null, anEntryComment());

      expect(service.state(2)()).toEqual({ status: 'idle' });
    });
  });
});

function htmlOf(state: CommentsState): string[] {
  return state.status === 'ok' ? state.comments.map((comment) => comment.html) : [];
}
