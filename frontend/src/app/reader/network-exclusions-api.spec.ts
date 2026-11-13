import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { NetworkExclusionsApi } from './network-exclusions-api';

describe('NetworkExclusionsApi', () => {
  let api: NetworkExclusionsApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(NetworkExclusionsApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it.each<{ call: () => Observable<unknown>; method: string; url: string }>([
    {
      call: () => api.excludedNetworks('all-items'),
      method: 'GET',
      url: 'https://api.test/api/all-items/excluded-networks',
    },
    {
      call: () => api.exclude('all-items', 'bluesky'),
      method: 'PUT',
      url: 'https://api.test/api/all-items/excluded-networks/bluesky',
    },
    {
      call: () => api.include('all-items', 'mastodon'),
      method: 'DELETE',
      url: 'https://api.test/api/all-items/excluded-networks/mastodon',
    },
    {
      call: () => api.excludedNetworks('for-you'),
      method: 'GET',
      url: 'https://api.test/api/for-you/excluded-networks',
    },
    {
      call: () => api.exclude('for-you', 'bluesky'),
      method: 'PUT',
      url: 'https://api.test/api/for-you/excluded-networks/bluesky',
    },
    {
      call: () => api.include('for-you', 'mastodon'),
      method: 'DELETE',
      url: 'https://api.test/api/for-you/excluded-networks/mastodon',
    },
  ])('$method $url', ({ call, method, url }) => {
    call().subscribe();
    const testRequest = ctrl.expectOne(url);
    expect(testRequest.request.method).toBe(method);
    testRequest.flush(null);
  });
});
