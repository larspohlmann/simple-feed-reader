// src/app/shared/segmented-choice/segmented-choice.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

/**
 * A small segmented control over a fixed set of string options, labelled by
 * `<labelPrefix><option>` translation keys.
 */
@Component({
  selector: 'app-segmented-choice',
  imports: [TranslocoPipe],
  templateUrl: './segmented-choice.component.html',
  styleUrl: './segmented-choice.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SegmentedChoiceComponent<T extends string> {
  readonly options = input.required<readonly T[]>();
  readonly selected = input.required<T>();
  readonly ariaLabelKey = input.required<string>();
  readonly labelPrefix = input.required<string>();
  readonly pick = output<T>();
}
