import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ExclusionSurface, SURFACE_LABEL_KEYS } from '../../reader/feed-exclusions';
import { IconComponent } from '../../shared/icon/icon.component';

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
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './visibility-row.component.html',
  styleUrl: './visibility-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VisibilityRowComponent {
  readonly name = input.required<string>();
  readonly cells = input.required<readonly VisibilityCell[]>();
  readonly toggled = output<ExclusionSurface>();

  protected readonly surfaceLabelKeys = SURFACE_LABEL_KEYS;
}
