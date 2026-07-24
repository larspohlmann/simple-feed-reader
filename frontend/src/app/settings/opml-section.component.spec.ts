import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { OpmlSectionComponent } from './opml-section.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';

describe('OpmlSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
      ],
    });
    const f = TestBed.createComponent(OpmlSectionComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => {
    load.mockReset();
    // jsdom lacks these:
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = jest.fn(() => 'blob:x');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = jest.fn();
  });
  afterEach(() => ctrl.verify());

  it('exports OPML through HttpClient and triggers a download', () => {
    const c = mount().componentInstance;
    c.exportOpml();
    const req = ctrl.expectOne('https://api.test/api/opml/export');
    expect(req.request.method).toBe('GET');
    req.flush('<opml/>');
    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(c.exporting()).toBe(false);
  });

  it('imports pasted OPML, shows the result, reloads, and refreshes the new feeds', () => {
    const c = mount().componentInstance;
    c.text.set('<opml/>');
    c.importText();
    const req = ctrl.expectOne('https://api.test/api/opml/import');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe('<opml/>');
    req.flush({ imported: 3, alreadySubscribed: 1, invalid: 0, skippedOverLimit: 0 });
    expect(c.result()?.imported).toBe(3);
    expect(load).toHaveBeenCalled();
    // Imported feeds are due but empty until fetched, so a refresh is kicked off.
    const refresh = ctrl.expectOne('https://api.test/api/refresh');
    expect(refresh.request.method).toBe('POST');
    refresh.flush({
      status: 'completed',
      total: 3,
      fetched: 3,
      notModified: 0,
      failed: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
  });

  it('does not refresh when nothing new was imported', () => {
    const c = mount().componentInstance;
    c.text.set('<opml/>');
    c.importText();
    ctrl
      .expectOne('https://api.test/api/opml/import')
      .flush({ imported: 0, alreadySubscribed: 2, invalid: 0, skippedOverLimit: 0 });
    ctrl.expectNone('https://api.test/api/refresh');
  });

  it('does not import an empty body', () => {
    const c = mount().componentInstance;
    c.text.set('   ');
    c.importText();
    ctrl.expectNone('https://api.test/api/opml/import');
  });
});
