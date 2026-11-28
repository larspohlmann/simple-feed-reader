import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { YourStreamSettingsService, YourStreamState } from './your-stream-settings.service';

const URL = 'https://api.test/api/me/your-stream';

function aState(over: Partial<YourStreamState> = {}): YourStreamState {
  return { enabled: true, precision: 'wide', defaultPrecision: 'wide', words: [], ...over };
}

describe('YourStreamSettingsService', () => {
  let service: YourStreamSettingsService;
  let http: HttpTestingController;
  const subscriptions = { reloadCountsQuietly: jest.fn() };

  beforeEach(() => {
    subscriptions.reloadCountsQuietly.mockReset();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: subscriptions },
      ],
    });
    service = TestBed.inject(YourStreamSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loaded(state: YourStreamState): void {
    service.load();
    http.expectOne(URL).flush(state);
  }

  it('loads from and saves to /api/me/your-stream', () => {
    service.load();
    http.expectOne(URL).flush(aState({ enabled: true, precision: 'wide' }));
    expect(service.shown()?.precision).toBe('wide');

    service.saveInstant({ enabled: false, precision: 'narrow' });
    const put = http.expectOne(URL);

    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ enabled: false, precision: 'narrow' });
    put.flush(aState({ enabled: false, precision: 'narrow' }));
  });

  it('has no state before the first answer', () => {
    expect(service.shown()).toBeNull();
  });

  it('shows the pending settings until the answer lands, and drops them on failure', () => {
    loaded(aState({ precision: 'wide' }));

    service.saveInstant({ enabled: true, precision: 'narrow' });
    expect(service.shown()?.precision).toBe('narrow');

    http
      .expectOne(URL)
      .flush({ type: 'about:blank', title: 'boom' }, { status: 500, statusText: 'Server Error' });
    expect(service.shown()?.precision).toBe('wide');
    expect(service.failure()?.title).toBe('boom');
    expect(service.saved()).toBe(false);
  });

  it('adopts the answer, words included, and flags the save', () => {
    loaded(aState());

    service.saveInstant({ enabled: true, precision: 'narrow' });
    http.expectOne(URL).flush(
      aState({
        precision: 'narrow',
        words: [{ language: null, words: [{ word: 'Bundesliga', weight: 0.5 }] }],
      }),
    );

    expect(service.shown()?.words).toHaveLength(1);
    expect(service.saved()).toBe(true);
  });

  it('ignores the answer to a save that a newer one replaced', () => {
    loaded(aState({ precision: 'wide' }));

    service.saveInstant({ enabled: true, precision: 'narrow' });
    const first = http.expectOne(URL);
    service.saveInstant({ enabled: true, precision: 'narrowest' });
    const second = http.expectOne(URL);
    first.flush(aState({ precision: 'narrow' }));

    expect(service.shown()?.precision).toBe('narrowest');
    second.flush(aState({ precision: 'narrowest' }));
    expect(service.shown()?.precision).toBe('narrowest');
  });

  it('reloads the sidebar counts when the switch changes, not when only the precision does', () => {
    loaded(aState({ enabled: true }));

    service.saveInstant({ enabled: true, precision: 'narrow' });
    http.expectOne(URL).flush(aState({ enabled: true, precision: 'narrow' }));
    expect(subscriptions.reloadCountsQuietly).toHaveBeenCalledTimes(0);

    service.saveInstant({ enabled: false, precision: 'narrow' });
    http.expectOne(URL).flush(aState({ enabled: false, precision: 'narrow' }));
    expect(subscriptions.reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('keeps the newer choice when an older save fails', () => {
    loaded(aState({ precision: 'wide' }));

    service.saveInstant({ enabled: true, precision: 'narrow' });
    service.saveInstant({ enabled: true, precision: 'widest' });
    const [first, second] = http.match(URL);
    first.flush(
      { type: 'about:blank', title: 'boom' },
      { status: 500, statusText: 'Server Error' },
    );

    expect(service.shown()?.precision).toBe('widest');
    expect(service.failure()).toBeNull();
    second.flush(aState({ precision: 'widest' }));
    expect(service.shown()?.precision).toBe('widest');
  });

  it('keeps the failure of a load', () => {
    service.load();
    http
      .expectOne(URL)
      .flush({ type: 'about:blank', title: 'down' }, { status: 503, statusText: 'Unavailable' });

    expect(service.shown()).toBeNull();
    expect(service.failure()?.title).toBe('down');
  });
});
