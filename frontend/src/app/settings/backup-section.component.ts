import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { Problem, REQUEST_TOO_LARGE, parseProblemAsync } from '../core/problem';
import { filenameFromContentDisposition, saveAs } from '../core/save-as';
import { downloadOpmlExport } from '../core/opml-export';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { RestoreCounts, RestorePreview, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { RefreshService } from '../reader/refresh.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { BackupArchive, isOldFormatBackup, openBackupArchive } from './backup-archive';
import { CLIENT_CHECK_FAILED, clientCheckFailed, restoreErrorProblem } from './backup-problem';
import { BackupRestoreRun, RestoreRunOutcome } from './backup-restore-run';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { ProgressHairlineComponent } from '../shared/progress-hairline/progress-hairline.component';
import { SettingsGroupComponent } from '../shared/settings/settings-group/settings-group.component';

const CONFIRM_PHRASE = 'REPLACE';

/** Used only if the server's Content-Disposition header is missing or
 *  unparseable -- normal responses carry the app-slug/version/account/date
 *  name the backend builds (BackupFilename). */
const FALLBACK_BACKUP_FILENAME = 'account-backup.zip';

@Component({
  selector: 'app-backup-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    ProgressHairlineComponent,
    SettingsGroupComponent,
    TranslocoPipe,
  ],
  templateUrl: './backup-section.component.html',
  styleUrl: './backup-section.component.scss',
})
export class BackupSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly refresh = inject(RefreshService);
  private readonly language = inject(LanguageService);
  private readonly transloco = inject(TranslocoService);
  private readonly restoreRun = inject(BackupRestoreRun);

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
  /** Set once a restore fails AFTER the wipe -- the rows are already gone, so
   *  the recovery banner stays up through a retry, and is cleared only once a
   *  run completes. A refusal that cost the account nothing must never set
   *  this: a false "may be half-wiped" alarm is the worst this feature raises. */
  readonly failedOnce = signal(false);

  readonly progress = this.restoreRun.progress;
  readonly canContinue = this.restoreRun.canContinue;

  /** The archive `onFile()` verified and previewed -- `restore()` reads the
   *  same object so it never re-opens or re-verifies the zip. */
  private archive: BackupArchive | null = null;

  /** Bumped on every file pick so a slower open/preview of an earlier file is
   *  discarded and its archive closed, never applied over a later pick (#1073). */
  private openGeneration = 0;

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
   *  instead of the generic fallback (#458). A client-side check failure
   *  carries no server detail either, so its `detail` holds the i18n key to
   *  translate instead of already-translated text. */
  private messageFor(problem: Problem | null): string | null {
    if (problem === null) return null;
    if (problem.type === REQUEST_TOO_LARGE) {
      return this.transloco.translate('settings.backup.tooLarge');
    }
    if (problem.type === CLIENT_CHECK_FAILED) {
      return this.transloco.translate(problem.detail ?? '');
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
    // A restore in flight has already wiped or is mid-load; a new pick must not
    // reset the run and close the archive out from under it.
    if (this.restoring()) return;

    this.restoreRun.reset();
    this.releaseArchive();
    const generation = ++this.openGeneration;
    this.file.set(file);
    this.result.set(null);
    this.error.set(null);
    this.preview.set(null);

    if (isOldFormatBackup(file.name)) {
      this.error.set(clientCheckFailed('settings.backup.oldFormat'));
      return;
    }

    this.previewing.set(true);
    void this.openAndPreview(file, generation);
  }

  private async openAndPreview(file: File, generation: number): Promise<void> {
    try {
      const archive = await openBackupArchive(file);
      if (generation !== this.openGeneration) {
        void archive.close().catch(() => undefined);
        return;
      }
      this.archive = archive;
      const preview = await firstValueFrom(
        this.api.previewAccountRestore(await archive.foundation()),
      );
      if (generation !== this.openGeneration) return;
      this.previewing.set(false);
      this.preview.set(preview);
    } catch (error) {
      if (generation !== this.openGeneration) return;
      this.releaseArchive();
      this.previewing.set(false);
      this.preview.set(null);
      this.error.set(restoreErrorProblem(error));
    }
  }

  private releaseArchive(): void {
    void this.archive?.close().catch(() => undefined);
    this.archive = null;
  }

  restore(): void {
    const archive = this.archive;
    if (!archive || !this.canRestore()) return;
    this.restoring.set(true);
    this.error.set(null);
    void this.drive(() => this.restoreRun.run(archive));
  }

  continueRestore(): void {
    this.restoring.set(true);
    this.error.set(null);
    void this.drive(() => this.restoreRun.continue());
  }

  private async drive(action: () => Promise<RestoreRunOutcome>): Promise<void> {
    try {
      const outcome = await action();
      if (outcome.kind === 'completed') {
        this.onRestoreCompleted(outcome.loaded);
        return;
      }
      this.error.set(outcome.problem);
      if (outcome.wiped) {
        this.failedOnce.set(true);
      }
    } catch {
      // The run catches every expected failure and returns an outcome, so a
      // throw here is a bug: surface it rather than strand the UI mid-restore.
      this.error.set(clientCheckFailed('settings.backup.unexpectedError'));
    } finally {
      this.restoring.set(false);
    }
  }

  private onRestoreCompleted(loaded: RestoreCounts): void {
    this.file.set(null);
    this.typed.set('');
    this.preview.set(null);
    this.error.set(null);
    this.failedOnce.set(false);
    this.restoreRun.reset();
    this.releaseArchive();
    this.result.set({ loaded });
    this.subs.load();
    // Restored feeds arrive with a virgin schedule and are empty until a
    // fetch runs -- same reasoning as the OPML import's post-import refresh.
    this.refresh.run(() => this.subs.load());
  }
}
