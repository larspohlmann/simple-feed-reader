import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { BackupArchive, InvalidBackupArchiveError, openBackupArchive } from './backup-archive';
import { BackupRestoreRun } from './backup-restore-run';
import { BackupSectionComponent } from './backup-section.component';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { RefreshService } from '../../reader/state/refresh.service';

jest.mock('./backup-archive', () => ({
  ...jest.requireActual('./backup-archive'),
  openBackupArchive: jest.fn(),
}));

/** `fixture.whenStable()` tracks zone-scheduled tasks, but `onFile()` and
 *  `restore()` chain several `await`s (open the archive, read its foundation,
 *  subscribe to the HTTP call) before anything reaches `HttpTestingController`
 *  -- a macrotask tick reliably drains all of them, where `whenStable()` alone
 *  does not. */
function flushPromises(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('BackupSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();
  const refreshRun = jest.fn();

  const previewResponse = {
    backup: {
      createdAt: '2026-01-01T00:00:00Z',
      sourceUrl: 'https://old.example',
      sourceEmail: 'them@x.test',
    },
    toLoad: { tags: 2, feeds: 3, subscriptions: 3, entries: 40, entryStates: 40, savedSearches: 5 },
    toDelete: { tags: 1, subscriptions: 1, entryStates: 5, recommendationRuns: 0 },
  };

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
        { provide: RefreshService, useValue: { run: refreshRun } },
      ],
    });
    const fixture = TestBed.createComponent(BackupSectionComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  function fakeArchive(overrides: Partial<BackupArchive> = {}): BackupArchive {
    return {
      entryPartCount: 0,
      foundation: jest.fn().mockResolvedValue(new Blob(['foundation'])),
      entryPart: jest.fn((index: number) => Promise.resolve(new Blob([`part-${index}`]))),
      close: jest.fn().mockResolvedValue(undefined),
      ...overrides,
    };
  }

  async function chooseFile(
    fixture: ReturnType<typeof mount>,
    archive: BackupArchive = fakeArchive(),
    name = 'account-backup.zip',
  ): Promise<{ file: File; archive: BackupArchive }> {
    (openBackupArchive as jest.Mock).mockResolvedValue(archive);
    const file = new File(['zip contents'], name, { type: 'application/zip' });
    fixture.componentInstance.onFile(file);
    await flushPromises();
    return { file, archive };
  }

  beforeEach(() => {
    load.mockReset();
    refreshRun.mockReset();
    (openBackupArchive as jest.Mock).mockReset();
    // jsdom lacks these:
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = jest.fn(() => 'blob:x');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = jest.fn();
    jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
  });
  afterEach(() => {
    ctrl.verify();
    jest.restoreAllMocks();
  });

  it('opens the archive, previews with its foundation part and renders the report counts', async () => {
    const fixture = mount();
    const { archive } = await chooseFile(fixture);
    const testRequest = ctrl.expectOne('https://api.test/api/account/restore/preview');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toBe(await archive.foundation());
    testRequest.flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('3');
    expect(text).toContain('40');
    expect(text).toContain('them@x.test');
    expect(text).toContain('https://old.example');
    expect(text).toContain('5');
  });

  it('closes the previous archive when another file is chosen', async () => {
    const fixture = mount();
    const { archive: first } = await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    expect(first.close).toHaveBeenCalledTimes(1);
  });

  const zeroLoaded = {
    loaded: { tags: 0, savedSearches: 0, feeds: 0, subscriptions: 0, entries: 0, entryStates: 0 },
  };

  it('discards a superseded file open and closes its archive, applying only the later pick', async () => {
    const fixture = mount();
    const first = fakeArchive();
    const second = fakeArchive();
    let resolveFirst!: (a: BackupArchive) => void;
    (openBackupArchive as jest.Mock)
      .mockReturnValueOnce(new Promise<BackupArchive>((resolve) => (resolveFirst = resolve)))
      .mockResolvedValueOnce(second);

    fixture.componentInstance.onFile(new File(['a'], 'a.zip', { type: 'application/zip' }));
    fixture.componentInstance.onFile(new File(['b'], 'b.zip', { type: 'application/zip' }));
    await flushPromises();

    // Only the later pick reached the server.
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    resolveFirst(first);
    await flushPromises();

    expect(first.close).toHaveBeenCalledTimes(1);
    expect(second.close).not.toHaveBeenCalled();
    expect(first.foundation).not.toHaveBeenCalled();
  });

  it('ignores a newly chosen file while a restore is running', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();
    expect(component.restoring()).toBe(true);
    const chosen = component.file();

    component.onFile(new File(['other'], 'other.zip', { type: 'application/zip' }));
    expect(component.file()).toBe(chosen);
    expect(openBackupArchive).toHaveBeenCalledTimes(1);

    ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE').flush(zeroLoaded);
    await flushPromises();
    ctrl.expectOne('https://api.test/api/account/restore/entries').flush(zeroLoaded);
    await flushPromises();
  });

  it('clears restoring and surfaces an error when the run throws unexpectedly', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    jest.spyOn(TestBed.inject(BackupRestoreRun), 'run').mockRejectedValue(new Error('boom'));
    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();
    fixture.detectChanges();

    expect(component.restoring()).toBe(false);
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('The restore stopped unexpectedly');
  });

  it('clears the failedOnce banner once a continued run completes', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();
    ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE').flush(zeroLoaded);
    await flushPromises();
    ctrl
      .expectOne('https://api.test/api/account/restore/entries')
      .flush(
        { type: 'about:blank', title: 'Unprocessable', status: 422 },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    await flushPromises();
    fixture.detectChanges();
    expect(component.failedOnce()).toBe(true);

    component.continueRestore();
    await flushPromises();
    ctrl.expectOne('https://api.test/api/account/restore/entries').flush(zeroLoaded);
    await flushPromises();
    fixture.detectChanges();

    expect(component.failedOnce()).toBe(false);
    expect(component.error()).toBeNull();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).not.toContain('Run the restore again with the same file');
  });

  it('shows the old-format message and makes no API call for a .json.gz file', async () => {
    const fixture = mount();
    const file = new File(['gz'], 'account-backup.json.gz', { type: 'application/gzip' });
    fixture.componentInstance.onFile(file);
    await flushPromises();
    fixture.detectChanges();

    expect(openBackupArchive).not.toHaveBeenCalled();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('This backup uses an old format.');
  });

  it('shows the invalid-archive message when the zip fails verification, with no preview call', async () => {
    const fixture = mount();
    (openBackupArchive as jest.Mock).mockRejectedValue(
      new InvalidBackupArchiveError('The archive has no foundation part.'),
    );
    fixture.componentInstance.onFile(new File(['zip'], 'backup.zip', { type: 'application/zip' }));
    await flushPromises();
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('This file is not a complete backup archive.');
  });

  it('keeps the restore button disabled until REPLACE is typed exactly', async () => {
    const fixture = mount();
    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    expect(component.canRestore()).toBe(false);

    component.typed.set('replace');
    expect(component.canRestore()).toBe(false);

    component.typed.set('REPLACE ');
    expect(component.canRestore()).toBe(false);

    component.typed.set('REPLACE');
    expect(component.canRestore()).toBe(true);
  });

  it('runs the restore part by part, shows the summed counts and refreshes', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    const startTestRequest = ctrl.expectOne(
      'https://api.test/api/account/restore/start?confirm=REPLACE',
    );
    startTestRequest.flush({
      loaded: { tags: 1, savedSearches: 0, feeds: 0, subscriptions: 0, entries: 0, entryStates: 0 },
    });
    await flushPromises();

    const partTestRequest = ctrl.expectOne('https://api.test/api/account/restore/entries');
    partTestRequest.flush({
      loaded: {
        tags: 0,
        savedSearches: 2,
        feeds: 1,
        subscriptions: 1,
        entries: 10,
        entryStates: 10,
      },
    });
    await flushPromises();
    fixture.detectChanges();

    expect(archive.close).toHaveBeenCalledTimes(1);
    expect(component.result()?.loaded).toEqual({
      tags: 1,
      savedSearches: 2,
      feeds: 1,
      subscriptions: 1,
      entries: 10,
      entryStates: 10,
    });
    expect(component.file()).toBeNull();
    expect(component.typed()).toBe('');
    expect(component.preview()).toBeNull();
    expect(load).toHaveBeenCalled();
    expect(refreshRun).toHaveBeenCalled();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('2');
  });

  it('stops on a part failure, keeps the file selected and shows Continue', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE').flush({
      loaded: {
        tags: 1,
        savedSearches: 0,
        feeds: 0,
        subscriptions: 0,
        entries: 0,
        entryStates: 0,
      },
    });
    await flushPromises();

    ctrl
      .expectOne('https://api.test/api/account/restore/entries')
      .flush(
        { type: 'about:blank', title: 'Unprocessable', status: 422 },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    await flushPromises();
    fixture.detectChanges();

    expect(component.failedOnce()).toBe(true);
    expect(component.canContinue()).toBe(true);
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Run the restore again with the same file');
    expect(text).toContain('Continue');
  });

  it('keeps failedOnce false when the start request is refused before the wipe', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE').flush(
      {
        type: 'backup_does_not_fit',
        title: 'The backup does not fit this account',
        detail: 'The backup holds 300 subscriptions; this account allows 200.',
        status: 409,
      },
      { status: 409, statusText: 'Conflict' },
    );
    await flushPromises();
    fixture.detectChanges();

    expect(component.failedOnce()).toBe(false);
    expect(component.canContinue()).toBe(false);
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('this account allows 200');
    expect(text).not.toContain('Continue');
  });

  it('continueRestore() calls run.continue() and completes from the failed part', async () => {
    const archive = fakeArchive({ entryPartCount: 1 });
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE').flush({
      loaded: {
        tags: 1,
        savedSearches: 0,
        feeds: 0,
        subscriptions: 0,
        entries: 0,
        entryStates: 0,
      },
    });
    await flushPromises();

    ctrl
      .expectOne('https://api.test/api/account/restore/entries')
      .flush(
        { type: 'about:blank', title: 'Unprocessable', status: 422 },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    await flushPromises();

    const restoreRun = TestBed.inject(BackupRestoreRun);
    const continueSpy = jest.spyOn(restoreRun, 'continue');

    component.continueRestore();
    await flushPromises();
    expect(continueSpy).toHaveBeenCalled();

    ctrl.expectOne('https://api.test/api/account/restore/entries').flush({
      loaded: { tags: 0, savedSearches: 1, feeds: 1, subscriptions: 1, entries: 5, entryStates: 5 },
    });
    await flushPromises();
    fixture.detectChanges();

    expect(component.result()?.loaded.entries).toBe(5);
  });

  const nginxTooLargePage = '<html><title>413 Request Entity Too Large</title></html>';
  const tooLarge = { status: 413, statusText: 'Request Entity Too Large' };

  /**
   * The web server refuses an oversized file with an HTML page, not
   * problem+json -- the generic fallback rendered that as "Something went
   * wrong", naming neither cause nor remedy (#458).
   */
  it('names the size limit when the preview upload is refused as too large', async () => {
    const fixture = mount();
    await chooseFile(fixture);

    ctrl
      .expectOne('https://api.test/api/account/restore/preview')
      .flush(nginxTooLargePage, tooLarge);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('larger than this server accepts');
    expect(text).not.toContain('Something went wrong');
    expect(component.preview()).toBeNull();
    expect(component.failedOnce()).toBe(false);
  });

  /** The same refusal at the pre-wipe start step -- reachable when the cap
   *  changes between the two requests, and the case that must NOT raise the
   *  data-loss banner, since the wipe never ran. */
  it('reports a refused restore start without the data-loss banner', async () => {
    const archive = fakeArchive();
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl
      .expectOne('https://api.test/api/account/restore/start?confirm=REPLACE')
      .flush(nginxTooLargePage, tooLarge);
    await flushPromises();
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('larger than this server accepts');
    expect(component.failedOnce()).toBe(false);
  });

  /** A dropped connection is the one case where nobody knows what happened. */
  it('keeps the data-loss banner for a start request that never got an answer', async () => {
    const archive = fakeArchive();
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl
      .expectOne('https://api.test/api/account/restore/start?confirm=REPLACE')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    await flushPromises();
    fixture.detectChanges();

    expect(component.failedOnce()).toBe(true);
  });

  /** A gateway timeout after the wipe has no typed exception behind it --
   *  nginx or php-fpm died first -- so it must not read as a clean refusal. */
  it('keeps the data-loss banner for a 504 on the start request', async () => {
    const archive = fakeArchive();
    const fixture = mount();
    await chooseFile(fixture, archive);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.typed.set('REPLACE');
    component.restore();
    await flushPromises();

    ctrl
      .expectOne('https://api.test/api/account/restore/start?confirm=REPLACE')
      .flush(
        { type: 'about:blank', title: 'Gateway Timeout', status: 504 },
        { status: 504, statusText: 'Gateway Timeout' },
      );
    await flushPromises();
    fixture.detectChanges();

    expect(component.failedOnce()).toBe(true);
  });

  it('shows the preview problem detail and clears any stale preview', async () => {
    const fixture = mount();
    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(
      {
        type: 'about:blank',
        title: 'Backup does not fit',
        detail: 'This backup is for a different plan.',
        status: 409,
      },
      { status: 409, statusText: 'Conflict' },
    );
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    expect(component.preview()).toBeNull();
    const banner = (fixture.nativeElement as HTMLElement).querySelector('app-error-banner');
    expect(banner).not.toBeNull();
    expect((fixture.nativeElement as HTMLElement).textContent ?? '').toContain(
      'This backup is for a different plan.',
    );
  });

  it('renders the "—" fallback when the backup has no source email', async () => {
    const fixture = mount();
    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush({
      ...previewResponse,
      backup: { ...previewResponse.backup, sourceEmail: null },
    });
    await flushPromises();
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('—');
    expect(text).not.toContain('null');
  });

  it('downloads the backup blob under the filename the server names in Content-Disposition', () => {
    const fixture = mount();
    const appendSpy = jest.spyOn(document.body, 'appendChild');

    const component = fixture.componentInstance;
    component.downloadBackup();
    const testRequest = ctrl.expectOne('https://api.test/api/account/backup');
    expect(testRequest.request.method).toBe('GET');
    expect(testRequest.request.responseType).toBe('blob');
    testRequest.flush(new Blob(['gzipped']), {
      headers: {
        'Content-Disposition': 'attachment; filename="simplefeedreader-dev-them-at-x-20260817.zip"',
      },
    });

    expect(URL.createObjectURL).toHaveBeenCalled();
    const anchor = appendSpy.mock.calls[0][0] as HTMLAnchorElement;
    expect(anchor.download).toBe('simplefeedreader-dev-them-at-x-20260817.zip');
    expect(component.exporting()).toBe(false);
    appendSpy.mockRestore();
  });

  it('falls back to a static filename when Content-Disposition is missing', () => {
    const fixture = mount();
    const appendSpy = jest.spyOn(document.body, 'appendChild');

    const component = fixture.componentInstance;
    component.downloadBackup();
    const testRequest = ctrl.expectOne('https://api.test/api/account/backup');
    testRequest.flush(new Blob(['gzipped']));

    expect(URL.createObjectURL).toHaveBeenCalled();
    const anchor = appendSpy.mock.calls[0][0] as HTMLAnchorElement;
    expect(anchor.download).toBe('account-backup.zip');
    appendSpy.mockRestore();
  });

  it('shows the server detail when the backup download returns problem+json as a Blob', async () => {
    const fixture = mount();

    const component = fixture.componentInstance;
    component.downloadBackup();
    const body = new Blob([], { type: 'application/problem+json' });
    body.text = jest.fn().mockResolvedValue(
      JSON.stringify({
        type: 'backup_export_failed',
        title: 'The backup could not be created',
        status: 500,
        detail: 'The server could not read one account record.',
      }),
    );
    ctrl
      .expectOne('https://api.test/api/account/backup')
      .flush(body, { status: 500, statusText: 'Server Error' });
    await flushPromises();
    fixture.detectChanges();

    expect(component.exporting()).toBe(false);
    expect(component.exportError()?.detail).toBe('The server could not read one account record.');
  });

  it('downloads the safety-net OPML export through the shared saveAs helper', async () => {
    const fixture = mount();
    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.exportSafetyNetOpml();
    const testRequest = ctrl.expectOne('https://api.test/api/opml/export');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush('<opml/>');

    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(component.safetyNetExporting()).toBe(false);
  });

  it('shows an error rather than failing silently when the safety-net export fails', async () => {
    const fixture = mount();
    await chooseFile(fixture);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    await flushPromises();
    fixture.detectChanges();

    const component = fixture.componentInstance;
    component.exportSafetyNetOpml();
    ctrl
      .expectOne('https://api.test/api/opml/export')
      .flush('server error', { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(component.safetyNetExporting()).toBe(false);
    expect(component.safetyNetError()).not.toBeNull();
    expect((fixture.nativeElement as HTMLElement).querySelectorAll('app-error-banner').length).toBe(
      1,
    );
  });
});
