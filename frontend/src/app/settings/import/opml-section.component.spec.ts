import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { OpmlSectionComponent } from './opml-section.component';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { Problem } from '../../core/problem';

describe('OpmlSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
      ],
    });
    const fixture = TestBed.createComponent(OpmlSectionComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  function renderWithImportError(
    problem: Record<string, unknown> = { type: 'about:blank', title: 'Import failed', status: 400 },
  ): HTMLElement {
    const fixture = mount();
    fixture.componentInstance.importError.set(problem as unknown as Problem);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  beforeEach(() => {
    load.mockReset();
    // jsdom lacks these:
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = jest.fn(() => 'blob:x');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = jest.fn();
  });
  afterEach(() => ctrl.verify());

  it('exports OPML through HttpClient and triggers a download', () => {
    const component = mount().componentInstance;
    component.exportOpml();
    const testRequest = ctrl.expectOne('https://api.test/api/opml/export');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush('<opml/>');
    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(component.exporting()).toBe(false);
  });

  it('reports a failed import through the shared error banner', () => {
    const element = renderWithImportError();
    const banner = element.querySelector('app-error-banner');
    expect(banner).not.toBeNull();
    expect(element.querySelector('p.error')).toBeNull();
  });

  it('shows the server-provided detail in the failed-import banner', () => {
    const element = renderWithImportError({
      type: 'about:blank',
      title: 'Invalid OPML',
      detail: 'Line 4: unexpected element <foo>.',
      status: 400,
    });
    expect(element.textContent).toContain('Line 4: unexpected element <foo>.');
  });

  it('reports a failed export through the shared error banner', () => {
    const fixture = mount();
    fixture.componentInstance.exportOpml();
    ctrl
      .expectOne('https://api.test/api/opml/export')
      .flush('server error', { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('app-error-banner')).not.toBeNull();
  });
});
