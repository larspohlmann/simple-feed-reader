// src/app/shared/toast/toast.component.ts
import { ChangeDetectionStrategy, Component, Type, inject } from '@angular/core';
import { NgComponentOutlet } from '@angular/common';
import { DIALOG_DATA } from '@angular/cdk/dialog';
import { ToastService } from './toast.service';

/** What every toast carries, whichever mode it is in. Already-translated
 *  strings only -- this lives in shared/ and must not know any feature's
 *  i18n keys. */
interface ToastBase {
  actionLabel?: string;
  action?: () => void;
  /** Omitted takes the 6000ms default. An explicit `null` never auto-dismisses,
   *  for a surface that must live as long as the work it reports on. */
  durationMs?: number | null;
  /** `fixed` holds one box width across successive toasts, so a surface whose
   *  content changes mid-life does not resize under the user. */
  width?: 'fit' | 'fixed';
}

/** A toast showing one translated line. */
export type MessageToast = ToastBase & { message: string };

/** A toast hosting a feature's own component, for content this shared shell
 *  cannot render itself -- a live progress readout, for one. The component is
 *  built through the outlet, so it injects and reads its own feature's
 *  services without anything being threaded through here. */
export type ContentToast = ToastBase & { content: Type<unknown> };

export type ToastData = MessageToast | ContentToast;

@Component({
  selector: 'app-toast',
  imports: [NgComponentOutlet],
  templateUrl: './toast.component.html',
  styleUrl: './toast.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToastComponent {
  private readonly data = inject<ToastData>(DIALOG_DATA);
  readonly svc = inject(ToastService);

  readonly message = 'message' in this.data ? this.data.message : null;
  readonly content = 'content' in this.data ? this.data.content : null;
  readonly actionLabel = this.data.actionLabel ?? null;

  onAction(): void {
    this.data.action?.();
    this.svc.dismiss();
  }
}
