import { type FocusCurve, focusOpacityForSpan } from './reading-focus';
import type { FocusUnit, UnitStrategy } from './reading-sections';

export interface ReadingFocusConfig {
  readonly scroller: HTMLElement;
  readonly blocks: () => HTMLElement[];
  readonly curve: FocusCurve;
  /** enabled && !isWide && !reduceMotion — read live, off the reactive graph. */
  readonly isActive: () => boolean;
  readonly runOutsideZone?: <T>(run: () => T) => T;
  /** Divides the blocks into focus units; without one, each block is its own. */
  readonly units?: UnitStrategy;
}

/**
 * Keeps each block's inline opacity in step with its distance from the scroll
 * centre. It observes the geometry it reads — the scroller and every block —
 * so it never enumerates the causes of a layout change.
 */
export class ReadingFocusApplier {
  private readonly runOutsideZone: <T>(run: () => T) => T;
  private readonly observer?: ResizeObserver;
  private readonly onScroll = (): void => this.schedule();
  private frame = 0;
  private destroyed = false;
  private units: FocusUnit[] = [];
  private regroupPending = true;

  constructor(private readonly config: ReadingFocusConfig) {
    this.runOutsideZone = config.runOutsideZone ?? ((run) => run());
    this.runOutsideZone(() =>
      config.scroller.addEventListener('scroll', this.onScroll, { passive: true }),
    );
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.scheduleRegroup());
    }
    this.observe();
    this.schedule();
  }

  refresh(): void {
    this.observe();
    this.scheduleRegroup();
  }

  /** Blank every target now and drop a pending pass — a disable must clear the
   *  same tick, not a frame later. */
  clear(): void {
    this.cancel();
    this.blankAll();
  }

  destroy(): void {
    this.destroyed = true;
    this.cancel();
    this.observer?.disconnect();
    this.config.scroller.removeEventListener('scroll', this.onScroll);
  }

  private observe(): void {
    if (!this.observer) return;
    this.observer.disconnect();
    this.observer.observe(this.config.scroller);
    for (const block of this.config.blocks()) this.observer.observe(block);
  }

  private scheduleRegroup(): void {
    this.regroupPending = true;
    this.schedule();
  }

  private schedule(): void {
    if (this.destroyed || this.frame || typeof requestAnimationFrame === 'undefined') return;
    this.frame = this.runOutsideZone(() =>
      requestAnimationFrame(() => {
        this.frame = 0;
        this.recompute();
      }),
    );
  }

  private cancel(): void {
    if (this.frame && typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(this.frame);
    this.frame = 0;
  }

  private recompute(): void {
    if (!this.config.isActive()) {
      this.blankAll();
      return;
    }
    const dropped = this.regroup();
    this.applyOpacities(this.measureOpacities());
    for (const element of dropped) element.style.opacity = '';
  }

  /**
   * Returns the elements that left the grouping. A unit strategy reruns only on
   * a geometry change, never on scroll (#982); bare blocks are reread every
   * pass, so a row revealed between two refreshes still gets its opacity.
   */
  private regroup(): HTMLElement[] {
    const { scroller, blocks, units } = this.config;
    if (!units) {
      this.units = blocks().map((block) => [block]);
      return [];
    }
    if (!this.regroupPending) return [];
    const previous = this.units.flat();
    this.units = units(blocks(), scroller.clientHeight);
    this.regroupPending = false;
    const kept = new Set(this.units.flat());
    return previous.filter((element) => !kept.has(element));
  }

  private measureOpacities(): string[] {
    const { scroller, curve } = this.config;
    const viewport = scroller.clientHeight;
    const scrollerTop = scroller.getBoundingClientRect().top;
    return this.units.map((unit) => {
      const first = unit[0].getBoundingClientRect();
      const last = unit.length === 1 ? first : unit[unit.length - 1].getBoundingClientRect();
      const top = first.top - scrollerTop;
      const bottom = last.top + last.height - scrollerTop;
      return String(focusOpacityForSpan(top, bottom, viewport, curve));
    });
  }

  private applyOpacities(opacities: string[]): void {
    this.units.forEach((unit, index) => {
      for (const element of unit) {
        if (element.style.opacity !== opacities[index]) element.style.opacity = opacities[index];
      }
    });
  }

  private blankAll(): void {
    for (const element of this.units.flat()) element.style.opacity = '';
    this.units = [];
    this.regroupPending = true;
    for (const block of this.config.blocks()) block.style.opacity = '';
  }
}
