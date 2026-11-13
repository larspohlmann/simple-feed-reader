import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ExclusionSurface, SURFACE_LABEL_KEYS } from '../../reader/feed-exclusions';
import { IconComponent } from '../../shared/icon/icon.component';

let nextId = 0;

/** One box of the matrix: the row's own switch, and what above it keeps the row out regardless. */
export interface VisibilityCell {
  readonly surface: ExclusionSurface;
  readonly checked: boolean;
  /** Empty unless the box is on and something above the row still excludes it. */
  readonly inheritedFrom: readonly string[];
}

/** A named row of the Visibility matrix; its icon projects in front of the name. */
@Component({
  selector: 'app-visibility-row',
  host: { '[style.--visibility-depth]': 'depth()' },
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './visibility-row.component.html',
  styleUrl: './visibility-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VisibilityRowComponent {
  readonly name = input.required<string>();
  readonly cells = input.required<readonly VisibilityCell[]>();
  readonly depth = input(0);
  /** Left unset on a row with nothing beneath it, which then shows no chevron. */
  readonly expanded = input<boolean>();
  readonly toggled = output<ExclusionSurface>();
  readonly expandToggled = output<void>();

  protected readonly surfaceLabelKeys = SURFACE_LABEL_KEYS;
  protected readonly id = `visibility-row-${nextId++}`;
}
