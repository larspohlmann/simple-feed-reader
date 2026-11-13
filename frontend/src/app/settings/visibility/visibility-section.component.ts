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
  EXCLUSION_SURFACES,
  ExclusionSurface,
  SURFACE_LABEL_KEYS,
  flipFeedFlag,
  isIncludedIn,
} from '../../reader/feed-exclusions';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { TagActions } from '../../reader/feeds/manage/tag-actions.service';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { NetworkSurfaceSwitches } from '../../reader/state/network-surface-switches.service';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { NETWORK_FEED_KIND_DESCRIPTORS } from '../../reader/state/social-sections';
import { buildTagTree, titleMatcher } from '../../reader/state/subscription-derivations';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { withAncestors } from '../../reader/state/tag-ancestry';
import { ExclusionCause, inheritedExclusions } from '../../reader/state/visibility';
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
  private readonly networkSurfaceSwitches = inject(NetworkSurfaceSwitches);
  private readonly visibilitySwitches = inject(VisibilitySwitchesStore).switches;
  private readonly manage = inject(ManageActions);
  private readonly tagActions = inject(TagActions);
  private readonly transloco = inject(TranslocoService);
  private readonly language = toSignal(this.transloco.langChanges$);

  protected readonly surfaces = EXCLUSION_SURFACES;
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

  private readonly allLines = computed(() => visibilityLines(this.sources(), null));

  private readonly matchesTitle = computed(() => titleMatcher(this.feedFilter()));
  protected readonly filtering = computed(() => this.matchesTitle() !== null);

  private readonly lines = computed(() => {
    const matches = this.matchesTitle();
    return matches === null ? this.allLines() : visibilityLines(this.sources(), matches);
  });

  /** From the whole tree: a filter must not narrow what Expand all records. */
  private readonly parentKeys = computed(() =>
    this.allLines()
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

  /** Every line's name and boxes, whatever is expanded: opening a branch recomputes none of them. */
  private readonly namedLines = computed<VisibilityRow[]>(() => {
    this.language();
    return this.lines().map((line) => ({
      line,
      name: this.nameOf(line.target),
      cells: this.cellsOf(line.target),
    }));
  });

  protected readonly rows = computed(() =>
    this.namedLines().filter(({ line }) =>
      line.ancestorKeys.every((key) => this.isExpandedKey(key)),
    ),
  );

  ngOnInit(): void {
    this.tags.loadIfStale();
    this.subscriptions.loadIfStale();
    this.savedSearches.reloadIfStale();
    this.networkSurfaceSwitches.loadExclusionsIfStale();
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
        return this.networkSurfaceSwitches.toggle(target.network, surface);
      case 'tag':
        return this.tagActions.setTagInclusion(
          target.tag,
          surface,
          !isIncludedIn(target.tag, surface),
        );
      case 'feed':
        return flipFeedFlag(this.manage, surface, target.subscription);
      case 'feedKind':
      case 'untagged':
        return;
    }
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
    return EXCLUSION_SURFACES.map((surface) => ({
      surface,
      checked: !this.networkSurfaceSwitches.isExcluded(network, surface),
      inheritedFrom: [],
    }));
  }

  /** A subtag inherits only an ancestor's switch; its own off box needs no second reason. */
  private tagCells(tag: TagDto): VisibilityCell[] {
    const ancestors = withAncestors(tag, this.tags.tagsById()).slice(1);
    return EXCLUSION_SURFACES.map((surface) => {
      const checked = isIncludedIn(tag, surface);
      const inheritedFrom = checked
        ? ancestors.filter((ancestor) => !isIncludedIn(ancestor, surface)).map(({ name }) => name)
        : [];
      return { surface, checked, inheritedFrom };
    });
  }

  private feedCells(subscription: SubscriptionDto): VisibilityCell[] {
    const switches = this.visibilitySwitches();
    return EXCLUSION_SURFACES.map((surface) => {
      const checked = isIncludedIn(subscription, surface);
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
