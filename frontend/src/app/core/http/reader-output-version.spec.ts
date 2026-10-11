import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  READER_VERSION_HEADER,
  ReaderOutputVersion,
  readerOutputVersionInterceptor,
} from './reader-output-version';

describe('readerOutputVersionInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;
  let version: ReaderOutputVersion;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([readerOutputVersionInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
    version = TestBed.inject(ReaderOutputVersion);
  });

  afterEach(() => backend.verify());

  it('records the version a response names', () => {
    http.get('/api/entries').subscribe();
    backend.expectOne('/api/entries').flush([], { headers: { [READER_VERSION_HEADER]: 'v2' } });
    expect(version.current()).toBe('v2');
  });

  it('keeps the last known version when a response names none', () => {
    version.current.set('v1');
    http.get('/api/entries').subscribe();
    backend.expectOne('/api/entries').flush([]);
    expect(version.current()).toBe('v1');
  });
});
