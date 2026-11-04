import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { anEntry } from '../../../testing/entry-testing';
import { EntriesPage } from '../models';
import { TrendingApi } from './trending-api';

describe('TrendingApi (#2017)', () => {
  let api: TrendingApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(TrendingApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it("reads a window's stories as a list page with no further page", () => {
    let page: EntriesPage | undefined;
    api.page('week').subscribe((answer) => (page = answer));
    const request = ctrl.expectOne((each) => each.url === 'https://api.test/api/trending');
    expect(request.request.params.get('window')).toBe('week');
    request.flush({ window: 'week', builtAt: null, stories: [anEntry({ id: 9 })] });

    expect(page).toEqual({ entries: [anEntry({ id: 9 })], nextCursor: null });
  });

  it('hides a story of a window by its lead', () => {
    api.hideStory('day', 4).subscribe();
    const request = ctrl.expectOne('https://api.test/api/trending/hidden');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ window: 'day', entryId: 4 });
    request.flush(null, { status: 204, statusText: 'No Content' });
  });
});
