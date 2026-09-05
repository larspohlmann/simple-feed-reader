import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { formatLongDateTime } from '../../reader/format';
import { SubscriptionDto } from '../../reader/models';

/** The facts grid inside an unhealthy feed's expanded row: its URL as an
 *  external link, the last-success and last-attempt timestamps and the failure
 *  streak. Extracted from the row so the row template stays lean and these rows
 *  carry their own tests — it is the row's block, not a cross-surface shared
 *  one: the health-error dialog deliberately renders a different grid (its own
 *  order, right-aligned values, failure streak promoted to a stat figure). The
 *  raw fetcher error is rendered by the row separately, below this grid. */
@Component({
  selector: 'app-feed-health-facts',
  imports: [TranslocoPipe],
  templateUrl: './feed-health-facts.component.html',
  styleUrl: './feed-health-facts.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedHealthFactsComponent {
  readonly subscription = input.required<SubscriptionDto>();

  private readonly language = inject(LanguageService);

  /** An absolute date-and-time (HH:MM), not a pipe: these are technical facts,
   *  so an exact timestamp beats a relative "3 days ago". */
  protected formatDateTime(iso: string): string {
    return formatLongDateTime(iso, this.language.lang());
  }
}
