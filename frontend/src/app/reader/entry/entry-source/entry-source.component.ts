import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { EntryDto } from '../../models';
import { selectionQueryParams } from '../../query/query';
import { isSavedFeed } from '../../state/social-sections';
import { SubscriptionRows } from '../../state/subscription-rows';

/** Where the source sits: a list row names it unlinked, a magazine kicker and the article link the feed's list. */
export type EntrySourceLayout = 'row' | 'kicker' | 'article';

/**
 * An entry's favicon and source: the poster when someone other than the feed posted it, linking their profile
 * outside the app; else the feed by its display name. Renders straight into the host's line, flex row or text run.
 */
@Component({
  selector: 'app-entry-source',
  imports: [FaviconComponent, RouterLink],
  templateUrl: './entry-source.component.html',
  styleUrl: './entry-source.component.scss',
  host: { '[class]': 'layout()' },
})
export class EntrySourceComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly entry = input.required<EntryDto>();
  readonly layout = input.required<EntrySourceLayout>();
  readonly size = input(16);

  private readonly rows = inject(SubscriptionRows);
  protected readonly name = computed(() => {
    const entry = this.entry();
    return entry.poster?.name ?? this.rows.find(entry.subscriptionId)?.title ?? entry.source;
  });

  /** A saved post names the feed it now lives in after its poster or origin. */
  protected readonly savedIn = computed(() => {
    const row = this.rows.find(this.entry().subscriptionId);
    return row && isSavedFeed(row) && this.entry().poster ? row.title : null;
  });

  /** In a list the line sits inside a card that opens the entry; the source's own link must not open it too. */
  protected keepFromCard(event: Event): void {
    if (this.layout() !== 'article') event.stopPropagation();
  }
}
