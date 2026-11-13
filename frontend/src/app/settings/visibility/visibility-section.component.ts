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
import { toggled } from '../../core/toggled-set';
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
import { NETWORK_FEED_KIND_DESCRIPTORS } from '../../reader/state/social-sections';
import { buildTagTree } from '../../reader/state/subscription-derivations';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import {
  ExclusionCause,
  INCLUSION_FLAG,
  inheritedExclusions,
  withAncestors,
} from '../../reader/state/visibility';
import { VisibilitySwitchesStore } from '../../reader/state/visibility-switches.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../shared/network-logo/network-logo.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { VisibilityCell, VisibilityRowComponent } from './visibility-row.component';
import {
  VisibilityLine,
  VisibilitySources,
  VisibilityTarget,
  visibilityLines,
} from './visibility-tree';

interface VisibilityRow {
  readonly line: VisibilityLine;
  readonly name: string;
  readonly cells: readonly VisibilityCell[];
}

const SURFACES: readonly ExclusionSurface[] = FEED_FLAG_TOGGLES.map(({ surface }) => surface);

/** Every inclusion switch in one tree-shaped matrix: each box is its row's own switch and saves on tick. */
@Component({
  selector: 'app-visibility-section',
  imports: [
    TranslocoPipe,
    SettingsStackComponent,
    SettingsGroupComponent,
    SkeletonComponent,
    ButtonComponent,
    FaviconComponent,
    IconComponent,
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
  protected readonly feedKindDescriptors = NETWORK_FEED_KIND_DESCRIPTORS;
  protected readonly loading = this.subscriptions.loading;
  protected readonly feedFilter = signal('');
  private readonly expandedKeys = signal<ReadonlySet<string>>(new Set());

  private readonly sources = computed<VisibilitySources>(() => {
    const subscriptions = this.subscriptions.subscriptions();
    return {
      tagTree: buildTagTree(subscriptions, this.tags.tags()),
      socialSections: this.subscriptions.socialSections(),
      subscriptions,
    };
  });

  private readonly lines = computed(() => {
    const term = this.feedFilter().trim().toLocaleLowerCase();
    return visibilityLines(
      this.sources(),
      term === '' ? null : (subscription) => subscription.title.toLocaleLowerCase().includes(term),
    );
  });

  protected readonly filtering = computed(() => this.feedFilter().trim() !== '');

  /** From the whole tree: a filter must not narrow what Expand all records. */
  private readonly parentKeys = computed(() =>
    visibilityLines(this.sources(), null)
      .filter((line) => line.hasChildren)
      .map((line) => line.key),
  );

  protected readonly showsExpandToggle = computed(
    () => !this.filtering() && this.parentKeys().length > 0,
  );

  protected readonly allExpanded = computed(() => {
    const expanded = this.expandedKeys();
    return this.parentKeys().every((key) => expanded.has(key));
  });

  protected readonly rows = computed<VisibilityRow[]>(() => {
    this.language();
    return this.lines()
      .filter((line) => line.ancestorKeys.every((key) => this.isExpandedKey(key)))
      .map((line) => ({ line, name: this.nameOf(line.target), cells: this.cellsOf(line.target) }));
  });

  ngOnInit(): void {
    this.tags.load();
    this.subscriptions.load();
    this.savedSearches.load();
    this.allItemsNetworks.load();
    this.forYouNetworks.load();
  }

  protected isExpanded(line: VisibilityLine): boolean {
    return this.isExpandedKey(line.key);
  }

  protected toggleExpanded(line: VisibilityLine): void {
    this.expandedKeys.update((expanded) => toggled(expanded, line.key));
  }

  protected toggleExpandAll(): void {
    this.expandedKeys.set(new Set(this.allExpanded() ? [] : this.parentKeys()));
  }

  /** A filter opens every branch it keeps; clearing it brings back the reader's own choice. */
  private isExpandedKey(key: string): boolean {
    return this.filtering() || this.expandedKeys().has(key);
  }

  protected toggle(target: VisibilityTarget, surface: ExclusionSurface): void {
    switch (target.kind) {
      case 'network':
        return this.toggleNetwork(target.network, surface);
      case 'tag':
        return this.tagActions.setTagInclusion(
          target.tag,
          surface,
          !target.tag[INCLUSION_FLAG[surface]],
        );
      case 'feed':
        return this.toggleFeed(target.subscription, surface);
      case 'feedKind':
      case 'untagged':
        return;
    }
  }

  private toggleNetwork(network: SocialNetworkName, surface: ExclusionSurface): void {
    this.networkSwitches
      .find((networkSwitch) => networkSwitch.surface === surface)
      ?.toggle(network);
  }

  private toggleFeed(subscription: SubscriptionDto, surface: ExclusionSurface): void {
    const toggle = FEED_FLAG_TOGGLES.find((candidate) => candidate.surface === surface);
    if (toggle !== undefined) flipFeedFlag(this.manage, toggle, subscription);
  }

  private nameOf(target: VisibilityTarget): string {
    switch (target.kind) {
      case 'network':
        return this.networkName(target.network);
      case 'tag':
        return target.tag.name;
      case 'feed':
        return target.subscription.title;
      case 'feedKind':
        return this.transloco.translate(NETWORK_FEED_KIND_DESCRIPTORS[target.feedKind].labelKey);
      case 'untagged':
        return this.transloco.translate('settings.organise.untagged');
    }
  }

  private cellsOf(target: VisibilityTarget): VisibilityCell[] {
    switch (target.kind) {
      case 'network':
        return this.networkCells(target.network);
      case 'tag':
        return this.tagCells(target.tag);
      case 'feed':
        return this.feedCells(target.subscription);
      case 'feedKind':
      case 'untagged':
        return [];
    }
  }

  private networkCells(network: SocialNetworkName): VisibilityCell[] {
    return this.networkSwitches.map((networkSwitch) => ({
      surface: networkSwitch.surface,
      checked: !networkSwitch.isExcluded(network),
      inheritedFrom: [],
    }));
  }

  /** A subtag inherits only an ancestor's switch; its own off box needs no second reason. */
  private tagCells(tag: TagDto): VisibilityCell[] {
    const byId = new Map(this.tags.tags().map((known) => [known.id, known]));
    const ancestors = withAncestors(tag, byId).slice(1);
    return SURFACES.map((surface) => {
      const flag = INCLUSION_FLAG[surface];
      const checked = tag[flag];
      const inheritedFrom = checked
        ? ancestors.filter((ancestor) => !ancestor[flag]).map(({ name }) => name)
        : [];
      return { surface, checked, inheritedFrom };
    });
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
