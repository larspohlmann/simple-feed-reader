import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { Dialog } from '@angular/cdk/dialog';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogComponent } from './admin-catalog.component';
import { CategoryFormDialogComponent } from './category-form-dialog.component';
import { FeedFormDialogComponent } from './feed-form-dialog.component';

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
  let ctrl: HttpTestingController;
  let dialogClosed: Subject<unknown>;
  const dialogOpen = jest.fn(() => ({ closed: dialogClosed }));

  // Mounts the component, provides the Dialog stub, and flushes the two
  // requests ngOnInit fires (catalog + bundled-catalog info) so every test
  // starts from a loaded list.
  function mountLoaded(payload = PAYLOAD) {
    TestBed.configureTestingModule({
      imports: [AdminCatalogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open: dialogOpen } },
      ],
    });
    const fixture = TestBed.createComponent(AdminCatalogComponent);
    ctrl = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/admin/catalog').flush(payload);
    ctrl
      .expectOne('https://api.test/api/admin/catalog/bundled')
      .flush({ available: true, categories: 13, feeds: 111 });
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    dialogClosed = new Subject<unknown>();
    dialogOpen.mockClear();
  });

  afterEach(() => ctrl.verify());

  it('lists categories and their feeds', () => {
    const fixture = mountLoaded();
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-category"]')).toHaveLength(
      1,
    );
    expect(fixture.nativeElement.querySelectorAll('[data-testid="admin-feed"]')).toHaveLength(1);
  });

  it('refreshes a favicon on demand', () => {
    const fixture = mountLoaded();
    fixture.nativeElement.querySelector('[data-testid="refresh-favicon"]').click();

    const req = ctrl.expectOne('https://api.test/api/admin/catalog/feeds/10/favicon');
    expect(req.request.method).toBe('POST');
    req.flush({ feed: { ...PAYLOAD.feeds[0], faviconFetchedAt: '2026-07-26T10:00:00+00:00' } });
  });

  it('imports the bundled document without transferring a file', () => {
    const fixture = mountLoaded();
    const button: HTMLButtonElement = fixture.nativeElement.querySelector(
      '[data-testid="import-bundled"]',
    );
    expect(button.textContent).toContain('111');

    button.click();

    const req = ctrl.expectOne('https://api.test/api/admin/catalog/import/bundled');
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

    ctrl.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);

    // A freshly imported catalog has no icons, so warming starts on its own —
    // this is what makes icons work without any deployment-specific step.
    ctrl
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 25, failed: 0, remaining: 86 });
    ctrl
      .expectOne('https://api.test/api/admin/catalog/favicons/warm')
      .flush({ warmed: 86, failed: 0, remaining: 0 });
  });

  it('posts a chosen document with the selected mode and reloads afterwards', async () => {
    const fixture = mountLoaded();
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

    const req = ctrl.expectOne('https://api.test/api/admin/catalog/import');
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
    ctrl.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
  });

  it('shows a message-only error banner, with no action button, when an import fails', () => {
    const fixture = mountLoaded();
    fixture.componentInstance.importBundled();
    ctrl
      .expectOne('https://api.test/api/admin/catalog/import/bundled')
      .flush(
        { type: 'about:blank', title: 'Malformed document', status: 422 },
        { status: 422, statusText: 'Unprocessable' },
      );
    fixture.detectChanges();

    const alerts = fixture.nativeElement.querySelectorAll('[role="alert"]');
    const importAlert = Array.from(alerts).find((el) =>
      (el as HTMLElement).textContent?.includes('Malformed document'),
    ) as HTMLElement | undefined;
    expect(importAlert).toBeDefined();
    expect(importAlert!.querySelector('button')).toBeNull();
  });

  it('upserts the category a closed dialog returns', () => {
    const fixture = mountLoaded();
    fixture.componentInstance.openCategoryDialog(null);
    expect(dialogOpen).toHaveBeenCalled();

    dialogClosed.next({
      id: 9,
      key: '',
      name: 'Fresh',
      icon: '',
      color: '#112233',
      position: 5,
      enabled: true,
      locked: false,
    });
    expect(fixture.componentInstance.categories().some((c) => c.id === 9)).toBe(true);
  });

  it('ignores a cancelled dialog', () => {
    const fixture = mountLoaded();
    const before = fixture.componentInstance.categories();
    fixture.componentInstance.openCategoryDialog(null);
    dialogClosed.next(undefined);
    expect(fixture.componentInstance.categories()).toEqual(before);
  });

  it('deletes a feed only after confirmation', () => {
    const fixture = mountLoaded();
    fixture.componentInstance.confirmDeleteFeed(fixture.componentInstance.feeds()[0]);
    ctrl.expectNone('https://api.test/api/admin/catalog/feeds/10');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/catalog/feeds/10').flush({});
    expect(fixture.componentInstance.feeds().length).toBe(0);
  });

  it('deletes a category only after confirmation', () => {
    const fixture = mountLoaded();
    fixture.componentInstance.confirmDeleteCategory(fixture.componentInstance.categories()[0]);
    ctrl.expectNone('https://api.test/api/admin/catalog/categories/1');

    dialogClosed.next(true);
    ctrl.expectOne('https://api.test/api/admin/catalog/categories/1').flush({});
    expect(fixture.componentInstance.categories().length).toBe(0);
  });

  it('opens the category and feed dialogs with the right data from their buttons', () => {
    const fixture = mountLoaded();
    const root: HTMLElement = fixture.nativeElement;

    root.querySelector<HTMLElement>('[data-testid="add-category"]')!.click();
    expect(dialogOpen).toHaveBeenLastCalledWith(
      CategoryFormDialogComponent,
      expect.objectContaining({ data: null }),
    );

    root.querySelector<HTMLElement>('[data-testid="category-edit"]')!.click();
    expect(dialogOpen).toHaveBeenLastCalledWith(
      CategoryFormDialogComponent,
      expect.objectContaining({ data: PAYLOAD.categories[0] }),
    );

    root.querySelector<HTMLElement>('[data-testid="add-feed"]')!.click();
    expect(dialogOpen).toHaveBeenLastCalledWith(
      FeedFormDialogComponent,
      expect.objectContaining({
        data: { feed: null, categories: PAYLOAD.categories, categoryId: 1 },
      }),
    );

    root.querySelector<HTMLElement>('[data-testid="feed-edit"]')!.click();
    expect(dialogOpen).toHaveBeenLastCalledWith(
      FeedFormDialogComponent,
      expect.objectContaining({
        data: { feed: PAYLOAD.feeds[0], categories: PAYLOAD.categories, categoryId: 1 },
      }),
    );
  });

  it('shows skeleton rows instead of a spinner while the catalog loads', () => {
    TestBed.configureTestingModule({
      imports: [AdminCatalogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open: dialogOpen } },
      ],
    });
    const fixture = TestBed.createComponent(AdminCatalogComponent);
    ctrl = TestBed.inject(HttpTestingController);
    fixture.detectChanges();

    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.querySelector('app-spinner')).toBeNull();

    ctrl.expectOne('https://api.test/api/admin/catalog').flush(PAYLOAD);
    ctrl
      .expectOne('https://api.test/api/admin/catalog/bundled')
      .flush({ available: true, categories: 13, feeds: 111 });
  });

  it('renders the import block and the catalog as two settings groups', () => {
    const fixture = mountLoaded();
    expect(fixture.nativeElement.querySelectorAll('app-settings-group').length).toBe(2);
  });
});
