import { DestroyRef, Directive, ElementRef, NgZone, effect, inject, input } from '@angular/core';
import { ScrollProgressRail } from './scroll-progress-rail';

/** Marks the scroller a `ScrollProgressRail` reports on: repaints it on every scroll,
 *  outside the zone (#501), and hides the scrollbar the rail stands in for. */
@Directive({
  selector: '[appScrollProgress]',
  host: { '[class.scrollbar-hidden]': 'progress().replacesScrollbar()' },
})
export class ScrollProgressDirective {
  readonly progress = input.required<ScrollProgressRail>({ alias: 'appScrollProgress' });

  constructor() {
    const host: HTMLElement = inject(ElementRef).nativeElement;
    const onScroll = (): void => this.progress().paint();
    inject(NgZone).runOutsideAngular(() =>
      host.addEventListener('scroll', onScroll, { passive: true }),
    );
    inject(DestroyRef).onDestroy(() => host.removeEventListener('scroll', onScroll));
    effect((onCleanup) => {
      const progress = this.progress();
      progress.attachScroller(host);
      onCleanup(() => progress.detachScroller(host));
    });
  }
}
