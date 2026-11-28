import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { EXPORT_FORMATS } from './export-formats';
import { ExportApi } from './export.api';

describe('ExportApi', () => {
  let http: HttpTestingController;
  let api: ExportApi;
  const [bookmarks, googleReader] = EXPORT_FORMATS;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(ExportApi);
  });

  afterEach(() => http.verify());

  it('sends the label and the ascending order for an oldest-first labelled list', () => {
    api
      .download(googleReader, { collection: 'kept', label: 12, title: 'Kept' }, 'oldest')
      .subscribe();

    const request = http.expectOne((candidate) =>
      candidate.urlWithParams.endsWith('/api/exports/google-reader?view=kept&label=12&order=asc'),
    );
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob());
  });

  it('sends neither label nor order for a whole newest-first list', () => {
    api
      .download(bookmarks, { collection: 'favorites', label: null, title: 'Favorites' }, 'newest')
      .subscribe();

    const request = http.expectOne((candidate) => candidate.url.endsWith('/api/exports/bookmarks'));
    expect(request.request.urlWithParams.endsWith('/api/exports/bookmarks?view=favorites')).toBe(
      true,
    );
    request.flush(new Blob());
  });

  it('sends unlabeled as the label', () => {
    api
      .download(bookmarks, { collection: 'kept', label: 'unlabeled', title: 'Kept' }, 'newest')
      .subscribe();

    const request = http.expectOne((candidate) => candidate.url.endsWith('/api/exports/bookmarks'));
    expect(request.request.params.get('label')).toBe('unlabeled');
    request.flush(new Blob());
  });
});
