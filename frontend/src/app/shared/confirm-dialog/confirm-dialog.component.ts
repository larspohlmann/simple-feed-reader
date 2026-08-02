// src/app/shared/confirm-dialog/confirm-dialog.component.ts
import { Component, computed, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { FormsModule } from '@angular/forms';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../button/button.component';
import { OverlayPanelComponent } from '../overlay-panel/overlay-panel.component';

export interface ConfirmData {
  title: string;
  message: string;
  confirmLabel: string;
  danger?: boolean;
  /**
   * When set, the user must type this exact string before the confirm button
   * enables. For deletions that take content with them and cannot be undone —
   * a single click is too cheap for that.
   */
  requireText?: string;
}

@Component({
  selector: 'app-confirm-dialog',
  imports: [A11yModule, FormsModule, TranslocoPipe, ButtonComponent, OverlayPanelComponent],
  templateUrl: './confirm-dialog.component.html',
  styleUrl: './confirm-dialog.component.scss',
})
export class ConfirmDialogComponent {
  readonly ref = inject<DialogRef<boolean>>(DialogRef);
  readonly data = inject<ConfirmData>(DIALOG_DATA);

  readonly typed = signal('');

  readonly canConfirm = computed(
    () => !this.data.requireText || this.typed() === this.data.requireText,
  );
}
