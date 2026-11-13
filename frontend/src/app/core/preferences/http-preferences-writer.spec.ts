import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../api';
import { HttpPreferencesWriter } from './http-preferences-writer';

describe('HttpPreferencesWriter', () => {
  let http: HttpTestingController;
  let writer: HttpPreferencesWriter;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    writer = TestBed.inject(HttpPreferencesWriter);
  });

  afterEach(() => http.verify());

  it('patches the one-key change and resolves true', () => {
    let result: boolean | null = null;

    writer.write({ handedness: 'left' }).subscribe((ok) => (result = ok));
    const request = http.expectOne('https://api.test/api/me/preferences');
    request.flush({});

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ handedness: 'left' });
    expect(result).toBe(true);
  });

  it('resolves false on a rejected change', () => {
    let result: boolean | null = null;

    writer.write({ trendingEnabled: false }).subscribe((ok) => (result = ok));
    http
      .expectOne('https://api.test/api/me/preferences')
      .flush({}, { status: 422, statusText: 'Unprocessable Entity' });

    expect(result).toBe(false);
  });
});
