import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** A quiet, non-sticky rule across the list with a label at its start: a for-you run's boundary, or where a
 *  followed story's new entries end. */
@Component({
  selector: 'app-list-divider',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './list-divider.component.html',
  styleUrl: './list-divider.component.scss',
})
export class ListDividerComponent {
  readonly label = input.required<string>();
}
