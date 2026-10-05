import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type ProgressRailOrientation = 'vertical' | 'horizontal';

/** The length cue that stands in for a scrollbar (#238): a track whose fill is the
 *  host's `--rail-fill` percentage. The caller positions the host. */
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
