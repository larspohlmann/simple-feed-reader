import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';
import { selectionQueryParams } from '../../query/query';
import { networkOf } from '../../state/social-sections';
import { SubscriptionRows } from '../../state/subscription-rows';

/**
 * The feed pill of a social post (network logo, feed title), the Short pill, tag pills, then saved-search pills, as
 * one wrapping list. Clicks stop propagating so a pill inside a clickable card follows its link instead.
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
  private readonly feed = computed(() => {
    const subscriptionId = this.subscriptionId();
    return subscriptionId === null ? null : this.rows.find(subscriptionId);
  });
  protected readonly network = computed(() => {
    const feed = this.feed();
    return feed === null ? null : networkOf(feed);
  });
  protected readonly feedTitle = computed(() => this.feed()?.title ?? '');
}
