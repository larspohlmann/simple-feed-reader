// src/app/reader/magazine/entry-block-base.ts
import { Directive, computed, inject, input, output } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../models';
import { relativeTime } from '../format';
import { textSnippet } from '../preview-image';
import { LanguageService } from '../../core/language.service';

/** The signal inputs/outputs every magazine block shares, whether or not it
 *  renders an image. The `@Directive()` decorator is required — without it
 *  Angular's compiler does not emit input/output metadata for the base class,
 *  so a `@Component` extending it silently loses `entry`/`tags`/`open`. */
@Directive()
export abstract class EntryBlockBase {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();

  /** Every block carries the three per-entry actions. They live here rather
   *  than on each block so a new block cannot forget them, and so the host
   *  binds the same four outputs for every kind. */
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();

  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );

  /** The lead of the entry's own copy, plain-texted. A block renders it as a
   *  clamped dek beneath the title; an empty result (a headline-only feed) lets
   *  the block fall back to title-only via its own `@if (snippet())`. */
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
}
