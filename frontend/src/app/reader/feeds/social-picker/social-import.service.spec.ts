import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { ImportCandidates, SocialImportService } from './social-import.service';

const BASE = 'https://api.test';
const CANDIDATES = `${BASE}/api/me/social-accounts/4/import-candidates`;
const IMPORT = `${BASE}/api/me/social-accounts/4/import`;
const EMPTY: ImportCandidates = {
  follows: [],
  lists: [],
  customFeeds: [],
  tags: [],
  truncated: false,
};

describe('SocialImportService', () => {
  let http: HttpTestingController;
  let service: SocialImportService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        SocialImportService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    service = TestBed.inject(SocialImportService);
  });

  afterEach(() => http.verify());

  it('loads the candidates of one account', () => {
    service.load(4);
    expect(service.loading()).toBe(true);

    http.expectOne(CANDIDATES).flush(EMPTY);

    expect(service.candidates()).toEqual(EMPTY);
    expect(service.loading()).toBe(false);
  });

  it('keeps the problem message when loading fails and clears it on the next load', () => {
    service.load(4);
    http.expectOne(CANDIDATES).flush(
      {
        type: 'social_network_unreachable',
        title: 'x',
        status: 502,
        detail: 'Bluesky could not be reached.',
      },
      { status: 502, statusText: 'Bad Gateway' },
    );
    expect(service.failure()).toBe('Bluesky could not be reached.');

    service.load(4);
    expect(service.failure()).toBeNull();
    http.expectOne(CANDIDATES).flush(EMPTY);
  });

  it('posts the selection and reports importing until the answer arrives', () => {
    const result = {
      imported: 1,
      alreadySubscribed: 0,
      invalid: 0,
      skippedOverLimit: 0,
      removed: 0,
    };
    let answered: unknown = null;

    service
      .importSelection(4, {
        subscribe: [{ identity: 'did:plc:bob', title: 'Bob' }],
        unsubscribe: [],
        collections: [],
      })
      .subscribe((answer) => (answered = answer));
    expect(service.importing()).toBe(true);
    const post = http.expectOne(IMPORT);
    expect(post.request.method).toBe('POST');
    post.flush(result);

    expect(answered).toEqual(result);
    expect(service.importing()).toBe(false);
  });
});
