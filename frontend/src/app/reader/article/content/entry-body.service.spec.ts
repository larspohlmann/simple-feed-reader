import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { TokenStore } from '../../../core/auth/token.store';
import { EntriesStore } from '../../state/entries.store';
import { EntryBodyService } from './entry-body.service';

describe('EntryBodyService', () => {
  let store: EntryBodyService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  let mergeCompleted: jest.Mock;

  beforeEach(() => {
    localStorage.clear();
    mergeCompleted = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: EntriesStore, useValue: { mergeCompleted } },
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

  describe('refresh (#1502)', () => {
    async function shown(html: string) {
      const state = store.body(1);
      await settle();
      ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: html } });
      return state;
    }

    it('refetches a shown body in place, without a loading state between', async () => {
      const state = await shown('<p>a</p>');

      store.refresh(1);
      await settle();
      expect(state()).toEqual({ status: 'ok', html: '<p>a</p>' });
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { contentHtml: '<p>b</p>' } });

      expect(state()).toEqual({ status: 'ok', html: '<p>b</p>' });
    });

    it('keeps the shown body when the refetch fails', async () => {
      const state = await shown('<p>a</p>');

      store.refresh(1);
      await settle();
      ctrl.expectOne('https://api.test/api/entries/1').flush('nope', {
        status: 500,
        statusText: 'Server Error',
      });

      expect(state()).toEqual({ status: 'ok', html: '<p>a</p>' });
    });

    it('cancels the fetch still on the wire and ends on the new body', async () => {
      const state = store.body(1);
      await settle();
      const first = ctrl.expectOne('https://api.test/api/entries/1');

      store.refresh(1);
      await settle();
      expect(first.cancelled).toBe(true);
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { contentHtml: '<p>new</p>' } });

      expect(state()).toEqual({ status: 'ok', html: '<p>new</p>' });
    });

    it('forgets a body so the next request fetches it afresh', async () => {
      await shown('<p>a</p>');

      store.forget(1);
      const state = store.body(1);
      await settle();
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { contentHtml: '<p>b</p>' } });

      expect(state()).toEqual({ status: 'ok', html: '<p>b</p>' });
    });

    it('merges what completing a fetched entry filled in, and cancels a body forgotten meanwhile', async () => {
      store.body(1);
      store.body(2);
      await settle();

      store.forget(2);
      expect(ctrl.expectOne('https://api.test/api/entries/2').cancelled).toBe(true);
      ctrl
        .expectOne('https://api.test/api/entries/1')
        .flush({ entry: { id: 1, contentHtml: '' }, completed: { id: 1 } });

      expect(mergeCompleted.mock.calls).toEqual([[[{ id: 1 }]]]);
    });

    it('fetches nothing for a body nobody has loaded', async () => {
      store.refresh(5);
      await settle();

      ctrl.expectNone('https://api.test/api/entries/5');
    });
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

  it('lets an evicted body still on the wire land in the signal a view holds', async () => {
    const evicted = store.body(1);
    for (let id = 2; id <= 51; id++) store.body(id);
    store.body(1);
    await settle();

    const [first, again] = ctrl.match('https://api.test/api/entries/1');
    expect(first.cancelled).toBe(false);
    first.flush({ entry: { contentHtml: '<p>1</p>' } });
    again.flush({ entry: { contentHtml: '<p>1</p>' } });
    ctrl
      .match((request) => request.url.startsWith('https://api.test/api/entries/'))
      .forEach((request) => request.flush({ entry: { contentHtml: '' } }));

    expect(evicted()).toEqual({ status: 'ok', html: '<p>1</p>' });
  });

  it("never shows one entry's body under another, whichever lands first", async () => {
    const first = store.body(1);
    const second = store.body(2);
    await settle();

    ctrl.expectOne('https://api.test/api/entries/2').flush({ entry: { contentHtml: '<p>B</p>' } });
    ctrl.expectOne('https://api.test/api/entries/1').flush({ entry: { contentHtml: '<p>A</p>' } });

    expect(second()).toEqual({ status: 'ok', html: '<p>B</p>' });
    expect(first()).toEqual({ status: 'ok', html: '<p>A</p>' });
  });

  describe('when the signed-in identity changes', () => {
    it('cancels a request still on the wire for the previous account', async () => {
      store.body(1);
      await settle();
      const testRequest = ctrl.expectOne('https://api.test/api/entries/1');

      tokens.clear();
      TestBed.tick();

      expect(testRequest.cancelled).toBe(true);
    });

    it('never sends a request asked for just before the account changed', async () => {
      store.body(1);
      tokens.clear();
      TestBed.tick();
      await settle();

      ctrl.expectNone('https://api.test/api/entries/1');
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
