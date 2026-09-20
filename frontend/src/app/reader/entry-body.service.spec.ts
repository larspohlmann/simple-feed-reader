import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { TokenStore } from '../core/token.store';
import { EntryBodyService } from './entry-body.service';

describe('EntryBodyService', () => {
  let store: EntryBodyService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    tokens = TestBed.inject(TokenStore);
    tokens.set('user-a.jwt');
    store = TestBed.inject(EntryBodyService);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  /** The store defers its own fetch past the current microtask (so a mocked,
   *  synchronously-emitting observable can never write mid-`computed()`) — flush it. */
  const settle = () => Promise.resolve();

  it('fetches the body on first request', async () => {
    const state = store.body(1);
    expect(state()).toEqual({ status: 'loading' });

    await settle();
    ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: '<p>a</p>' } });

    expect(state()).toEqual({ status: 'ok', html: '<p>a</p>' });
  });

  it('answers a cache hit without a second request', async () => {
    store.body(1);
    await settle();
    ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: '<p>a</p>' } });

    const second = store.body(1);
    await settle();

    expect(second()).toEqual({ status: 'ok', html: '<p>a</p>' });
    ctrl.expectNone('https://api.test/api/entries/1');
  });

  it('de-dupes a request still in flight', async () => {
    const first = store.body(1);
    const second = store.body(1);
    await settle();

    ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: '<p>a</p>' } });

    expect(first()).toEqual(second());
  });

  it('surfaces a failed fetch as an error state', async () => {
    const state = store.body(1);
    await settle();

    ctrl.expectOne('https://api.test/api/entries/1').flush('nope', {
      status: 500,
      statusText: 'Server Error',
    });

    expect(state()).toEqual({ status: 'error' });
  });

  it('seeds a body directly, skipping a request', async () => {
    store.seed(1, '<p>seeded</p>');
    const state = store.body(1);
    await settle();

    expect(state()).toEqual({ status: 'ok', html: '<p>seeded</p>' });
    ctrl.expectNone('https://api.test/api/entries/1');
  });

  it('prefetch warms the cache for an id nothing is displaying yet', async () => {
    store.prefetch(7);
    await settle();
    ctrl.expectOne('https://api.test/api/entries/7').flush({ entry: { contentHtml: '<p>x</p>' } });

    expect(store.body(7)()).toEqual({ status: 'ok', html: '<p>x</p>' });
  });

  it('retry refetches a failed body', async () => {
    const state = store.body(1);
    await settle();
    ctrl.expectOne('https://api.test/api/entries/1').flush('nope', {
      status: 500,
      statusText: 'Server Error',
    });
    expect(state()).toEqual({ status: 'error' });

    store.retry(1);
    expect(state()).toEqual({ status: 'loading' });
    await settle();
    ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: '<p>b</p>' } });

    expect(state()).toEqual({ status: 'ok', html: '<p>b</p>' });
  });

  it('evicts the least recently used body once the cache grows past its cap', async () => {
    for (let id = 1; id <= 50; id++) {
      store.body(id);
      await settle();
      ctrl.expectOne(`https://api.test/api/entries/${id}`).flush({
        entry: { contentHtml: `<p>${id}</p>` },
      });
    }
    // Touch id 1 so it is no longer the least recently used entry.
    store.body(1);
    await settle();
    ctrl.expectNone('https://api.test/api/entries/1');

    // Entry 51 pushes the cache past its cap of 50 — id 2, now the oldest, is evicted.
    store.body(51);
    await settle();
    ctrl
      .expectOne('https://api.test/api/entries/51')
      .flush({ entry: { contentHtml: '<p>51</p>' } });

    store.body(2);
    await settle();
    ctrl.expectOne('https://api.test/api/entries/2').flush({ entry: { contentHtml: '<p>2</p>' } });
  });

  describe('when the signed-in identity changes', () => {
    it('drops a response that was still on the wire before the account changed', async () => {
      const state = store.body(1);
      await settle();
      const req = ctrl.expectOne('https://api.test/api/entries/1');

      tokens.clear();
      TestBed.tick();
      req.flush({ entry: { contentHtml: '<p>from the previous account</p>' } });

      // Cleared, and the stale response must not have written back into it.
      expect(state()).toEqual({ status: 'loading' });
    });

    it('refetches for the next signed-in account', async () => {
      store.body(1);
      await settle();
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { contentHtml: '<p>a</p>' } });

      tokens.clear();
      tokens.set('user-b.jwt');
      TestBed.tick();

      const state = store.body(1);
      expect(state()).toEqual({ status: 'loading' });
      await settle();
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { contentHtml: '<p>b</p>' } });
      expect(state()).toEqual({ status: 'ok', html: '<p>b</p>' });
    });
  });
});
