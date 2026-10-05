import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  effect,
  inject,
  input,
} from '@angular/core';
import { ScrollProgressRail } from './scroll-progress-rail';

/** The visible half of a `ScrollProgressRail`: a track along the right or bottom edge
 *  of the nearest positioned ancestor, shown only while the content overflows. */
@Component({
  selector: 'app-progress-rail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[class.horizontal]': 'progress().horizontal()',
    '[class.idle]': '!progress().overflows()',
  },
  template: '<i></i>',
  styleUrl: './progress-rail.component.scss',
})
export class ProgressRailComponent {
  readonly progress = input.required<ScrollProgressRail>();

  constructor() {
    const host: HTMLElement = inject(ElementRef).nativeElement;
    effect((onCleanup) => {
      const progress = this.progress();
      progress.attachRail(host);
      onCleanup(() => progress.detachRail(host));
    });
  }
}
