import { DestroyRef, Directive, effect, ElementRef, inject, input, NgZone } from '@angular/core';
import { clampListPercent, MAX_LIST_PERCENT, MIN_LIST_PERCENT } from './pane-split';
import { PaneSplitService } from './pane-split.service';

const STEP = 2;

/**
 * Drag-resizes the reading split from its handle. The live drag runs outside the
 * Angular zone and coalesces every write into one animation frame, so a fast drag
 * never floods change detection; only the released width goes through the service.
 * The percent band (MIN/MAX) is the collapse guard — no drag can strand a pane.
 * Pointer events cover mouse and touch with one code path.
 */
@Directive({
  selector: '[appPaneResize]',
})
export class PaneResizeDirective {
  /** The `.main` split container the handle lives in; the directive measures and writes it. */
  readonly container = input.required<HTMLElement>({ alias: 'appPaneResize' });

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly split = inject(PaneSplitService);
  private readonly zone = inject(NgZone);

  private latestClientX = 0;
  private frame: number | null = null;
  /** The container's edge and width, measured once at pointerdown: they cannot
   *  change mid-drag, so re-reading them per frame would only force a layout. */
  private dragLeft = 0;
  private dragWidth = 1;

  private readonly onPointerDown = (event: PointerEvent): void => {
    if (event.button !== 0) return;
    // No preventDefault: it would suppress the compatibility click/dblclick the
    // browser synthesises from the pointer, silently breaking double-click reset.
    // Text selection during the drag is held off with `user-select` instead.
    this.host.setPointerCapture?.(event.pointerId);
    this.container().style.userSelect = 'none';
    const rect = this.container().getBoundingClientRect();
    this.dragLeft = rect.left;
    this.dragWidth = rect.width;
    // Outside the zone: the move handler writes the CSS var directly and never a
    // signal, so a fast drag must not tick change detection on every frame. Only
    // the released width re-enters the zone, through `commit`.
    this.zone.runOutsideAngular(() => {
      this.host.addEventListener('pointermove', this.onPointerMove);
      this.host.addEventListener('pointerup', this.onPointerUp);
      this.host.addEventListener('pointercancel', this.onPointerUp);
    });
  };

  private readonly onPointerMove = (event: PointerEvent): void => {
    this.latestClientX = event.clientX;
    if (typeof requestAnimationFrame !== 'function') {
      this.applyLive();
      return;
    }
    if (this.frame !== null) return;
    this.frame = requestAnimationFrame(() => {
      this.frame = null;
      this.applyLive();
    });
  };

  private readonly onPointerUp = (event: PointerEvent): void => {
    this.host.releasePointerCapture?.(event.pointerId);
    this.host.removeEventListener('pointermove', this.onPointerMove);
    this.host.removeEventListener('pointerup', this.onPointerUp);
    this.host.removeEventListener('pointercancel', this.onPointerUp);
    this.container().style.userSelect = '';
    this.cancelFrame();
    this.commit(this.percentFromClientX(event.clientX));
  };

  private readonly onDblClick = (): void => this.split.reset();

  private readonly onKeydown = (event: KeyboardEvent): void => {
    const current = this.split.width();
    if (event.key === 'ArrowLeft') this.commit(clampListPercent(current - STEP));
    else if (event.key === 'ArrowRight') this.commit(clampListPercent(current + STEP));
    else if (event.key === 'Home') this.split.reset();
    else return;
    event.preventDefault();
  };

  constructor() {
    this.setAriaContract();
    this.host.addEventListener('pointerdown', this.onPointerDown);
    this.host.addEventListener('dblclick', this.onDblClick);
    this.host.addEventListener('keydown', this.onKeydown);

    effect(() => this.writeWidth(this.split.width()));

    inject(DestroyRef).onDestroy(() => this.teardown());
  }

  private applyLive(): void {
    this.writeWidth(this.percentFromClientX(this.latestClientX));
  }

  /** The one writer of the visible width: the live-drag var and the committed
   *  value, so a no-op `set()` (Object.is skips the effect) cannot strand the
   *  CSS var on a stale live value. */
  private writeWidth(percent: number): void {
    this.container().style.setProperty('--list-width', `${percent}%`);
    this.host.setAttribute('aria-valuenow', String(Math.round(percent)));
  }

  private percentFromClientX(clientX: number): number {
    return clampListPercent(((clientX - this.dragLeft) / this.dragWidth) * 100);
  }

  private commit(percent: number): void {
    this.zone.run(() => this.split.set(percent));
    this.writeWidth(this.split.width());
  }

  private setAriaContract(): void {
    this.host.setAttribute('role', 'separator');
    this.host.setAttribute('aria-orientation', 'vertical');
    this.host.setAttribute('tabindex', '0');
    this.host.setAttribute('aria-valuemin', String(MIN_LIST_PERCENT));
    this.host.setAttribute('aria-valuemax', String(MAX_LIST_PERCENT));
  }

  private cancelFrame(): void {
    if (this.frame !== null && typeof cancelAnimationFrame === 'function') {
      cancelAnimationFrame(this.frame);
    }
    this.frame = null;
  }

  private teardown(): void {
    this.host.removeEventListener('pointerdown', this.onPointerDown);
    this.host.removeEventListener('dblclick', this.onDblClick);
    this.host.removeEventListener('keydown', this.onKeydown);
    this.host.removeEventListener('pointermove', this.onPointerMove);
    this.host.removeEventListener('pointerup', this.onPointerUp);
    this.host.removeEventListener('pointercancel', this.onPointerUp);
    this.cancelFrame();
  }
}
