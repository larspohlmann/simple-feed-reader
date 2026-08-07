// src/app/shared/toast/toast.service.ts
import { Injectable, inject } from '@angular/core';
import { Dialog, DialogRef } from '@angular/cdk/dialog';
import { Overlay } from '@angular/cdk/overlay';
import { ToastComponent, ToastData } from './toast.component';

const DEFAULT_DURATION_MS = 6000;

/**
 * The app's one toast: a message with an optional single action, replacing
 * whatever is currently visible. Rendered through the CDK overlay -- never
 * `position: fixed` -- because a transformed ancestor (the open drawer, a
 * dialog) would re-anchor a fixed child to the wrong containing block (#85,
 * #100). `hasBackdrop: false`, `autoFocus: false`, `restoreFocus: false`: a
 * toast must never steal focus from whatever the user is doing.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly dialog = inject(Dialog);
  private readonly overlay = inject(Overlay);

  private ref: DialogRef<void, ToastComponent> | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;

  /** Replaces any toast currently visible. */
  show(toast: ToastData): void {
    this.clearTimer();
    this.ref?.close();

    this.ref = this.dialog.open<void, ToastData, ToastComponent>(ToastComponent, {
      panelClass: 'app-toast',
      positionStrategy: this.overlay
        .position()
        .global()
        .centerHorizontally()
        .bottom(this.bottomOffset()),
      hasBackdrop: false,
      autoFocus: false,
      restoreFocus: false,
      data: toast,
    });

    const durationMs = toast.durationMs ?? DEFAULT_DURATION_MS;
    this.timer = setTimeout(() => this.dismiss(), durationMs);
  }

  dismiss(): void {
    this.clearTimer();
    this.ref?.close();
    this.ref = null;
  }

  private clearTimer(): void {
    if (this.timer === null) return;
    clearTimeout(this.timer);
    this.timer = null;
  }

  /** Stylelint bans ad-hoc `px` outside `theme/`, but cannot see this file:
   *  read the spacing scale's own value instead of hardcoding a literal that
   *  would silently drift from it (#308 final review, Minor 9). */
  private bottomOffset(): string {
    const value = getComputedStyle(document.documentElement).getPropertyValue('--space-5').trim();

    return value === '' ? '24px' : value;
  }
}
