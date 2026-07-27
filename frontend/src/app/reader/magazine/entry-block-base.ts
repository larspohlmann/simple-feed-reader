// src/app/reader/magazine/entry-block-base.ts
import { Directive, computed, inject, input, output } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../models';
import { relativeTime } from '../format';
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

  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );
}
