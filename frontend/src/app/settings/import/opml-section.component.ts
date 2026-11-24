import { Dialog } from '@angular/cdk/dialog';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../../core/problem';
import { downloadOpmlExport } from './opml-export';
import { SettingsApi } from '../settings-api';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { OpmlImportResult } from '../settings.models';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { OpmlImportDialogComponent, OpmlImportDialogData } from './opml-import-dialog.component';

@Component({
  selector: 'app-opml-section',
  imports: [ButtonComponent, ErrorBannerComponent, SettingsGroupComponent, TranslocoPipe],
  templateUrl: './opml-section.component.html',
  styleUrl: './opml-section.component.scss',
})
export class OpmlSectionComponent {
  private readonly api = inject(SettingsApi);
  private readonly dialog = inject(Dialog);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly refresh = inject(RefreshService);
  private readonly i18n = inject(TranslocoService);

  readonly previewing = signal(false);
  readonly exporting = signal(false);
  readonly result = signal<OpmlImportResult | null>(null);
  readonly exportError = signal<Problem | null>(null);
  readonly importError = signal<Problem | null>(null);

  exportOpml(): void {
    downloadOpmlExport(this.api, this.exporting, this.exportError);
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || this.previewing()) return;
    this.previewing.set(true);
    this.importError.set(null);
    this.result.set(null);
    file
      .text()
      .then((contents) => this.preview(contents))
      .catch(() => this.failToRead());
  }

  private failToRead(): void {
    this.previewing.set(false);
    this.importError.set({
      type: 'unreadable_file',
      title: this.i18n.translate('settings.opml.unreadable'),
      status: 0,
    });
  }

  private preview(contents: string): void {
    this.api.previewOpml(contents, null).subscribe({
      next: (preview) => {
        this.previewing.set(false);
        this.openDialog({ opml: contents, preview });
      },
      error: (error: HttpErrorResponse) => {
        this.previewing.set(false);
        this.importError.set(parseProblem(error));
      },
    });
  }

  private openDialog(data: OpmlImportDialogData): void {
    this.dialog
      .open<OpmlImportResult | undefined>(OpmlImportDialogComponent, {
        data,
        panelClass: 'app-dialog',
      })
      .closed.subscribe((importResult) => {
        if (importResult) this.finishImport(importResult);
      });
  }

  private finishImport(importResult: OpmlImportResult): void {
    this.result.set(importResult);
    this.subs.load();
    this.tags.load();
    if (importResult.imported > 0) {
      this.refresh.run(() => this.subs.load(), { kind: 'neverFetched' });
    }
  }
}
