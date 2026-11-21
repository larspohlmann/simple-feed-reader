import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** Three dots in a wave, sized to an icon slot: an action of this row is waiting on its answer. */
@Component({
  selector: 'app-pending-dots',
  template: '<i></i><i></i><i></i>',
  styleUrl: './pending-dots.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', '[class.md]': "size() === 'md'" },
})
export class PendingDotsComponent {
  readonly size = input<'sm' | 'md'>('sm');
}
