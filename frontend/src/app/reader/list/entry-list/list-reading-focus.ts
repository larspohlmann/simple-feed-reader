import { DestroyRef, NgZone, Signal, effect, inject } from '@angular/core';
import { LIST_FOCUS_CURVE } from '../../article/reading/reading-focus';
import { ReadingFocusApplier } from '../../article/reading/reading-focus-applier';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { LayoutService } from '../../layout.service';
import { EntryDto } from '../../models';
import { Selection } from '../../query/query';

export interface ListReadingFocusOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly rendered: Signal<EntryDto[]>;
  readonly entries: Signal<EntryDto[]>;
  readonly selection: Signal<Selection>;
  readonly reduceMotion: boolean;
}

/** Binds the reading-focus dimming to the list scroller. Built in a field
 *  initializer, so its effects are created there. */
export class ListReadingFocus {
  private readonly readingFocus = inject(ReadingFocusService);
  private readonly screen = inject(LayoutService);
  private readonly zone = inject(NgZone);
  private applier?: ReadingFocusApplier;

  // (Re)build the applier when the scroller element appears or swaps (skeleton ->
  // list, list <-> magazine). Its constructor runs the first pass and starts
  // observing; nothing here enumerates the causes of a later geometry change.
  private readonly _bindReadingFocus = effect(() => {
    const scroller = this.options.scroller();
    this.applier?.destroy();
    this.applier = undefined;
    if (!scroller) return;
    this.applier = new ReadingFocusApplier({
      scroller,
      blocks: () =>
        (Array.from(scroller.children) as HTMLElement[]).filter(
          (child) => !child.classList.contains('foot'),
        ),
      curve: LIST_FOCUS_CURVE,
      isActive: () =>
        this.readingFocus.enabled() && !this.screen.isWide() && !this.options.reduceMotion,
      runOutsideZone: (run) => this.zone.runOutsideAngular(run),
    });
  });

  // The inputs with no geometric signature: the enable gate and the rendered set
  // (a load, a view switch's retained rows (#254, #462), a fully revealed append —
  // the scroll listener covers the reveal steps in between).
  private readonly _pushReadingFocus = effect(() => {
    const enabled = this.readingFocus.enabled();
    const rendered = this.options.rendered();
    this.options.selection();
    const applier = this.applier;
    if (!applier) return;
    if (!enabled) {
      applier.clear();
      return;
    }
    if (rendered === this.options.entries()) applier.refresh();
  });

  constructor(private readonly options: ListReadingFocusOptions) {
    inject(DestroyRef).onDestroy(() => this.applier?.destroy());
  }
}
