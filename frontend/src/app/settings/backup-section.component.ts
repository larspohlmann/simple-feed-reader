// src/app/settings/backup-section.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { saveAs } from '../core/save-as';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { RestorePreview, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { RefreshService } from '../reader/refresh.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

const CONFIRM_PHRASE = 'REPLACE';

/** The one problem type the backend raises from an already-wiped account
 *  (BackupLoadFailedException). Every other restore failure -- the file does
 *  not fit, the file is invalid, the confirmation is missing, the body is too
 *  large -- is refused before a single row is deleted. */
const POST_WIPE_PROBLEM = 'backup_load_failed';

/** A request whose outcome is not provable from the response: a dropped
 *  connection (status 0) leaves the wipe's fate unknown, and a 5xx --
 *  gateway timeout, an OOM-killed worker, any throwable that is not the
 *  typed BackupLoadFailedException -- can just as well be a post-wipe crash
 *  that never reached the typed exception. Only here is "the account may be
 *  partly loaded" the honest report. */
function outcomeIsUnproven(problem: Problem): boolean {
  return problem.status === 0 || problem.status >= 500;
}

@Component({
  selector: 'app-backup-section',
  imports: [ButtonComponent, ErrorBannerComponent, SettingsCardComponent, TranslocoPipe],
  templateUrl: './backup-section.component.html',
  styleUrl: './backup-section.component.scss',
})
export class BackupSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly refresh = inject(RefreshService);
  private readonly language = inject(LanguageService);

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
  /** Set once a restore fails AFTER the wipe, and never cleared -- the rows
   *  are already gone, so the recovery banner has to stay up even after the
   *  user picks a fresh file for the retry. A refusal that cost the account
   *  nothing must never set this: telling a user their account may be
   *  half-wiped when it was not touched is the worst false alarm this
   *  feature can raise. */
  readonly failedOnce = signal(false);

  readonly canRestore = computed(
    () => this.typed() === CONFIRM_PHRASE && !!this.file() && !this.restoring(),
  );

  createdAt(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  downloadBackup(): void {
    this.exporting.set(true);
    this.exportError.set(null);
    this.api.downloadAccountBackup().subscribe({
      next: (blob) => {
        this.exporting.set(false);
        saveAs(blob, 'account-backup.json.gz');
      },
      error: (e: HttpErrorResponse) => {
        this.exporting.set(false);
        this.exportError.set(parseProblem(e));
      },
    });
  }

  exportSafetyNetOpml(): void {
    this.safetyNetExporting.set(true);
    this.safetyNetError.set(null);
    this.api.exportOpml().subscribe({
      next: (xml) => {
        this.safetyNetExporting.set(false);
        saveAs(new Blob([xml], { type: 'text/x-opml' }), 'feeds.opml');
      },
      error: (e: HttpErrorResponse) => {
        this.safetyNetExporting.set(false);
        this.safetyNetError.set(parseProblem(e));
      },
    });
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
