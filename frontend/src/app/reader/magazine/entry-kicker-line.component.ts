// src/app/reader/magazine/entry-kicker-line.component.ts
import { Component, computed, inject, input } from '@angular/core';
import { EntryDto } from '../models';
import { relativeTime } from '../format';
import { LanguageService } from '../../core/language.service';
import { FaviconComponent } from '../../shared/favicon/favicon.component';

/**
 * The one-line attribution every magazine block carries: unread dot, favicon,
 * source name, relative time. Owning it here rather than repeating it per
 * block is what keeps the line's single-line guarantee in one place — it used
 * to be seven copies of the markup and seven of the CSS, which is how the
 * `flex: none` on the dot came to be missing from two of them (#155).
 */
@Component({
  selector: 'app-entry-kicker-line',
  imports: [FaviconComponent],
  templateUrl: './entry-kicker-line.component.html',
  styleUrl: './entry-kicker-line.component.scss',
})
export class EntryKickerLineComponent {
  readonly entry = input.required<EntryDto>();
  /** Off for a block that draws its own dot outside the line (compact). */
  readonly showDot = input(true);
  /** Off inside a source group, whose header already names the source. */
  readonly showSource = input(true);
  /** The hero's larger type carries a larger mark. */
  readonly faviconSize = input(12);

  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );
}
