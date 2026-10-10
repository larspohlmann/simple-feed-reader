import { Directive, computed, inject, input } from '@angular/core';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { relativeTime } from '../../format';
import { entryHeadline, entrySnippet } from '../preview-image';
import { LanguageService } from '../../../core/i18n/language.service';

/** The signal inputs/outputs every magazine block shares, whether or not it
 *  renders an image. The `@Directive()` decorator is required — without it
 *  Angular's compiler does not emit input/output metadata for the base class,
 *  so a `@Component` extending it silently loses `entry`/`tags`. */
@Directive({
  host: { '[attr.data-entry-id]': 'entry().id' },
})
export abstract class EntryBlockBase {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  protected readonly actions = inject(EntryActionHandler);

  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );

  /** The lead of the entry's own copy, plain-texted. A block renders it as a
   *  clamped dek beneath the title; an empty result (a headline-only feed) lets
   *  the block fall back to title-only via its own `@if (snippet())`. A post has none: its text is the headline. */
  readonly isPost = computed(() => this.entry().titleDerived);
  readonly headline = computed(() => entryHeadline(this.entry()));
  readonly snippet = computed(() => (this.isPost() ? '' : entrySnippet(this.entry())));
}
