import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { SearchPreview, SocialSearchService } from './social-search.service';

describe('SocialSearchService', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('previews a search with the query as a parameter', () => {
    let preview: SearchPreview | undefined;

    TestBed.inject(SocialSearchService)
      .preview(7, '"rss reader" || feedreader')
      .subscribe((answer) => (preview = answer));

    http
      .expectOne(
        (candidate) =>
          candidate.url === 'https://api.test/api/me/social-accounts/7/search-preview' &&
          candidate.params.get('query') === '"rss reader" || feedreader',
      )
      .flush({ count: 0, posts: [] });
    expect(preview).toEqual({ count: 0, posts: [] });
  });

  it('puts a new query on a search subscription', () => {
    TestBed.inject(SocialSearchService).changeQuery(5, 'feed reader').subscribe();

    const request = http.expectOne('https://api.test/api/subscriptions/5/search-query');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ query: 'feed reader' });
    request.flush({ subscription: null });
  });
});
