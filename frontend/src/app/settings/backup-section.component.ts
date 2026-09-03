import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, REQUEST_TOO_LARGE, parseProblem, parseProblemAsync } from '../core/problem';
import { filenameFromContentDisposition, saveAs } from '../core/save-as';
import { downloadOpmlExport } from '../core/opml-export';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { RestorePreview, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { RefreshService } from '../reader/refresh.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';

const CONFIRM_PHRASE = 'REPLACE';

/** Used only if the server's Content-Disposition header is missing or
 *  unparseable -- normal responses carry the app-slug/version/account/date
 *  name the backend builds (BackupFilename). */
const FALLBACK_BACKUP_FILENAME = 'account-backup.json.gz';

/** The one problem type the backend raises from an already-wiped account
 *  (BackupLoadFailedException). Every other restore failure -- the file does
 *  not fit, the file is invalid, the confirmation is missing, the body is too
 *  large -- is refused before a single row is deleted. */
const POST_WIPE_PROBLEM = 'backup_load_failed';

/** A request whose outcome the response can't prove: a dropped connection
 *  (status 0) leaves the wipe's fate unknown, and a 5xx -- gateway timeout,
 *  OOM-killed worker, anything but the typed BackupLoadFailedException --
 *  can just as well be a post-wipe crash. Only here is "may be partly
 *  loaded" the honest report. */
function outcomeIsUnproven(problem: Problem): boolean {
  return problem.status === 0 || problem.status >= 500;
}

@Component({
  selector: 'app-backup-section',
  imports: [ButtonComponent, ErrorBannerComponent, SettingsGroupComponent, TranslocoPipe],
  templateUrl: './backup-section.component.html',
  styleUrl: './backup-section.component.scss',
})
export class BackupSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly refresh = inject(RefreshService);
  private readonly language = inject(LanguageService);
  private readonly transloco = inject(TranslocoService);

  readonly exporting = signal(false);
  readonly exportError = signal<Problem | null>(null);

  readonly safetyNetExporting = signal(false);
  readonly safetyNetError = signal<Problem | null>(null);

  readonly file = signal<File | null>(null);
  readonly previewing = signal(false);
  readonly preview = signal<RestorePreview | null>(null);
  readonly typed = signal('');
  readonly restoring = signal(false);
  readonly result = signal<RestoreResult | null>(null);
  readonly error = signal<Problem | null>(null);
  /** Set once a restore fails AFTER the wipe, never cleared -- the rows are
   *  already gone, so the recovery banner stays up even through a retry. A
   *  refusal that cost the account nothing must never set this: a false "may
   *  be half-wiped" alarm is the worst this feature can raise. */
  readonly failedOnce = signal(false);

  readonly canRestore = computed(
    () => this.typed() === CONFIRM_PHRASE && !!this.file() && !this.restoring(),
  );

  /** Banner texts, memoised like ai-section's `listFailure` and
   *  recommendation-settings-card's `failureMessage`: this component is not
   *  OnPush, so a template-called method would re-translate on every
   *  change-detection tick while a banner is up. */
  readonly exportErrorMessage = computed(() => this.messageFor(this.exportError()));
  readonly safetyNetErrorMessage = computed(() => this.messageFor(this.safetyNetError()));
  readonly errorMessage = computed(() => this.messageFor(this.error()));

  /** A body the web server refused as oversized never reaches the app, so it
   *  carries no translated detail of its own -- and it is the one failure here
   *  the user can act on, so it gets wording that names the upload limit
   *  instead of the generic fallback (#458). */
  private messageFor(problem: Problem | null): string | null {
    if (problem === null) return null;
    if (problem.type === REQUEST_TOO_LARGE) {
      return this.transloco.translate('settings.backup.tooLarge');
    }

    return problem.detail || problem.title;
  }

  createdAt(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  downloadBackup(): void {
    this.exporting.set(true);
    this.exportError.set(null);
    this.api.downloadAccountBackup().subscribe({
      next: (response) => {
        this.exporting.set(false);
        if (!response.body) return;
        const filename = filenameFromContentDisposition(
          response.headers.get('Content-Disposition'),
          FALLBACK_BACKUP_FILENAME,
        );
        saveAs(response.body, filename);
      },
      error: async (e: HttpErrorResponse) => {
        const problem = await parseProblemAsync(e);
        this.exporting.set(false);
        this.exportError.set(problem);
      },
    });
  }

  exportSafetyNetOpml(): void {
    downloadOpmlExport(this.api, this.safetyNetExporting, this.safetyNetError);
  }

  onFileSelected(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.onFile(file);
  }

  onFile(file: File): void {
    this.file.set(file);
    this.result.set(null);
    this.error.set(null);
    this.preview.set(null);
    this.previewing.set(true);
    this.api.previewAccountRestore(file).subscribe({
      next: (p) => {
        this.previewing.set(false);
        this.preview.set(p);
      },
      error: (e: HttpErrorResponse) => {
        this.previewing.set(false);
        this.preview.set(null);
        this.error.set(parseProblem(e));
      },
    });
  }

  restore(): void {
    const file = this.file();
    if (!file || !this.canRestore()) return;
    this.restoring.set(true);
    this.error.set(null);
    this.api.restoreAccount(file).subscribe({
      next: (r) => {
        this.restoring.set(false);
        this.file.set(null);
        this.typed.set('');
        this.preview.set(null);
        this.result.set(r);
        this.subs.load();
        // Restored feeds arrive with a virgin schedule and are empty until a
        // fetch runs -- same reasoning as the OPML import's post-import refresh.
        this.refresh.run(() => this.subs.load());
      },
      error: (e: HttpErrorResponse) => {
        const problem = parseProblem(e);
        this.restoring.set(false);
        this.error.set(problem);
        if (problem.type === POST_WIPE_PROBLEM || outcomeIsUnproven(problem)) {
          this.failedOnce.set(true);
        }
      },
    });
  }
}
