import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { formatLongDate } from '../../reader/format';
import { feedHealthReason, isGone as feedIsGone } from '../../reader/feed-health';
import { SubscriptionDto } from '../../reader/models';
import { ButtonComponent } from '../../shared/button/button.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';

/** One row in the unhealthy-feeds list: favicon, title, a status pill, a
 *  friendly reason line, Retry and Unsubscribe, and an inline details
 *  disclosure. Presentational — it emits and never writes. */
@Component({
  selector: 'app-unhealthy-feed-row',
  imports: [TranslocoPipe, FaviconComponent, ButtonComponent, DisclosureComponent],
  templateUrl: './unhealthy-feed-row.component.html',
  styleUrl: './unhealthy-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnhealthyFeedRowComponent {
  readonly subscription = input.required<SubscriptionDto>();
  readonly retry = output<void>();
  readonly unsubscribe = output<void>();

  private readonly language = inject(LanguageService);

  protected readonly reason = computed(() => feedHealthReason(this.subscription(), new Date()));
  protected readonly isGone = computed(() => feedIsGone(this.subscription()));

  /** A row-scoped absolute-date formatter, not a pipe: these are technical
   *  facts inside a details disclosure, not reading-flow copy, so an exact
   *  date beats a relative "3 days ago". */
  protected formatDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }
}
