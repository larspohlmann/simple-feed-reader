import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem } from '../../core/problem';
import { ButtonComponent } from '../../shared/button/button.component';
import { FieldComponent } from '../../shared/field/field.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ToastService } from '../../shared/toast/toast.service';
import { LabelledRow } from './labelled-row';
import { LABEL_NAME_MAX } from './labels.models';

export interface NewLabelDialogData {
  row: LabelledRow;
}

/** The coarse-pointer label sheet's "New label…": names a label, creates it and puts it on the entry. A taken name
 *  keeps the dialog open behind its toast. */
@Component({
  selector: 'app-new-label-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    FieldComponent,
    OverlayPanelComponent,
    ReactiveFormsModule,
    TranslocoPipe,
  ],
  templateUrl: './new-label-dialog.component.html',
  styleUrl: './new-label-dialog.component.scss',
})
export class NewLabelDialogComponent {
  readonly ref = inject<DialogRef<void>>(DialogRef);
  private readonly data = inject<NewLabelDialogData>(DIALOG_DATA);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  readonly form = inject(NonNullableFormBuilder).group({
    name: [
      '',
      [Validators.required, Validators.pattern(/\S/), Validators.maxLength(LABEL_NAME_MAX)],
    ],
  });
  protected readonly saving = signal(false);
  protected readonly nameMax = LABEL_NAME_MAX;

  create(): void {
    if (this.form.invalid || this.saving()) return;
    this.saving.set(true);
    this.data.row.createLabel(this.form.getRawValue().name.trim()).subscribe({
      complete: () => this.ref.close(),
      error: (problem: Problem) => {
        this.saving.set(false);
        this.toast.showTranslatedProblem(problem, this.i18n);
      },
    });
  }
}
