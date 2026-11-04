import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { Params, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';

/**
 * A small segmented control over a fixed set of string options, labelled by
 * `<labelPrefix><option>` translation keys. A disabled option stays visible;
 * the consumer says beside the control why it cannot be picked, and passes
 * that text's id as `describedBy`. An option with an entry in `counts` shows
 * that number after its label. With `links` the options are links that merge
 * their query params into the URL, which then owns the selection: no `pick`.
 */
@Component({
  selector: 'app-segmented-choice',
  imports: [TranslocoPipe, RouterLink, NgTemplateOutlet],
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
  readonly counts = input<Partial<Record<T, number>>>({});
  readonly links = input<Readonly<Record<T, Params>> | null>(null);
  readonly pick = output<T>();

  protected countOf(option: T): number | undefined {
    return this.counts()[option];
  }
}
