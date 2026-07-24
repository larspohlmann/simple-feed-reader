// src/app/reader/entry-row/entry-row.component.ts
import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { LanguageService } from '../../core/language.service';
import { EntryDto, SubscriptionTagDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-row',
  imports: [IconComponent, FaviconComponent, SourceTagsComponent, TranslocoPipe],
  templateUrl: './entry-row.component.html',
  styleUrl: './entry-row.component.scss',
})
export class EntryRowComponent {
  readonly entry = input.required<EntryDto>();
  readonly imageSide = input<'left' | 'right'>('right');
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly image = computed(() =>
    firstPreviewImage(this.entry().contentHtml, this.entry().summary),
  );
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
