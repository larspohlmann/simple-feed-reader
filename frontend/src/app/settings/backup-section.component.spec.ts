import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { BackupSectionComponent } from './backup-section.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { RefreshService } from '../reader/refresh.service';

describe('BackupSectionComponent', () => {
  let ctrl: HttpTestingController;
  const load = jest.fn();
  const run = jest.fn();

  const previewResponse = {
    backup: { createdAt: '2026-01-01T00:00:00Z', sourceUrl: null, sourceEmail: 'them@x.test' },
    toLoad: { tags: 2, feeds: 3, subscriptions: 3, entries: 40, entryStates: 40 },
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
        { provide: RefreshService, useValue: { run } },
      ],
    });
    const f = TestBed.createComponent(BackupSectionComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  function chooseFile(f: ReturnType<typeof mount>): File {
    const file = new File(['gzipped'], 'account-backup.json.gz', { type: 'application/gzip' });
    f.componentInstance.onFile(file);
    f.detectChanges();
    return file;
  }

  beforeEach(() => {
    load.mockReset();
    run.mockReset();
  });
  afterEach(() => ctrl.verify());

  it('previews the file on choice and renders the report counts', () => {
    const f = mount();
    chooseFile(f);
    const req = ctrl.expectOne('https://api.test/api/account/restore/preview');
    expect(req.request.method).toBe('POST');
    req.flush(previewResponse);
    f.detectChanges();

    const text = (f.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('3');
    expect(text).toContain('40');
    expect(text).toContain('them@x.test');
  });

  it('keeps the restore button disabled until REPLACE is typed exactly', () => {
    const f = mount();
    chooseFile(f);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    f.detectChanges();

    const c = f.componentInstance;
    expect(c.canRestore()).toBe(false);

    c.typed.set('replace');
    expect(c.canRestore()).toBe(false);

    c.typed.set('REPLACE ');
    expect(c.canRestore()).toBe(false);

    c.typed.set('REPLACE');
    expect(c.canRestore()).toBe(true);
  });

  it('restores with the same file, reloads subscriptions and refreshes', () => {
    const f = mount();
    const file = chooseFile(f);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    f.detectChanges();

    const c = f.componentInstance;
    c.typed.set('REPLACE');
    c.restore();

    const req = ctrl.expectOne('https://api.test/api/account/restore?confirm=REPLACE');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe(file);
    req.flush({ loaded: { tags: 2, feeds: 3, subscriptions: 3, entries: 40, entryStates: 40 } });
    f.detectChanges();

    expect(c.result()?.loaded.subscriptions).toBe(3);
    expect(c.file()).toBeNull();
    expect(c.typed()).toBe('');
    expect(c.preview()).toBeNull();
    expect(load).toHaveBeenCalled();
    expect(run).toHaveBeenCalled();
  });

  it('shows the re-run message and keeps the file selected after a failed restore', () => {
    const f = mount();
    const file = chooseFile(f);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(previewResponse);
    f.detectChanges();

    const c = f.componentInstance;
    c.typed.set('REPLACE');
    c.restore();

    ctrl.expectOne('https://api.test/api/account/restore?confirm=REPLACE').flush(
      {
        type: 'about:blank',
        title: 'Storage failure',
        detail: 'Could not write the restored data.',
        status: 422,
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    f.detectChanges();

    expect(c.failedOnce()).toBe(true);
    expect(c.file()).toBe(file);
    const text = (f.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Run the restore again with the same file');
  });

  it('shows the preview problem detail and clears any stale preview', () => {
    const f = mount();
    chooseFile(f);
    ctrl.expectOne('https://api.test/api/account/restore/preview').flush(
      {
        type: 'about:blank',
        title: 'Backup does not fit',
        detail: 'This backup is for a different plan.',
        status: 409,
      },
      { status: 409, statusText: 'Conflict' },
    );
    f.detectChanges();

    const c = f.componentInstance;
    expect(c.preview()).toBeNull();
    const banner = (f.nativeElement as HTMLElement).querySelector('app-error-banner');
    expect(banner).not.toBeNull();
    expect((f.nativeElement as HTMLElement).textContent ?? '').toContain(
      'This backup is for a different plan.',
    );
  });
});
