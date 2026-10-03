import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { profileRun, profileState } from '../../../testing/profile-settings';
import { ProfileSettingsService } from './profile-settings.service';

const ENDPOINT = '/api/me/ai/profile';

describe('ProfileSettingsService', () => {
  let service: ProfileSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    jest.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [
        ProfileSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    service = TestBed.inject(ProfileSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    service.ngOnDestroy();
    jest.useRealTimers();
    http.verify();
  });

  it('saves an instant change over the last-saved state', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(profileState({ keptCap: 15 }));

    service.saveInstant({ intervalHours: 168 });

    const request = http.expectOne((each) => each.method === 'PUT' && each.url === ENDPOINT);
    expect(request.request.body).toEqual({
      intervalHours: 168,
      connectionId: null,
      keptCap: 15,
      viewedCap: 80,
    });
    request.flush(profileState({ intervalHours: 168, keptCap: 15 }));
  });

  it('polls the current run every two seconds while it is active and reloads the state when it ends', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(profileState());
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));
    expect(service.runActive()).toBe(true);

    jest.advanceTimersByTime(2000);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(profileRun({ status: 'running', id: 9 }));
    jest.advanceTimersByTime(2000);
    http
      .expectOne(`${ENDPOINT}/runs/current`)
      .flush(profileRun({ status: 'completed', id: 9, outcome: 'generated' }));

    http.expectOne(ENDPOINT).flush(profileState({ profileText: 'Fresh profile.' }));
    expect(service.state()?.profileText).toBe('Fresh profile.');
    jest.advanceTimersByTime(4000);
    http.expectNone(`${ENDPOINT}/runs/current`);
  });

  it('keeps the typed draft when a finished run reloads the state', () => {
    service.load();
    http.expectOne(ENDPOINT).flush(profileState());
    service.setTypedField('keptCap', 12);
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));

    jest.advanceTimersByTime(2000);
    http
      .expectOne(`${ENDPOINT}/runs/current`)
      .flush(profileRun({ status: 'failed', id: 9, error: 'gone' }));
    http.expectOne(ENDPOINT).flush(profileState());

    expect(service.pending('keptCap')).toBe(12);
  });

  it('keeps polling after a failed status read', () => {
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));

    jest.advanceTimersByTime(2000);
    http
      .expectOne(`${ENDPOINT}/runs/current`)
      .flush('gateway', { status: 500, statusText: 'Server Error' });
    jest.advanceTimersByTime(2000);
    http.expectOne(`${ENDPOINT}/runs/current`).flush(profileRun({ status: 'running', id: 9 }));

    expect(service.pollFailure()).toBeNull();
    expect(service.polling()).toBe(true);
  });

  it('gives up after three failed status reads in a row', () => {
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));

    for (let attempt = 1; attempt <= 3; attempt++) {
      jest.advanceTimersByTime(2000);
      http
        .expectOne(`${ENDPOINT}/runs/current`)
        .flush('gateway', { status: 500, statusText: 'Server Error' });
    }
    jest.advanceTimersByTime(4000);

    http.expectNone(`${ENDPOINT}/runs/current`);
    expect(service.pollFailure()).not.toBeNull();
    expect(service.polling()).toBe(false);
  });

  it('reports a failed reload of the state after the run ends', () => {
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));

    jest.advanceTimersByTime(2000);
    http
      .expectOne(`${ENDPOINT}/runs/current`)
      .flush(profileRun({ status: 'completed', id: 9, outcome: 'generated' }));
    http.expectOne(ENDPOINT).flush('gateway', { status: 500, statusText: 'Server Error' });

    expect(service.failure()).not.toBeNull();
  });

  it('stops polling when it is destroyed', () => {
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(profileRun({ status: 'pending', id: 9 }));

    service.ngOnDestroy();
    jest.advanceTimersByTime(4000);

    http.expectNone(`${ENDPOINT}/runs/current`);
  });

  it('keeps a refused start as its own failure', () => {
    service.startRun();
    http
      .expectOne((each) => each.method === 'POST')
      .flush(
        {
          type: 'profile_connection_missing',
          title: 'x',
          status: 422,
          detail: 'Choose a connection.',
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );

    expect(service.startFailure()?.detail).toBe('Choose a connection.');
    expect(service.starting()).toBe(false);
    expect(service.failure()).toBeNull();
  });
});
