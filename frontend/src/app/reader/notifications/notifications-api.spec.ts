import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { NotificationsApi } from './notifications-api';

describe('NotificationsApi (#2240)', () => {
  let api: NotificationsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(NotificationsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it("asks for a network's first page", () => {
    api.page('bluesky', null).subscribe();

    const request = http.expectOne('https://api.test/api/me/notifications?network=bluesky');
    expect(request.request.params.has('cursor')).toBe(false);
  });

  it('asks for the next page by cursor', () => {
    api.page('bluesky', 41).subscribe();

    http.expectOne('https://api.test/api/me/notifications?network=bluesky&cursor=41');
  });

  it('posts the seen position', () => {
    api.markSeen('mastodon', 77).subscribe();

    const request = http.expectOne('https://api.test/api/me/notifications/seen');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ network: 'mastodon', upTo: 77 });
  });

  it('posts a refresh for a network (#2502)', () => {
    api.refresh('lemmy').subscribe();

    const request = http.expectOne('https://api.test/api/me/notifications/refresh');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ network: 'lemmy' });
  });
});
