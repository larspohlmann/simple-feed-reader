import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { MailHealthStore } from './mail-health.store';

const BASE = 'https://api.test';
const ERRORS_ENDPOINT = `${BASE}/api/admin/mail/errors`;

describe('MailHealthStore', () => {
  let store: MailHealthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    store = TestBed.inject(MailHealthStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('starts with an empty failure log', () => {
    expect(store.failures()).toEqual([]);
    expect(store.failureCount()).toBe(0);
  });

  it('refresh() GETs the mail errors endpoint and sets failures/failureCount', () => {
    store.refresh();

    const req = http.expectOne(ERRORS_ENDPOINT);
    expect(req.request.method).toBe('GET');
    req.flush({
      failures: [
        {
          kind: 'digest',
          recipient: 'a@example.test',
          error: 'SMTP is down',
          at: '2026-09-06T10:00:00Z',
        },
        {
          kind: 'test',
          recipient: 'boss@example.test',
          error: 'no_from_address',
          at: '2026-09-06T10:05:00Z',
        },
      ],
    });

    expect(store.failures().length).toBe(2);
    expect(store.failureCount()).toBe(2);
    expect(store.failures()[0].kind).toBe('digest');
  });

  it('collapses concurrent refreshes into a single in-flight request', () => {
    store.refresh();
    store.refresh();

    const req = http.expectOne(ERRORS_ENDPOINT); // expectOne fails if two were issued
    req.flush({ failures: [] });

    // Once the in-flight request settles, a later refresh issues a fresh GET.
    store.refresh();
    http.expectOne(ERRORS_ENDPOINT).flush({ failures: [] });
  });
});
