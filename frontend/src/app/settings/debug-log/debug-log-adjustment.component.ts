import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DebugLogAdjustment } from '../settings.models';

/** The change a fallback made after the call above it was rejected. */
@Component({
  selector: 'app-debug-log-adjustment',
  imports: [TranslocoPipe],
  templateUrl: './debug-log-adjustment.component.html',
  styleUrl: './debug-log-adjustment.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DebugLogAdjustmentComponent {
  readonly adjustment = input.required<DebugLogAdjustment>();
}
