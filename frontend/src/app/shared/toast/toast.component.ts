// src/app/shared/toast/toast.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { DIALOG_DATA } from '@angular/cdk/dialog';
import { ToastService } from './toast.service';

/** What the toast shows. Already-translated strings only -- this lives in
 *  shared/ and must not know any feature's i18n keys. */
export interface ToastData {
  message: string;
  actionLabel?: string;
  action?: () => void;
  durationMs?: number;
}

@Component({
  selector: 'app-toast',
  templateUrl: './toast.component.html',
  styleUrl: './toast.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToastComponent {
  readonly data = inject<ToastData>(DIALOG_DATA);
  readonly svc = inject(ToastService);

  onAction(): void {
    this.data.action?.();
    this.svc.dismiss();
  }
}
