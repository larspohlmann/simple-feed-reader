import { type FocusCurve, focusOpacityForSpan } from './reading-focus';
import { type FocusUnit, focusUnits } from './reading-sections';

export interface ReadingFocusConfig {
  readonly scroller: HTMLElement;
  readonly blocks: () => HTMLElement[];
  readonly curve: FocusCurve;
  /** enabled && !isWide && !reduceMotion — read live, off the reactive graph. */
  readonly isActive: () => boolean;
  readonly runOutsideZone?: <T>(run: () => T) => T;
  /** Split a block taller than the phone screen into reading sections (#1077).
   *  The article view sets it; the entry list keeps one unit per row. */
  readonly split?: boolean;
  /** The document language, for sentence splitting. */
  readonly lang?: () => string;
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
  /** The current grouping; recomputed only when the geometry changes, not on
   *  scroll (#982). One element per unit unless a tall block was split. */
  private units: FocusUnit[] = [];
  private regroupPending = true;
  /** Every element carrying an opacity we set, so a unit that disappears — a
   *  block's spans once it is no longer tall — gets cleared, not stranded. */
  private written = new Set<HTMLElement>();

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
      this.regroupPending = true;
      return;
    }
    if (this.regroupPending) {
      this.units = this.regroup();
      this.regroupPending = false;
    }
    this.applyOpacities(this.measureOpacities());
  }

  private regroup(): FocusUnit[] {
    const { scroller, blocks, split, lang } = this.config;
    if (!split) return blocks().map((block) => [block]);
    return focusUnits(
      blocks(),
      scroller.clientHeight,
      (element) => element.getBoundingClientRect().height,
      lang?.() ?? document.documentElement.lang,
    );
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
    const next = new Set<HTMLElement>();
    this.units.forEach((unit, index) => {
      for (const element of unit) {
        if (element.style.opacity !== opacities[index]) element.style.opacity = opacities[index];
        next.add(element);
      }
    });
    for (const element of this.written) if (!next.has(element)) element.style.opacity = '';
    this.written = next;
  }

  private blankAll(): void {
    for (const element of this.written) element.style.opacity = '';
    this.written.clear();
    for (const block of this.config.blocks()) block.style.opacity = '';
  }
}
