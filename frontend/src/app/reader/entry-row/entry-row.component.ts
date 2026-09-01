// src/app/reader/entry-row/entry-row.component.ts
import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { MarkedTextComponent } from '../../shared/marked-text/marked-text.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { LanguageService } from '../../core/language.service';
import { EntryDto, SubscriptionTagDto } from '../models';
import { entryImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-row',
  imports: [
    FaviconComponent,
    MarkedTextComponent,
    SourceTagsComponent,
    EntryActionsComponent,
    TranslocoPipe,
  ],
  templateUrl: './entry-row.component.html',
  styleUrl: './entry-row.component.scss',
})
export class EntryRowComponent {
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
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  // The entry's image, from the same shared helper the magazine uses: the
  // persisted hero when present, else an inline <img>. One source of truth, so
  // a picture never shows in one view and hides in another.
  readonly image = computed(() => entryImage(this.entry())?.url ?? null);
  readonly snippet = computed(() =>
    this.entry().summary
      ? textSnippet(this.entry().summary)
      : textSnippet(this.entry().contentHtml),
  );
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
