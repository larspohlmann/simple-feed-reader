import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type ProgressRailOrientation = 'vertical' | 'horizontal';

/** A scroller's length cue: a track along the right or bottom edge of the nearest
 *  positioned ancestor, filled to the host's `--rail-fill` percentage. */
@Component({
  selector: 'app-progress-rail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', '[class.horizontal]': "orientation() === 'horizontal'" },
  template: '<i></i>',
  styleUrl: './progress-rail.component.scss',
})
export class ProgressRailComponent {
  readonly orientation = input<ProgressRailOrientation>('vertical');
}
