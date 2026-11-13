import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { SocialNetworkName } from '../../core/social-network';
import {
  ExclusionSurface,
  FEED_FLAG_TOGGLES,
  SURFACE_LABEL_KEYS,
  flipFeedFlag,
} from '../../reader/feed-exclusions';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { TagActions } from '../../reader/feeds/manage/tag-actions.service';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { AllItemsNetworksStore } from '../../reader/state/all-items-networks.store';
import { ForYouNetworksStore } from '../../reader/state/for-you-networks.store';
import { NetworkSurfaceSwitches } from '../../reader/state/network-surface-switches.service';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { tagsInTreeOrder } from '../../reader/state/subscription-derivations';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import {
  ExclusionCause,
  INCLUSION_FLAG,
  inheritedExclusions,
  withAncestors,
} from '../../reader/state/visibility';
import { VisibilitySwitchesStore } from '../../reader/state/visibility-switches.store';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { NetworkLogoComponent } from '../../shared/network-logo/network-logo.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { VisibilityCell, VisibilityRowComponent } from './visibility-row.component';

interface NetworkRow {
  readonly network: SocialNetworkName;
  readonly name: string;
  readonly cells: readonly VisibilityCell[];
}

interface TagRow {
  readonly tag: TagDto;
  readonly isSubtag: boolean;
  readonly cells: readonly VisibilityCell[];
}

interface FeedRow {
  readonly subscription: SubscriptionDto;
  readonly cells: readonly VisibilityCell[];
}

const SURFACES: readonly ExclusionSurface[] = FEED_FLAG_TOGGLES.map(({ surface }) => surface);

/** Every inclusion switch in one matrix: each box is its row's own switch and saves on tick. */
@Component({
  selector: 'app-visibility-section',
  imports: [
    TranslocoPipe,
    SettingsStackComponent,
    SettingsGroupComponent,
    SkeletonComponent,
    FaviconComponent,
    NetworkLogoComponent,
    TagGlyphComponent,
    VisibilityRowComponent,
  ],
  templateUrl: './visibility-section.component.html',
  styleUrl: './visibility-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VisibilitySectionComponent implements OnInit {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly allItemsNetworks = inject(AllItemsNetworksStore);
  private readonly forYouNetworks = inject(ForYouNetworksStore);
  private readonly networkSwitches = inject(NetworkSurfaceSwitches).all;
  private readonly visibilitySwitches = inject(VisibilitySwitchesStore).switches;
  private readonly manage = inject(ManageActions);
  private readonly tagActions = inject(TagActions);
  private readonly transloco = inject(TranslocoService);
  private readonly language = toSignal(this.transloco.langChanges$);

  protected readonly surfaces = SURFACES;
  protected readonly surfaceLabelKeys = SURFACE_LABEL_KEYS;
  protected readonly loading = this.subscriptions.loading;
  protected readonly feedFilter = signal('');

  protected readonly networkRows = computed<NetworkRow[]>(() => {
    this.language();
    return this.subscriptions.socialSections().map(({ network }) => ({
      network,
      name: this.networkName(network),
      cells: this.networkSwitches.map((networkSwitch) => ({
        surface: networkSwitch.surface,
        checked: !networkSwitch.isExcluded(network),
        inheritedFrom: [],
      })),
    }));
  });

  protected readonly tagRows = computed<TagRow[]>(() => {
    const tags = this.tags.tags();
    const byId = new Map(tags.map((tag) => [tag.id, tag]));
    return tagsInTreeOrder(tags).map((tag) => ({
      tag,
      isSubtag: tag.parentId !== null,
      cells: SURFACES.map((surface) => tagCell(tag, surface, withAncestors(tag, byId).slice(1))),
    }));
  });

  protected readonly feedRows = computed<FeedRow[]>(() => {
    this.language();
    const term = this.feedFilter().trim().toLocaleLowerCase();
    return this.subscriptions
      .subscriptions()
      .filter((subscription) => subscription.title.toLocaleLowerCase().includes(term))
      .sort((left, right) => left.title.localeCompare(right.title))
      .map((subscription) => ({ subscription, cells: this.feedCells(subscription) }));
  });

  ngOnInit(): void {
    this.tags.load();
    this.subscriptions.load();
    this.savedSearches.load();
    this.allItemsNetworks.load();
    this.forYouNetworks.load();
  }

  protected toggleNetwork(network: SocialNetworkName, surface: ExclusionSurface): void {
    this.networkSwitches
      .find((networkSwitch) => networkSwitch.surface === surface)
      ?.toggle(network);
  }

  protected toggleTag(tag: TagDto, surface: ExclusionSurface): void {
    this.tagActions.setTagInclusion(tag, surface, !tag[INCLUSION_FLAG[surface]]);
  }

  protected toggleFeed(subscription: SubscriptionDto, surface: ExclusionSurface): void {
    const toggle = FEED_FLAG_TOGGLES.find((candidate) => candidate.surface === surface);
    if (toggle !== undefined) flipFeedFlag(this.manage, toggle, subscription);
  }

  private feedCells(subscription: SubscriptionDto): VisibilityCell[] {
    const switches = this.visibilitySwitches();
    return SURFACES.map((surface) => {
      const checked = subscription[INCLUSION_FLAG[surface]];
      const causes = checked ? inheritedExclusions(subscription, surface, switches) : [];
      return { surface, checked, inheritedFrom: causes.map((cause) => this.causeName(cause)) };
    });
  }

  private causeName(cause: ExclusionCause): string {
    return cause.kind === 'network' ? this.networkName(cause.network) : cause.tag.name;
  }

  private networkName(network: SocialNetworkName): string {
    return this.transloco.translate(`settings.social.${network}`);
  }
}

/** A subtag inherits only an ancestor's switch; its own off box needs no second reason. */
function tagCell(
  tag: TagDto,
  surface: ExclusionSurface,
  ancestors: readonly TagDto[],
): VisibilityCell {
  const flag = INCLUSION_FLAG[surface];
  const checked = tag[flag];
  const inheritedFrom = checked
    ? ancestors.filter((ancestor) => !ancestor[flag]).map(({ name }) => name)
    : [];
  return { surface, checked, inheritedFrom };
}
