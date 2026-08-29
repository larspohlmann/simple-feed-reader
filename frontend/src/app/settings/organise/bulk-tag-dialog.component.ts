// src/app/settings/organise/bulk-tag-dialog.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { pluralKey } from '../../core/plural-key';

export interface BulkTagDialogData {
  readonly mode: 'add' | 'remove';
  readonly subscriptions: SubscriptionDto[];
  readonly tags: TagDto[];
}

/**
 * Choose one tag to add to, or remove from, the current selection.
 *
 * Nothing is written here: the dialog closes with the chosen tag and the caller
 * performs the bulk write through ManageActions. That keeps the one-write-path
 * rule intact and makes a wrong click free — until Apply, the user has changed
 * nothing.
 */
@Component({
  selector: 'app-bulk-tag-dialog',
  imports: [A11yModule, TranslocoPipe, OverlayPanelComponent, ButtonComponent, TagGlyphComponent],
  templateUrl: './bulk-tag-dialog.component.html',
  styleUrl: './bulk-tag-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BulkTagDialogComponent {
  readonly ref = inject<DialogRef<TagDto | undefined>>(DialogRef);
  readonly data = inject<BulkTagDialogData>(DIALOG_DATA);

  readonly chosen = signal<TagDto | null>(null);

  readonly total = this.data.subscriptions.length;

  private readonly isAddMode = this.data.mode === 'add';

  /** i18n key for the panel heading — resolved once here rather than three
   *  times in the template (also `effectKey`, `applyKey` below). `total` is
   *  fixed for the dialog's lifetime, so this needs no signal. */
  readonly titleKey = pluralKey(
    this.isAddMode ? 'settings.organise.addTagTitle' : 'settings.organise.removeTagTitle',
    this.total,
  );

  /** i18n key for the Apply button's label. */
  readonly applyKey = this.isAddMode
    ? 'settings.organise.addTagApply'
    : 'settings.organise.removeTagApply';

  /** Add mode offers every tag; remove mode offers only what the selection
   *  actually carries — a tag nobody has is not a tag anybody can lose. */
  readonly offered = computed<TagDto[]>(() => {
    if (this.data.mode === 'add') return this.data.tags;

    return this.data.tags.filter((tag) => this.carriedBy(tag) > 0);
  });

  /** How many of the selected feeds carry each tag id, built once from
   *  `data.subscriptions` rather than rescanned per tag: `carriedBy` is read
   *  from the template inside a loop over every offered tag, and again by
   *  `offered()` and `affected()` — at the 500-id cap over 18 tags an
   *  unmemoised scan would be ~9,000 comparisons per change-detection pass. */
  private readonly carrierCounts = computed<ReadonlyMap<number, number>>(() => {
    const counts = new Map<number, number>();
    for (const subscription of this.data.subscriptions) {
      for (const tag of subscription.tags) {
        counts.set(tag.id, (counts.get(tag.id) ?? 0) + 1);
      }
    }

    return counts;
  });

  /** How many of the selected feeds already carry this tag. */
  carriedBy(tag: TagDto): number {
    return this.carrierCounts().get(tag.id) ?? 0;
  }

  /** In remove mode, how many feeds would be left with no tag at all. */
  readonly losingLastTag = computed<number>(() => {
    const tag = this.chosen();
    if (tag === null || this.data.mode !== 'remove') return 0;

    return this.data.subscriptions.filter((s) => s.tags.length === 1 && s.tags[0].id === tag.id)
      .length;
  });

  /** i18n key for the "N of them lose their last tag" sentence. */
  readonly losingLastTagKey = computed<string>(() =>
    pluralKey('settings.organise.losingLastTag', this.losingLastTag()),
  );

  /** How many feeds the Apply button will actually change. */
  readonly affected = computed<number>(() => {
    const tag = this.chosen();
    if (tag === null) return 0;
    const carried = this.carriedBy(tag);

    return this.data.mode === 'add' ? this.total - carried : carried;
  });

  /** i18n key for the sentence describing what Apply will do. Reactive,
   *  unlike `titleKey`: `affected()` changes with every tag the user picks. */
  readonly effectKey = computed<string>(() =>
    pluralKey(
      this.isAddMode ? 'settings.organise.addEffect' : 'settings.organise.removeEffect',
      this.affected(),
    ),
  );

  choose(tag: TagDto): void {
    this.chosen.set(tag);
  }

  apply(): void {
    const tag = this.chosen();
    if (tag === null) return;
    this.ref.close(tag);
  }

  cancel(): void {
    this.ref.close(undefined);
  }
}
