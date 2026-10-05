import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

/**
 * A small segmented control over a fixed set of string options, labelled by
 * `<labelPrefix><option>` translation keys. A disabled option stays visible;
 * the consumer says beside the control why it cannot be picked, and passes
 * that text's id as `describedBy`.
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
  readonly disabledOptions = input<readonly T[]>([]);
  readonly describedBy = input<string | null>(null);
  readonly pick = output<T>();
}
