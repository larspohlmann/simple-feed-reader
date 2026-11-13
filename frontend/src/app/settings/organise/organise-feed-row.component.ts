import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { RouterLink } from '@angular/router';
import { CdkDragHandle } from '@angular/cdk/drag-drop';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { FeedRefreshTimesComponent } from './health/feed-refresh-times.component';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { stepMenu } from '../../shared/menu-keys';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetCheck,
} from '../../shared/action-sheet/action-sheet.component';
import { MenuCheckboxComponent } from '../../shared/menu-checkbox/menu-checkbox.component';
import { LayoutService } from '../../reader/layout.service';
import { selectionQueryParams } from '../../reader/query/query';
import { SubscriptionDto } from '../../reader/models';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import {
  ExclusionSurface,
  SURFACE_DESCRIPTORS,
  flipFeedFlag,
  isIncludedIn,
  ownExcludedSurfaces,
  surfaceChecks,
} from '../../reader/feed-exclusions';

/**
 * One feed row on the Organise page. The tree and the flat list both render it,
 * and only the parent knows what "move up" means in its own context, so moves
 * are emitted; the row's own surface switches it flips itself, like a sidebar row.
 */
@Component({
  selector: 'app-organise-feed-row',
  imports: [
    TranslocoPipe,
    IconComponent,
    FaviconComponent,
    TagGlyphComponent,
    CdkDragHandle,
    RouterLink,
    DismissOnOutsideDirective,
    IconButtonDirective,
    FeedRefreshTimesComponent,
    MenuCheckboxComponent,
  ],
  templateUrl: './organise-feed-row.component.html',
  styleUrl: './organise-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseFeedRowComponent {
  readonly subscription = input.required<SubscriptionDto>();
  readonly selected = input(false);
  /** Whether this row can be dragged: false in the flat list, and false on a
   *  coarse pointer. It hides the handle only — the arrows are governed by
   *  `reorderable`, so a phone keeps them. */
  readonly sortable = input(false);
  /** Whether this row belongs to an ordered group at all. False only in the
   *  flat list, which has no one order to change. */
  readonly reorderable = input(false);
  readonly canMoveUp = input(false);
  readonly canMoveDown = input(false);
  /** The id of the tag whose own group this row is rendered inside, so its
   *  pill can be left out -- a tag panel already says which tag a feed sits
   *  under, so repeating it as the row's own pill is redundant by
   *  construction (#659). `null` in the flat list, where pills are the only
   *  place a feed's tags show at all. */
  readonly hideTagId = input<number | null>(null);

  readonly selectedChange = output<boolean>();
  readonly moveUp = output<void>();
  readonly moveDown = output<void>();
  readonly edit = output<void>();
  readonly unsubscribe = output<void>();

  protected readonly feedFlags = SURFACE_DESCRIPTORS;
  protected readonly step = stepMenu;

  protected readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly manage = inject(ManageActions);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly menuOpen = signal(false);

  protected readonly excluded = computed(() => ownExcludedSurfaces(this.subscription()).length > 0);

  protected readonly visibleTags = computed(() =>
    this.subscription().tags.filter((tag) => tag.id !== this.hideTagId()),
  );

  /** The reader query that opens this feed's own list view — the target of the
   *  feed-name link, opened in a new tab. */
  protected readonly listParams = computed(() =>
    selectionQueryParams({ subscription: this.subscription().id }),
  );

  protected toggleFlag(surface: ExclusionSurface): void {
    flipFeedFlag(this.manage, surface, this.subscription());
  }

  protected toggleMenu(): void {
    this.menuOpen.update((open) => !open);
  }

  protected closeMenu(): void {
    this.menuOpen.set(false);
  }

  /** Coarse pointers get the action sheet the sidebar already uses; a hover
   *  popover has nothing to hover. */
  protected openSheet(): void {
    this.sheet
      .open({
        title: this.subscription().title,
        actions: [
          { id: 'edit', label: this.i18n.translate('reader.editFeed') },
          ACTION_SHEET_DIVIDER,
          ...this.flagChecks(),
          ACTION_SHEET_DIVIDER,
          { id: 'unsubscribe', label: this.i18n.translate('reader.unsubscribe'), danger: true },
        ],
      })
      // The sheet can outlive this row (a reload replaces the list); a late
      // choice must not emit into a destroyed output.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.edit.emit();
        if (choice === 'unsubscribe') this.unsubscribe.emit();
      });
  }

  private flagChecks(): ActionSheetCheck[] {
    return surfaceChecks(this.i18n, {
      isIncluded: (surface) => isIncludedIn(this.subscription(), surface),
      flip: (surface) => this.toggleFlag(surface),
    });
  }
}
