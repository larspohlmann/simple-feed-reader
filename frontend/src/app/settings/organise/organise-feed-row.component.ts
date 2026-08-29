// src/app/settings/organise/organise-feed-row.component.ts
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
import { CdkDragHandle } from '@angular/cdk/drag-drop';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';

/**
 * One feed row on the Organise page. Presentational on purpose: the tree and
 * the flat list both render it, and only the parent knows what "move up" means
 * in its own context, so the row emits and never writes.
 */
@Component({
  selector: 'app-organise-feed-row',
  imports: [
    TranslocoPipe,
    IconComponent,
    FaviconComponent,
    TagGlyphComponent,
    CdkDragHandle,
    DismissOnOutsideDirective,
    IconButtonDirective,
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
  readonly toggleAllItems = output<void>();
  readonly toggleForYou = output<void>();
  readonly unsubscribe = output<void>();

  protected readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly menuOpen = signal(false);

  protected readonly visibleTags = computed(() =>
    this.subscription().tags.filter((tag) => tag.id !== this.hideTagId()),
  );

  protected toggleMenu(): void {
    this.menuOpen.update((open) => !open);
  }

  protected closeMenu(): void {
    this.menuOpen.set(false);
  }

  /** Coarse pointers get the action sheet the sidebar already uses; a hover
   *  popover has nothing to hover. */
  protected openSheet(): void {
    const sub = this.subscription();
    this.sheet
      .open({
        title: sub.title,
        actions: [
          { id: 'edit', label: this.i18n.translate('reader.editFeed') },
          {
            id: 'allItems',
            label: this.i18n.translate(
              sub.includeInAllItems ? 'reader.excludeFromAllItems' : 'reader.includeInAllItems',
            ),
          },
          {
            id: 'forYou',
            label: this.i18n.translate(
              sub.includeInForYou ? 'reader.excludeFromForYou' : 'reader.includeInForYou',
            ),
          },
          { id: 'unsubscribe', label: this.i18n.translate('reader.unsubscribe'), danger: true },
        ],
      })
      // The sheet can outlive this row (a reload replaces the list); a late
      // choice must not emit into a destroyed output.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.edit.emit();
        if (choice === 'allItems') this.toggleAllItems.emit();
        if (choice === 'forYou') this.toggleForYou.emit();
        if (choice === 'unsubscribe') this.unsubscribe.emit();
      });
  }
}
