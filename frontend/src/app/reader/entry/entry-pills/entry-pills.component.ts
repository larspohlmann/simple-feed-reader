import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { byLabelName } from '../../labels/label-order';
import { LabelMenu } from '../../labels/label-menu.service';
import { LabelPill } from '../../labels/labels.models';
import { EntryDto, SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';
import { selectionQueryParams } from '../../query/query';
import { networkOf } from '../../state/social-sections';
import { injectSelectionOnScreen } from '../../shell/list-cover';
import { SubscriptionRows } from '../../state/subscription-rows';

const LABELS_SHOWN = 3;

/**
 * The feed pill of a social post (network logo, feed title), the Short pill, tag pills, then saved-search pills, then the labels of a favorite or kept entry, as
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
  /** The entry whose labels follow the saved-search pills; they show only while it is a favorite or kept. */
  readonly labelled = input<EntryDto | null>(null);

  protected readonly labelMenu = inject(LabelMenu);
  protected readonly actions = inject(EntryActionHandler);
  private readonly selection = injectSelectionOnScreen();

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

  private readonly labelledEntry = computed(() => {
    const entry = this.labelled();
    return entry !== null && (entry.isFavorite || entry.isKept) ? entry : null;
  });
  protected readonly labelPills = computed<LabelPill[]>(() =>
    [...(this.labelledEntry()?.labels ?? [])].sort(byLabelName).slice(0, LABELS_SHOWN),
  );
  protected readonly hiddenLabelCount = computed(() =>
    Math.max(0, (this.labelledEntry()?.labels.length ?? 0) - LABELS_SHOWN),
  );
  protected readonly labelView = computed<'favorites' | 'kept'>(() => {
    const kind = this.selection()?.kind;
    if (kind === 'favorites' || kind === 'kept') return kind;
    return this.labelled()?.isFavorite ? 'favorites' : 'kept';
  });
}
