import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { FileDropComponent } from '../../shared/file-drop/file-drop.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { OpmlSectionComponent } from './opml-section.component';
import { Subject } from 'rxjs';
import { Dialog } from '@angular/cdk/dialog';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { RefreshService } from '../../reader/state/refresh.service';
import { OpmlImportDialogComponent } from './opml-import-dialog.component';
import { OpmlImportResult } from '../settings.models';
import { Problem } from '../../core/problem';

describe('OpmlSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();
  const loadTags = jest.fn();
  const refreshRun = jest.fn();
  const open = jest.fn();
  let closed: Subject<OpmlImportResult | undefined>;
  const preview = { parentTagName: null, feeds: [], remainingSlots: 5 };

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
        { provide: TagsStore, useValue: { load: loadTags } },
        { provide: RefreshService, useValue: { run: refreshRun } },
        { provide: Dialog, useValue: { open } },
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

  async function pick(fixture: ReturnType<typeof mount>, contents: string): Promise<void> {
    await pickFile(fixture, { text: () => Promise.resolve(contents) } as File);
  }

  async function pickFile(fixture: ReturnType<typeof mount>, file: File): Promise<void> {
    const drop = fixture.debugElement.query(By.directive(FileDropComponent));
    (drop.componentInstance as FileDropComponent).picked.emit(file);
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();
  }

  function flushPreview(): void {
    ctrl.expectOne('https://api.test/api/opml/preview').flush(preview);
  }

  beforeEach(() => {
    load.mockReset();
    loadTags.mockReset();
    refreshRun.mockReset();
    open.mockReset();
    closed = new Subject();
    open.mockReturnValue({ closed });
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

  it('has no paste box', () => {
    expect((mount().nativeElement as HTMLElement).querySelector('textarea')).toBeNull();
  });

  it('previews the chosen file and opens the dialog with it', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    const request = ctrl.expectOne('https://api.test/api/opml/preview');
    expect(request.request.body).toEqual({ opml: '<opml/>', parentTagName: null, folderNames: {} });
    request.flush(preview);
    expect(open).toHaveBeenCalledTimes(1);
    expect(open.mock.calls[0][0]).toBe(OpmlImportDialogComponent);
    expect(open.mock.calls[0][1].data).toEqual({ opml: '<opml/>', preview });
    expect(open.mock.calls[0][1].panelClass).toBe('app-dialog');
  });

  it('says it is reading while the preview runs', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Reading the file');
    flushPreview();
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('Reading the file');
  });

  it('opens one dialog when a second file is picked during the preview', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    await pick(fixture, '<opml/>');
    flushPreview();
    expect(open).toHaveBeenCalledTimes(1);
  });

  it('shows the reason and opens nothing when the file cannot be read', async () => {
    const fixture = mount();
    await pick(fixture, 'nope');
    ctrl.expectOne('https://api.test/api/opml/preview').flush(
      {
        type: 'about:blank',
        title: 'Unprocessable',
        status: 422,
        detail: 'Not a well-formed OPML 2.0 document.',
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'Not a well-formed OPML 2.0 document.',
    );
    expect(open).not.toHaveBeenCalled();
  });

  it('says the file could not be read when reading it fails', async () => {
    const fixture = mount();
    await pickFile(fixture, { text: () => Promise.reject(new Error('gone')) } as File);
    await Promise.resolve();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('app-error-banner')?.textContent).toContain(
      'This file could not be read.',
    );
    expect(element.textContent).not.toContain('Reading the file');
    expect(open).not.toHaveBeenCalled();
  });

  it('clears the last result when a new file is picked', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    flushPreview();
    closed.next({ imported: 2, alreadySubscribed: 1, invalid: 0, skippedOverLimit: 0 });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Imported 2');
    await pick(fixture, '<opml/>');
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('Imported 2');
    flushPreview();
  });

  it('shows the result and reloads when the dialog imported', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    flushPreview();
    closed.next({ imported: 2, alreadySubscribed: 1, invalid: 0, skippedOverLimit: 0 });
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).querySelector('.result')?.textContent ?? '';
    expect(text).toContain('2');
    expect(text).toContain('1');
    expect(text).not.toContain('limit');
    expect(load).toHaveBeenCalled();
    expect(loadTags).toHaveBeenCalled();
    expect(refreshRun).toHaveBeenCalledTimes(1);
    expect(refreshRun.mock.calls[0][1]).toEqual({ kind: 'neverFetched' });
  });

  it('starts no refresh when nothing was imported', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    flushPreview();
    closed.next({ imported: 0, alreadySubscribed: 3, invalid: 0, skippedOverLimit: 0 });
    expect(refreshRun).not.toHaveBeenCalled();
  });

  it('changes nothing when the dialog is cancelled', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    flushPreview();
    closed.next(undefined);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.result')).toBeNull();
    expect(load).not.toHaveBeenCalled();
    expect(loadTags).not.toHaveBeenCalled();
  });

  it('opml section previews a dropped file', async () => {
    const fixture = mount();
    await pick(fixture, '<opml/>');
    const request = ctrl.expectOne('https://api.test/api/opml/preview');
    expect(request.request.body).toEqual({ opml: '<opml/>', parentTagName: null, folderNames: {} });
    request.flush(preview);
    expect(open).toHaveBeenCalledTimes(1);
    expect(open.mock.calls[0][1].data).toEqual({ opml: '<opml/>', preview });
  });
});
