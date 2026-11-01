import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { SocialPostApi } from './social-post-api';

const REPLY = 'https://hachyderm.io/@ann/200';
const SHARED_URL = 'https://example.com/story';

describe('SocialPostApi', () => {
  let api: SocialPostApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(SocialPostApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('GETs the live counts of a post (#1615)', () => {
    let received: unknown;
    api.counts(7).subscribe((counts) => (received = counts));

    const testRequest = ctrl.expectOne('https://api.test/api/entries/7/social');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ likes: 3, reposts: 1, replies: 0, quotes: null });

    expect(received).toEqual({ likes: 3, reposts: 1, replies: 0, quotes: null });
  });

  it('GETs refreshed counts with ?refresh=1', () => {
    api.counts(7, true).subscribe();

    ctrl.expectOne('https://api.test/api/entries/7/social?refresh=1').flush({});
  });

  it('GETs what the reader did to the post', () => {
    let received: unknown;
    api.viewer(7).subscribe((viewer) => (received = viewer));

    const viewer = {
      isLiked: true,
      isReposted: false,
      canRepost: true,
      canReply: true,
      canQuote: true,
    };
    const testRequest = ctrl.expectOne('https://api.test/api/entries/7/social/viewer');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush(viewer);

    expect(received).toEqual(viewer);
  });

  it('PUTs a like and DELETEs it again', () => {
    api.like(3).subscribe();
    const like = ctrl.expectOne('https://api.test/api/entries/3/like');
    expect(like.request.method).toBe('PUT');
    like.flush({ isLiked: true });

    api.unlike(3).subscribe();
    const unlike = ctrl.expectOne('https://api.test/api/entries/3/like');
    expect(unlike.request.method).toBe('DELETE');
    unlike.flush({ isLiked: false });
  });

  it('PUTs a repost and DELETEs it again', () => {
    api.repost(3).subscribe();
    const repost = ctrl.expectOne('https://api.test/api/entries/3/repost');
    expect(repost.request.method).toBe('PUT');
    repost.flush({ isReposted: true });

    api.unrepost(3).subscribe();
    const unrepost = ctrl.expectOne('https://api.test/api/entries/3/repost');
    expect(unrepost.request.method).toBe('DELETE');
    unrepost.flush({ isReposted: false });
  });

  it.each([
    { name: 'likes', method: 'PUT', path: 'comments/like', call: () => api.likeComment(3, REPLY) },
    {
      name: 'unlikes',
      method: 'DELETE',
      path: 'comments/like',
      call: () => api.unlikeComment(3, REPLY),
    },
    {
      name: 'reposts',
      method: 'PUT',
      path: 'comments/repost',
      call: () => api.repostComment(3, REPLY),
    },
    {
      name: 'unreposts',
      method: 'DELETE',
      path: 'comments/repost',
      call: () => api.unrepostComment(3, REPLY),
    },
  ])('$name the reply named by its address', ({ method, path, call }) => {
    const request: Observable<unknown> = call();
    request.subscribe();

    const testRequest = ctrl.expectOne(`https://api.test/api/entries/3/${path}?address=${REPLY}`);
    expect(testRequest.request.method).toBe(method);
    testRequest.flush({});
  });

  it('GETs the composer context for a reply or quote of the post or a reply, on a chosen account', () => {
    api.composer({ kind: 'reply', entryId: 7, network: 'bluesky', address: null }).subscribe();
    expect(
      ctrl.expectOne('https://api.test/api/entries/7/social/composer?kind=reply').request.method,
    ).toBe('GET');

    api.composer({ kind: 'quote', entryId: 7, network: 'bluesky', address: REPLY }, 4).subscribe();
    ctrl
      .expectOne(
        `https://api.test/api/entries/7/social/composer?kind=quote&address=${REPLY}&accountId=4`,
      )
      .flush({});
  });

  it('POSTs a quote of the post, or of a reply named by its address', () => {
    const draft = { text: 'Look', accountId: 4, language: 'en', idempotencyKey: 'k-2' };
    api.send({ kind: 'quote', entryId: 7, network: 'bluesky', address: null }, draft).subscribe();

    const toPost = ctrl.expectOne('https://api.test/api/entries/7/social/quotes');
    expect(toPost.request.method).toBe('POST');
    expect(toPost.request.body).toEqual(draft);
    toPost.flush({ address: 'at://x', url: null });

    api.send({ kind: 'quote', entryId: 7, network: 'bluesky', address: REPLY }, draft).subscribe();
    ctrl.expectOne(`https://api.test/api/entries/7/social/quotes?address=${REPLY}`).flush({});
  });

  it('POSTs a reply to the post, or to a reply named by its address', () => {
    const draft = { text: 'Hi', accountId: 4, language: 'de', idempotencyKey: 'k-1' };
    let received: unknown;
    api
      .send({ kind: 'reply', entryId: 7, network: 'bluesky', address: null }, draft)
      .subscribe((published) => (received = published));

    const toPost = ctrl.expectOne('https://api.test/api/entries/7/social/replies');
    expect(toPost.request.method).toBe('POST');
    expect(toPost.request.body).toEqual(draft);
    toPost.flush({ address: 'at://x', url: null });
    expect(received).toEqual({ address: 'at://x', url: null });

    api.send({ kind: 'reply', entryId: 7, network: 'bluesky', address: REPLY }, draft).subscribe();
    ctrl.expectOne(`https://api.test/api/entries/7/social/replies?address=${REPLY}`).flush({});
  });

  it('GETs the composer context for the shared URL and its title on a network, on a chosen account', () => {
    const composerPath = 'https://api.test/api/social/link-composer';
    api
      .composer({ kind: 'link', network: 'bluesky', url: SHARED_URL, title: 'A story' })
      .subscribe();
    const bluesky = ctrl.expectOne((request) => request.url === composerPath);
    expect(bluesky.request.method).toBe('GET');
    expect(bluesky.request.params.get('network')).toBe('bluesky');
    expect(bluesky.request.params.get('url')).toBe(SHARED_URL);
    expect(bluesky.request.params.get('title')).toBe('A story');
    expect(bluesky.request.params.has('accountId')).toBe(false);
    bluesky.flush({});

    api.composer({ kind: 'link', network: 'mastodon', url: SHARED_URL, title: '' }, 4).subscribe();
    const mastodon = ctrl.expectOne((request) => request.url === composerPath);
    expect(mastodon.request.params.get('network')).toBe('mastodon');
    expect(mastodon.request.params.get('accountId')).toBe('4');
    mastodon.flush({});
  });

  it('POSTs a link post with the shared URL on its network in the query and the draft as the body', () => {
    const draft = { text: 'Read this', accountId: 4, language: 'en', idempotencyKey: 'k-3' };
    api
      .send({ kind: 'link', network: 'mastodon', url: SHARED_URL, title: 'A story' }, draft)
      .subscribe();

    const toPost = ctrl.expectOne((request) => request.url === 'https://api.test/api/social/links');
    expect(toPost.request.method).toBe('POST');
    expect(toPost.request.params.get('network')).toBe('mastodon');
    expect(toPost.request.params.get('url')).toBe(SHARED_URL);
    expect(toPost.request.params.has('title')).toBe(false);
    expect(toPost.request.body).toEqual(draft);
    toPost.flush({ address: 'https://x/1', url: null });
  });

  it('DELETEs an own reply by its address', () => {
    api.deleteReply(7, REPLY).subscribe();

    const testRequest = ctrl.expectOne(
      `https://api.test/api/entries/7/comments/reply?address=${REPLY}`,
    );
    expect(testRequest.request.method).toBe('DELETE');
    testRequest.flush(null);
  });
});
