import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogComponent } from './admin-catalog.component';

const PAYLOAD = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      position: 0,
      enabled: true,
      locked: false,
    },
  ],
  feeds: [
    {
      id: 10,
      categoryId: 1,
      title: 'The Verge',
      url: 'https://www.theverge.com/rss/index.xml',
      siteUrl: null,
      description: null,
      sourceFormat: 'xml',
      position: 0,
      enabled: true,
      locked: false,
      faviconFetchedAt: null,
      faviconFailedAt: null,
    },
  ],
};

describe('AdminCatalogComponent', () => {
  let fixture: ComponentFixture<AdminCatalogComponent>;
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdminCatalogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AdminCatalogComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
    http
      .expectOne('https://api.test/api/admin/catalog/bundled')
      .flush({ available: true, categories: 13, feeds: 111 });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('lists categories and their feeds', () => {
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-category"]')).toHaveLength(
      1,
    );
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-feed"]')).toHaveLength(1);
  });

  it('refreshes a favicon on demand', () => {
    fixture.nativeElement.querySelector('[data-testid="refresh-favicon"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/feeds/10/favicon');
    expect(req.request.method).toBe('POST');
    req.flush({ feed: { ...PAYLOAD.feeds[0], faviconFetchedAt: '2026-07-26T10:00:00+00:00' } });
  });

  it('marks a locked feed as such and can toggle it', () => {
    const row = fixture.nativeElement.querySelector('[data-testid="admin-feed"]');
    const lock: HTMLInputElement = row.querySelector('[data-testid="feed-locked"]');
    expect(lock.checked).toBe(false);

    lock.click();
    row.querySelector('[data-testid="feed-save"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/feeds/10');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body.locked).toBe(true);
    req.flush({ feed: { ...PAYLOAD.feeds[0], locked: true } });
  });

  it('imports the bundled document without transferring a file', () => {
    const button: HTMLButtonElement = fixture.nativeElement.querySelector(
      '[data-testid="import-bundled"]',
    );
    expect(button.textContent).toContain('111');

    button.click();

    const req = http.expectOne('https://api.test/api/admin/catalog/import/bundled');
    expect(req.request.body).toEqual({ mode: 'merge' });
    req.flush({
      categoriesCreated: 13,
      categoriesUpdated: 0,
      categoriesRemoved: 0,
      feedsCreated: 111,
      feedsUpdated: 0,
      feedsRemoved: 0,
      lockedSkipped: 0,
    });

    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);

    // A freshly imported catalog has no icons, so warming starts on its own —
    // this is what makes icons work without any deployment-specific step.
    http
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 25, failed: 0, remaining: 86 });
    http
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 86, failed: 0, remaining: 0 });
  });

  it('posts a chosen document with the selected mode and reloads afterwards', async () => {
    const document =
      '<opml version="2.0"><head/><body>' +
      '<outline text="Technology" key="technology" icon="memory" color="#3b82f6"/>' +
      '</body></opml>';
    const file = new File([document], 'catalog.opml', { type: 'text/x-opml' });

    const input: HTMLInputElement = fixture.nativeElement.querySelector(
      '[data-testid="import-file"]',
    );
    Object.defineProperty(input, 'files', { value: [file] });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    const mode: HTMLSelectElement = fixture.nativeElement.querySelector(
      '[data-testid="import-mode"]',
    );
    mode.value = 'replace';
    mode.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    fixture.nativeElement.querySelector('[data-testid="import-run"]').click();

    const req = http.expectOne('https://api.test/api/admin/catalog/import');
    expect(req.request.body).toEqual({ mode: 'replace', document });
    req.flush({
      categoriesCreated: 0,
      categoriesUpdated: 0,
      categoriesRemoved: 1,
      feedsCreated: 0,
      feedsUpdated: 0,
      feedsRemoved: 1,
      lockedSkipped: 0,
    });

    // The lists are stale after an import, so the component refetches them.
    http.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
  });
});
