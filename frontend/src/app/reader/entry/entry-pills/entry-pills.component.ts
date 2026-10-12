import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';
import { selectionQueryParams } from '../../query/query';
import { SubscriptionRows } from '../../state/subscription-rows';

/**
 * The network pill of a social feed, the Short pill, tag pills, then saved-search pills, as one wrapping list. Clicks stop
 * propagating so a pill inside a clickable card follows its link instead.
 */
@Component({
  selector: 'app-entry-pills',
  imports: [RouterLink, TranslocoPipe, IconComponent, TagGlyphComponent, NetworkLogoComponent],
  templateUrl: './entry-pills.component.html',
  styleUrl: './entry-pills.component.scss',
})
export class EntryPillsComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly savedSearches = input<SavedSearchMembershipDto[]>([]);
  readonly short = input(false);
  readonly subscriptionId = input<number | null>(null);

  private readonly rows = inject(SubscriptionRows);
  protected readonly network = computed(() => {
    const subscriptionId = this.subscriptionId();
    return subscriptionId === null ? null : this.rows.networkOf(subscriptionId);
  });
}
