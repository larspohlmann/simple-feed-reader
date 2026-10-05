import { ChangeDetectionStrategy, Component } from '@angular/core';

/** The phone's length cue (#238): a track on the right edge whose fill is the
 *  host's `--rail-fill` percentage. The caller positions the host. */
@Component({
  selector: 'app-progress-rail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true' },
  template: '<i></i>',
  styleUrl: './progress-rail.component.scss',
})
export class ProgressRailComponent {}
