import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../core/api';
import { CatalogApi } from './catalog-api';

describe('CatalogApi', () => {
  let api: CatalogApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(CatalogApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the catalog', () => {
    let categories: unknown;
    api.load().subscribe((r) => (categories = r.categories));

    const req = http.expectOne('https://api.test/api/catalog');
    expect(req.request.method).toBe('GET');
    req.flush({ categories: [{ id: 1, key: 'technology', name: 'Technology', feeds: [] }] });

    expect(categories).toHaveLength(1);
  });

  it('posts the selected ids to the onboarding endpoint', () => {
    api.subscribe([3, 1, 2]).subscribe();

    const req = http.expectOne('https://api.test/api/onboarding/subscribe');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ catalogFeedIds: [3, 1, 2] });
    req.flush({ subscribed: 3, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });
  });
});
