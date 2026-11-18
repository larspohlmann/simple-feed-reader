import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { storiesState } from '../../../testing/stories-settings';
import { StoriesSettingsService } from './stories-settings.service';

const ENDPOINT = '/api/me/ai/stories';

describe('StoriesSettingsService', () => {
  let service: StoriesSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        StoriesSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    service = TestBed.inject(StoriesSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the state from /api/me/ai/stories', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(storiesState({ titlesEnabled: true }));

    expect(service.state()?.titlesEnabled).toBe(true);
  });

  it('saves a change over the last-saved state', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(storiesState({ writingConnectionId: 7 }));

    service.saveInstant({ titlesEnabled: false });

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual({ writingConnectionId: 7, titlesEnabled: false });
    request.flush(storiesState({ writingConnectionId: 7, titlesEnabled: false }));
    expect(service.state()?.titlesEnabled).toBe(false);
  });
});
