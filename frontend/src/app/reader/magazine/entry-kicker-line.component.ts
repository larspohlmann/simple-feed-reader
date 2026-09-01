// src/app/reader/magazine/entry-kicker-line.component.ts
import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { EntryDto } from '../models';
import { relativeTime, relativeTimeNarrow } from '../format';
import { LanguageService } from '../../core/language.service';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { selectionQueryParams } from '../query';

/**
 * The one-line attribution every magazine block carries: unread dot, favicon,
 * source name, relative time. Owning it here rather than repeating it per
 * block is what keeps the line's single-line guarantee in one place — it used
 * to be seven copies of the markup and seven of the CSS, which is how the
 * `flex: none` on the dot came to be missing from two of them (#155).
 */
@Component({
  selector: 'app-entry-kicker-line',
  imports: [FaviconComponent, RouterLink],
  templateUrl: './entry-kicker-line.component.html',
  styleUrl: './entry-kicker-line.component.scss',
})
export class EntryKickerLineComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly entry = input.required<EntryDto>();
  /** Off inside a source group, whose header already names the source. */
  readonly showSource = input(true);
  /** The hero's larger type carries a larger mark. */
  readonly faviconSize = input(12);

  private readonly language = inject(LanguageService);
  private readonly publishedOrCreated = computed(
    () => this.entry().publishedAt ?? this.entry().createdAt,
  );
  readonly when = computed(() => relativeTime(this.publishedOrCreated(), this.language.lang()));
  /** The same instant, narrow ("15d ago"), for a card too tight for `when`'s
   *  full label — a CSS container query picks between the two (#769). */
  readonly whenNarrow = computed(() =>
    relativeTimeNarrow(this.publishedOrCreated(), this.language.lang()),
  );
}
