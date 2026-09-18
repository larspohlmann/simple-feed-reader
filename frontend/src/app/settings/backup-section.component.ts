import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { Problem, REQUEST_TOO_LARGE, parseProblem, parseProblemAsync } from '../core/problem';
import { filenameFromContentDisposition, saveAs } from '../core/save-as';
import { downloadOpmlExport } from '../core/opml-export';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { RestoreCounts, RestorePreview, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { RefreshService } from '../reader/refresh.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import {
  BackupArchive,
  InvalidBackupArchiveError,
  isOldFormatBackup,
  openBackupArchive,
} from './backup-archive';
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

/** Marks a `Problem` this component built itself from a check that never
 *  reached the server -- an old-format file, or a zip `openBackupArchive`
 *  rejected. `detail` holds the i18n key to translate, the same trick
 *  `messageFor()` already plays for `REQUEST_TOO_LARGE`. */
const CLIENT_CHECK_FAILED = 'client_backup_check_failed';

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
  /** Set once a restore fails AFTER the wipe, never cleared -- the rows are
   *  already gone, so the recovery banner stays up even through a retry. A
   *  refusal that cost the account nothing must never set this: a false "may
   *  be half-wiped" alarm is the worst this feature can raise. */
  readonly failedOnce = signal(false);

  readonly progress = this.restoreRun.progress;
  readonly canContinue = this.restoreRun.canContinue;

  /** The archive `onFile()` verified and previewed -- `restore()` reads the
   *  same object so it never re-opens or re-verifies the zip. */
  private archive: BackupArchive | null = null;

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

  private clientCheckFailed(detailKey: string): Problem {
    return {
      type: CLIENT_CHECK_FAILED,
      title: 'Backup check failed',
      status: 0,
      detail: detailKey,
    };
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
    this.restoreRun.reset();
    this.releaseArchive();
    this.file.set(file);
    this.result.set(null);
    this.error.set(null);
    this.preview.set(null);

    if (isOldFormatBackup(file.name)) {
      this.error.set(this.clientCheckFailed('settings.backup.oldFormat'));
      return;
    }

    this.previewing.set(true);
    void this.openAndPreview(file);
  }

  private async openAndPreview(file: File): Promise<void> {
    try {
      this.archive = await openBackupArchive(file);
      const preview = await firstValueFrom(
        this.api.previewAccountRestore(await this.archive.foundation()),
      );
      this.previewing.set(false);
      this.preview.set(preview);
    } catch (error) {
      this.releaseArchive();
      this.previewing.set(false);
      this.preview.set(null);
      this.error.set(this.previewFailure(error));
    }
  }

  private releaseArchive(): void {
    void this.archive?.close().catch(() => undefined);
    this.archive = null;
  }

  private previewFailure(error: unknown): Problem {
    if (error instanceof InvalidBackupArchiveError) {
      return this.clientCheckFailed('settings.backup.invalidArchive');
    }
    return parseProblem(error as HttpErrorResponse);
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
    const outcome = await action();
    this.restoring.set(false);
    if (outcome.kind === 'completed') {
      this.onRestoreCompleted(outcome.loaded);
      return;
    }
    this.error.set(outcome.problem);
    if (outcome.wiped) {
      this.failedOnce.set(true);
    }
  }

  private onRestoreCompleted(loaded: RestoreCounts): void {
    this.file.set(null);
    this.typed.set('');
    this.preview.set(null);
    this.restoreRun.reset();
    this.releaseArchive();
    this.result.set({ loaded });
    this.subs.load();
    // Restored feeds arrive with a virgin schedule and are empty until a
    // fetch runs -- same reasoning as the OPML import's post-import refresh.
    this.refresh.run(() => this.subs.load());
  }
}
