// src/app/core/http-locale-writer.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from './api';
import { HttpLocaleWriter } from './http-locale-writer';

describe('HttpLocaleWriter', () => {
  let writer: HttpLocaleWriter;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    writer = TestBed.inject(HttpLocaleWriter);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('PATCHes the locale to /api/me and resolves true on success', async () => {
    const result = firstValueFrom(writer.write('de'));
    const req = ctrl.expectOne('https://api.test/api/me');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ locale: 'de' });
    req.flush({ locale: 'de' });
    await expect(result).resolves.toBe(true);
  });

  it('resolves false, never errors, when the account rejects the write', async () => {
    const result = firstValueFrom(writer.write('de'));
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ title: 'Server error' }, { status: 500, statusText: 'Server Error' });
    await expect(result).resolves.toBe(false);
  });
});
