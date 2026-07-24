// src/app/reader/magazine/entry-hero.component.ts
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
  selector: 'app-entry-hero',
  imports: [IconComponent, FaviconComponent, SourceTagsComponent, TranslocoPipe],
  templateUrl: './entry-hero.component.html',
  styleUrl: './entry-hero.component.scss',
})
export class EntryHeroComponent {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly tooSmall = signal(false);
  readonly image = computed(() =>
    firstPreviewImage(this.entry().contentHtml, this.entry().summary),
  );
  readonly showImage = computed(() => !!this.image() && !this.imgError() && !this.tooSmall());
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );

  onLoad(ev: Event): void {
    const img = ev.target as HTMLImageElement;
    if (img.naturalWidth && img.naturalWidth < 200) this.tooSmall.set(true);
  }

  // Reset the gates when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
    this.tooSmall.set(false);
  });
}
