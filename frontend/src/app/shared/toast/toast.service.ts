// src/app/shared/toast/toast.service.ts
import { Injectable, Signal, inject, signal } from '@angular/core';
import { Dialog, DialogRef } from '@angular/cdk/dialog';
import { Overlay } from '@angular/cdk/overlay';
import { ToastComponent, ToastData } from './toast.component';

const DEFAULT_DURATION_MS = 6000;

/** How long a bare confirmation stays up. Shorter than the default on purpose:
 *  a "Saved." carries no action and nothing to read past the word itself, and
 *  the user is still working on the surface it sits over -- six seconds of it
 *  reads as a toast that never leaves (#554). A toast that offers an action, or
 *  reports a failure, keeps the longer default. */
export const CONFIRMATION_DURATION_MS = 3000;

/**
 * The app's one toast: a message -- or a feature's own component -- with an
 * optional single action, replacing whatever is currently visible. Rendered
 * through the CDK overlay -- never `position: fixed` -- because a transformed
 * ancestor (the open drawer, a dialog) would re-anchor a fixed child to the
 * wrong containing block (#85, #100). `hasBackdrop: false`, `autoFocus: false`,
 * `restoreFocus: false` and a `noop` scroll strategy: a toast must never steal
 * focus -- nor freeze the page -- from whatever the user is doing. The CDK
 * `Dialog` defaults its scroll strategy to `block`, which would lock scrolling
 * for the toast's whole life; a "Saved." over a long settings page then reads
 * as a page that will not move (#700).
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly dialog = inject(Dialog);
  private readonly overlay = inject(Overlay);

  private ref: DialogRef<void, ToastComponent> | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private cachedBottomOffset: string | null = null;
  private paneObserver: ResizeObserver | null = null;

  /** The custom property carrying the live toast's height, published on the
   *  document root while a toast is on screen and removed when none is. A
   *  bottom-anchored floating control (a to-top button) reads it to sit clear
   *  of the toast instead of being buried under it -- on a narrow layout the
   *  run pill spans nearly the full width and, as an overlay, paints over the
   *  corner the button lives in (#641). */
  private static readonly HEIGHT_PROPERTY = '--app-toast-height';

  private readonly _visible = signal(false);
  /** Whether a toast is on screen. Read by a feature that offers to raise its
   *  own long-lived toast again after the user closed it (#398). */
  readonly visible: Signal<boolean> = this._visible.asReadonly();

  /** Replaces any toast currently visible. */
  show(toast: ToastData): void {
    this.clearTimer();
    this.ref?.close();

    const ref = this.dialog.open<void, ToastData, ToastComponent>(ToastComponent, {
      panelClass: this.panelClasses(toast),
      positionStrategy: this.overlay
        .position()
        .global()
        .centerHorizontally()
        .bottom(this.bottomOffset()),
      scrollStrategy: this.overlay.scrollStrategies.noop(),
      hasBackdrop: false,
      autoFocus: false,
      restoreFocus: false,
      // The overlay's keyboard dispatcher routes every body-level Escape to
      // the topmost overlay, and Escape is habitual elsewhere in this app
      // (the search field's clear/close) -- so an ordinary keystroke aimed at
      // something else would close a persistent toast (a minutes-long run
      // readout) out from under the user. Only the ✕ may end one of those.
      disableClose: this.durationOf(toast) === null,
      data: toast,
    });
    this.ref = ref;
    this._visible.set(true);
    this.publishHeight(ref);

    // Every close lands here -- the ✕, `dismiss()`, Escape -- so the flag has
    // one owner rather than three. The identity guard is what makes that safe:
    // a replacement opens the next toast before the outgoing ref reports
    // itself closed, and an unguarded handler would blank the flag for a toast
    // that is on screen.
    ref.closed.subscribe(() => {
      if (this.ref !== ref) return;
      this.ref = null;
      this._visible.set(false);
      this.clearHeight();
    });

    const durationMs = this.durationOf(toast);
    if (durationMs === null) return;
    this.timer = setTimeout(() => this.dismiss(), durationMs);
  }

  dismiss(): void {
    this.clearTimer();
    this.ref?.close();
  }

  private clearTimer(): void {
    if (this.timer === null) return;
    clearTimeout(this.timer);
    this.timer = null;
  }

  /** Mirrors the toast's rendered height onto the document root, and keeps it
   *  current: a content toast (the run pill) grows a line as the run reports a
   *  phase or an ETA, so a single measurement would leave a stale offset. The
   *  observer supersedes any prior toast's, and a browser without
   *  `ResizeObserver` still gets the first measurement. */
  private publishHeight(ref: DialogRef<void, ToastComponent>): void {
    this.clearHeight();
    const pane = ref.overlayRef.overlayElement;
    const write = (): void =>
      document.documentElement.style.setProperty(
        ToastService.HEIGHT_PROPERTY,
        `${Math.ceil(pane.getBoundingClientRect().height)}px`,
      );
    write();
    if (typeof ResizeObserver === 'undefined') return;
    this.paneObserver = new ResizeObserver(write);
    this.paneObserver.observe(pane);
  }

  private clearHeight(): void {
    this.paneObserver?.disconnect();
    this.paneObserver = null;
    document.documentElement.style.removeProperty(ToastService.HEIGHT_PROPERTY);
  }

  /** Stylelint bans ad-hoc `px` outside `theme/`, but cannot see this file:
   *  read the spacing scale's own value instead of hardcoding a literal that
   *  would silently drift from it (#308 final review, Minor 9). Read once and
   *  kept: the token is a static custom property, and `getComputedStyle`
   *  forces a style flush every time it is asked. */
  private bottomOffset(): string {
    this.cachedBottomOffset ??=
      getComputedStyle(document.documentElement).getPropertyValue('--space-5').trim() || '24px';

    return this.cachedBottomOffset;
  }

  /** Omitting the duration takes the default; an explicit `null` means never.
   *  `??` cannot express that pair -- it folds `null` into the default and
   *  would dismiss a run pill after six seconds -- so the two cases are read
   *  apart here rather than coalesced. */
  private durationOf(toast: ToastData): number | null {
    return toast.durationMs === undefined ? DEFAULT_DURATION_MS : toast.durationMs;
  }

  private panelClasses(toast: ToastData): string[] {
    const classes = ['app-toast'];
    if (toast.width === 'fixed') classes.push('app-toast--fixed');
    if (toast.tone === 'translucent') classes.push('app-toast--translucent');
    return classes;
  }
}
