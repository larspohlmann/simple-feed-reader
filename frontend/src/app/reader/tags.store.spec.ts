import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { TagsStore } from './tags.store';
import { TagDto } from './models';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
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
    expect(store.tags().map((t) => t.name)).toEqual(['Alpha', 'Zeta']);
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
});
