import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem } from '../../core/problem';
import { ButtonComponent } from '../../shared/button/button.component';
import { FieldComponent } from '../../shared/field/field.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ToastService } from '../../shared/toast/toast.service';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';

export interface NewLabelDialogData {
  entry: EntryDto;
  actions: EntryActionHandler;
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

  readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.pattern(/\S/), Validators.maxLength(60)]],
  });
  protected readonly saving = signal(false);

  create(): void {
    if (this.form.invalid || this.saving()) return;
    this.saving.set(true);
    this.data.actions.createLabel(this.data.entry, this.form.getRawValue().name.trim()).subscribe({
      complete: () => this.ref.close(),
      error: (problem: Problem) => {
        this.saving.set(false);
        this.toast.showProblem(problem);
      },
    });
  }
}
