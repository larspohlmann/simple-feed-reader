import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  forwardRef,
  inject,
  input,
  signal,
} from '@angular/core';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { MarkedTextComponent } from '../../../shared/marked-text/marked-text.component';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDuplicatesComponent } from '../magazine/entry-duplicates.component';
import { LanguageService } from '../../../core/i18n/language.service';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { entryImage, entrySnippet, portraitCoverRatio, showsShortPill } from '../preview-image';
import { relativeTime } from '../../format';
import { RenditionsDirective } from '../renditions.directive';
import { ShortBadgeComponent } from '../short-badge/short-badge.component';
import { COVER_BOX_SIZES } from '../rendition-sizes';

@Component({
  selector: 'app-entry-row',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[attr.data-entry-id]': 'entry().id' },
  // forwardRef, not a direct reference: entry-duplicates renders entry-row for
  // its popover card, so a plain reference here would resolve
  // EntryDuplicatesComponent mid-import-cycle and read as undefined.
  imports: [
    FaviconComponent,
    MarkedTextComponent,
    EntryPillsComponent,
    EntryActionsComponent,
    forwardRef(() => EntryDuplicatesComponent),
    RenditionsDirective,
    ShortBadgeComponent,
  ],
  templateUrl: './entry-row.component.html',
  styleUrl: './entry-row.component.scss',
})
export class EntryRowComponent {
  protected readonly coverBoxSizes = COVER_BOX_SIZES;
  readonly entry = input.required<EntryDto>();
  readonly imageSide = input<'left' | 'right'>('right');
  readonly tags = input<SubscriptionTagDto[]>([]);
  /** The current search's words, marked inside the title and the snippet.
   *  Empty outside a search, where nothing is marked. */
  readonly terms = input<string[]>([]);
  /** Whether anything can be marked at all. Outside a search — every list but
   *  one — the template renders plain interpolation instead of two
   *  `<app-marked-text>` instances per row, so the ordinary list pays nothing
   *  for a feature it never uses. */
  readonly marking = computed(() => this.terms().length > 0);

  protected readonly actions = inject(EntryActionHandler);

  readonly imgError = signal(false);
  // The entry's image, from the same shared helper the magazine uses: the
  // persisted hero when present, else an inline <img>. One source of truth, so
  // a picture never shows in one view and hides in another.
  readonly image = computed(() => entryImage(this.entry())?.url ?? null);
  readonly showImage = computed(() => !!this.image() && !this.imgError());
  readonly portraitRatio = computed(() => portraitCoverRatio(this.entry()));
  readonly shortPill = computed(() => showsShortPill(this.entry(), this.showImage()));
  readonly snippet = computed(() => entrySnippet(this.entry()));
  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );

  // Reset the failed-image flag whenever the row is reused for a different entry.
  private readonly _resetOnEntryChange = effect(() => {
    this.entry();
    this.imgError.set(false);
  });
}
