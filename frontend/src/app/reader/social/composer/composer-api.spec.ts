import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { ComposerApi } from './composer-api';

const REPLY = 'https://hachyderm.io/@ann/200';
const SHARED_URL = 'https://example.com/story';

describe('ComposerApi', () => {
  let api: ComposerApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(ComposerApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('GETs the composer context for a reply or quote of the post or a reply, on a chosen account', () => {
    api.context({ kind: 'reply', entryId: 7, network: 'bluesky', address: null }).subscribe();
    expect(
      ctrl.expectOne('https://api.test/api/entries/7/social/composer?kind=reply').request.method,
    ).toBe('GET');

    api.context({ kind: 'quote', entryId: 7, network: 'bluesky', address: REPLY }, 4).subscribe();
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
      .context({
        kind: 'link',
        network: 'bluesky',
        url: SHARED_URL,
        title: 'A story',
        quote: null,
      })
      .subscribe();
    const bluesky = ctrl.expectOne((request) => request.url === composerPath);
    expect(bluesky.request.method).toBe('GET');
    expect(bluesky.request.params.get('network')).toBe('bluesky');
    expect(bluesky.request.params.get('url')).toBe(SHARED_URL);
    expect(bluesky.request.params.get('title')).toBe('A story');
    expect(bluesky.request.params.has('accountId')).toBe(false);
    bluesky.flush({});

    api
      .context({ kind: 'link', network: 'mastodon', url: SHARED_URL, title: '', quote: null }, 4)
      .subscribe();
    const mastodon = ctrl.expectOne((request) => request.url === composerPath);
    expect(mastodon.request.params.get('network')).toBe('mastodon');
    expect(mastodon.request.params.get('accountId')).toBe('4');
    mastodon.flush({});
  });

  it('POSTs a link post with the shared URL and its network in the body beside the draft', () => {
    const draft = { text: 'Read this', accountId: 4, language: 'en', idempotencyKey: 'k-3' };
    api
      .send(
        { kind: 'link', network: 'mastodon', url: SHARED_URL, title: 'A story', quote: null },
        draft,
      )
      .subscribe();

    const toPost = ctrl.expectOne((request) => request.url === 'https://api.test/api/social/links');
    expect(toPost.request.method).toBe('POST');
    expect(toPost.request.params.keys()).toEqual([]);
    expect(toPost.request.body).toEqual({ ...draft, network: 'mastodon', url: SHARED_URL });
    toPost.flush({ address: 'https://x/1', url: null });
  });

  it('sends the quote the composer starts from only when there is one', () => {
    const composerPath = 'https://api.test/api/social/link-composer';
    api
      .context({
        kind: 'link',
        network: 'mastodon',
        url: SHARED_URL,
        title: 'A story',
        quote: 'A line',
      })
      .subscribe();
    const quoted = ctrl.expectOne((request) => request.url === composerPath);
    expect(quoted.request.params.get('quote')).toBe('A line');
    quoted.flush({});

    api
      .context({
        kind: 'link',
        network: 'mastodon',
        url: SHARED_URL,
        title: 'A story',
        quote: null,
      })
      .subscribe();
    const plain = ctrl.expectOne((request) => request.url === composerPath);
    expect(plain.request.params.has('quote')).toBe(false);
    plain.flush({});
  });
});
