import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { FeedDirectoryApi } from './feed-directory-api';

describe('FeedDirectoryApi', () => {
  let api: FeedDirectoryApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(FeedDirectoryApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the index', () => {
    api.index().subscribe();

    const testRequest = http.expectOne('https://api.test/api/feed-directory/index');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ countries: [], languages: {} });
  });

  it('loads one country file', () => {
    api.country('DE').subscribe();

    const testRequest = http.expectOne('https://api.test/api/feed-directory/countries/DE');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ country: 'DE', categories: [], languages: [], domains: [] });
  });

  it('loads the subscribed urls and hosts', () => {
    api.subscribed().subscribe();

    const testRequest = http.expectOne('https://api.test/api/feed-directory/subscribed');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ urls: [], hosts: [] });
  });

  it('saves the scope with PATCH', () => {
    api.saveScope({ countries: ['DE'], languages: ['de'] }).subscribe();

    const testRequest = http.expectOne('https://api.test/api/me/feed-directory-scope');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ countries: ['DE'], languages: ['de'] });
    testRequest.flush({});
  });
});
