import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { TagsStore } from './tags.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { TokenStore } from '../../core/auth/token.store';
import { TagDto } from '../models';
import { INCLUDED_EVERYWHERE } from '../tag-inclusion';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
  parentId: null,
  ...INCLUDED_EVERYWHERE,
});

describe('TagsStore', () => {
  let store: TagsStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(TagsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('loads tags sorted by name', () => {
    store.load();
    expect(store.loading()).toBe(true);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [tag(1, 'Zeta'), tag(2, 'Alpha')] });
    expect(store.loading()).toBe(false);
    expect(store.tags().map((listedTag) => listedTag.name)).toEqual(['Alpha', 'Zeta']);
  });

  it('exposes the roots on their own', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/tags')
      .flush({ tags: [tag(1, 'Reading'), { ...tag(2, 'Long reads'), parentId: 1 }] });
  });

  it('loads at most once per freshness window, and afresh for the next user', () => {
    jest.useFakeTimers({ now: new Date('2026-11-13T12:00:00Z') });

    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    store.loadIfStale();
    ctrl.expectNone('https://api.test/api/tags');

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });

    const tokens = TestBed.inject(TokenStore);
    tokens.set('user-b.jwt');
    TestBed.tick();
    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    tokens.clear();
    jest.useRealTimers();
  });

  it('records a Problem on error', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/tags')
      .flush(
        { type: 'about:blank', title: 'Nope', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );
    expect(store.error()?.title).toBe('Nope');
    expect(store.loading()).toBe(false);
  });

  it('finds a listed tag by id, and nothing for an unknown one', () => {
    store.tags.set([tag(1, 'Zeta'), tag(2, 'Alpha')]);
    expect(store.byId(2)?.name).toBe('Alpha');
    expect(store.byId(3)).toBeUndefined();
  });

  it('adds a tag in position order', () => {
    store.tags.set([
      { ...tag(1, 'Alpha'), position: 0 },
      { ...tag(2, 'Gamma'), position: 2 },
    ]);

    store.add({ ...tag(3, 'Beta'), position: 1 });

    expect(store.tags().map((listedTag) => listedTag.name)).toEqual(['Alpha', 'Beta', 'Gamma']);
  });
});
