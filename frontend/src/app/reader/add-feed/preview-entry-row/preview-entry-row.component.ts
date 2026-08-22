import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { LanguageService } from '../../../core/language.service';
import { relativeTime } from '../../format';
import { FeedPreviewItem } from '../../models';

// A visual copy of the reader entry row, deliberately decoupled from
// app-entry-row (#519): inert by construction — no click target, no actions,
// no tags, no read dot — so the preview never entangles the reader's row.
@Component({
  selector: 'app-preview-entry-row',
  imports: [FaviconComponent],
  templateUrl: './preview-entry-row.component.html',
  styleUrl: './preview-entry-row.component.scss',
})
export class PreviewEntryRowComponent {
  readonly item = input.required<FeedPreviewItem>();
  readonly source = input<string>('');
  readonly faviconUrl = input<string | null>(null);
  readonly imgError = signal(false);

  private readonly language = inject(LanguageService);
  readonly when = computed(() => {
    const at = this.item().publishedAt;
    return at ? relativeTime(at, this.language.lang()) : '';
  });

  // Reset the failed-image flag when the row is reused for another item.
  private readonly _resetOnItemChange = effect(() => {
    this.item();
    this.imgError.set(false);
  });
}
