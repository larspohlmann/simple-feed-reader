import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { filenameFromContentDisposition, saveAs } from '../../core/download/save-as';
import { Problem, parseProblemAsync } from '../../core/problem';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ListOrderService } from '../list/list-order.service';
import { EXPORT_FORMATS, ExportFormat, ExportTarget } from './export-formats';
import { ExportApi } from './export.api';

@Component({
  selector: 'app-export-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    FormsModule,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './export-dialog.component.html',
  styleUrl: './export-dialog.component.scss',
})
export class ExportDialogComponent {
  readonly ref = inject<DialogRef<void>>(DialogRef);
  protected readonly target = inject<ExportTarget>(DIALOG_DATA);
  private readonly api = inject(ExportApi);
  private readonly listOrder = inject(ListOrderService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly formats = EXPORT_FORMATS;
  protected readonly chosen = signal<ExportFormat>(EXPORT_FORMATS[0]);
  protected readonly downloading = signal(false);
  protected readonly problem = signal<Problem | null>(null);
  protected readonly problemMessage = computed(() => {
    const problem = this.problem();
    return problem === null ? null : problem.detail || problem.title;
  });

  choose(format: ExportFormat): void {
    this.chosen.set(format);
  }

  submit(): void {
    if (this.downloading()) return;
    const format = this.chosen();
    this.downloading.set(true);
    this.problem.set(null);
    const order = this.listOrder.orderFor({
      kind: this.target.collection,
      label: this.target.label ?? undefined,
    });
    this.api
      .download(format, this.target, order)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) =>
          this.finish(format, response.body, response.headers.get('Content-Disposition')),
        error: async (error: HttpErrorResponse) => {
          const problem = await parseProblemAsync(error);
          this.downloading.set(false);
          this.problem.set(problem);
        },
      });
  }

  private finish(format: ExportFormat, body: Blob | null, disposition: string | null): void {
    if (body !== null) {
      saveAs(body, filenameFromContentDisposition(disposition, format.fallbackFilename));
    }
    this.ref.close();
  }
}
