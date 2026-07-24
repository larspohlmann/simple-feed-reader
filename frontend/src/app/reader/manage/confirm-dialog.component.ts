// src/app/reader/manage/confirm-dialog.component.ts
import { Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';

export interface ConfirmData {
  title: string;
  message: string;
  confirmLabel: string;
  danger?: boolean;
}

@Component({
  selector: 'app-confirm-dialog',
  imports: [A11yModule],
  templateUrl: './confirm-dialog.component.html',
  styleUrl: './confirm-dialog.component.scss',
})
export class ConfirmDialogComponent {
  readonly ref = inject<DialogRef<boolean>>(DialogRef);
  readonly data = inject<ConfirmData>(DIALOG_DATA);
}
