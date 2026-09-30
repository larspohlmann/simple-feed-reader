import { DestroyRef, Directive, ElementRef, inject, input, output } from '@angular/core';
import { isDrawerSwipe } from '../reader-gestures';

/**
 * Opens/closes the mobile sidebar drawer with a horizontal swipe on the content
 * area: a rightward swipe opens it, a leftward swipe closes it. A pure toggle —
 * the drawer keeps its own CSS slide transition, so this only decides the flip.
 *
 * Touches that begin inside the open drawer panel ([data-drawer-panel]) are left
 * alone so the sidebar's own scrolling and drag-to-reorder keep working; the
 * close-swipe is driven from the main/backdrop area instead.
 */
@Directive({
  selector: '[appDrawerSwipe]',
})
export class DrawerSwipeDirective {
  /** Whether the drawer is currently open (decides open- vs close-swipe). */
  readonly open = input(false, { alias: 'appDrawerSwipeOpen' });
  /** Suppress the gesture entirely (wide screen, or an article is open). */
  readonly disabled = input(false, { alias: 'appDrawerSwipeDisabled' });

  readonly openDrawer = output<void>({ alias: 'appDrawerSwipeOpenDrawer' });
  readonly closeDrawer = output<void>({ alias: 'appDrawerSwipeCloseDrawer' });

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  private startX = 0;
  private startY = 0;
  private dx = 0;
  private dy = 0;
  private tracking = false;

  constructor() {
    const element = this.host.nativeElement;
    const start = (event: TouchEvent): void => this.onTouchStart(event);
    const move = (event: TouchEvent): void => this.onTouchMove(event);
    const end = (): void => this.onTouchEnd();
    // All passive: a threshold toggle never needs to preventDefault, so vertical
    // scrolling of the list stays smooth and only the final delta is inspected.
    element.addEventListener('touchstart', start, { passive: true });
    element.addEventListener('touchmove', move, { passive: true });
    element.addEventListener('touchend', end);
    element.addEventListener('touchcancel', end);
    inject(DestroyRef).onDestroy(() => {
      element.removeEventListener('touchstart', start);
      element.removeEventListener('touchmove', move);
      element.removeEventListener('touchend', end);
      element.removeEventListener('touchcancel', end);
    });
  }

  onTouchStart(event: TouchEvent): void {
    this.tracking = false;
    if (this.disabled() || event.touches.length !== 1) return;
    const target = event.target as Element | null;
    if (this.open() && target?.closest('[data-drawer-panel]')) return;
    const touch = event.touches[0];
    this.startX = touch.clientX;
    this.startY = touch.clientY;
    this.dx = 0;
    this.dy = 0;
    this.tracking = true;
  }

  onTouchMove(event: TouchEvent): void {
    if (!this.tracking || event.touches.length !== 1) return;
    const touch = event.touches[0];
    this.dx = touch.clientX - this.startX;
    this.dy = touch.clientY - this.startY;
  }

  onTouchEnd(): void {
    if (!this.tracking) return;
    this.tracking = false;
    if (this.open()) {
      if (isDrawerSwipe(this.dx, this.dy, -1)) this.closeDrawer.emit();
    } else if (isDrawerSwipe(this.dx, this.dy, 1)) {
      this.openDrawer.emit();
    }
  }
}
