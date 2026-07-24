// src/app/reader/magazine/entry-compact.component.ts
import { Component, computed, input, output } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../models';
import { relativeTime } from '../format';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';

@Component({
  selector: 'app-entry-compact',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-compact.component.html',
  styleUrl: './entry-compact.component.scss',
})
export class EntryCompactComponent {
  readonly entry = input.required<EntryDto>();
  /** Hidden inside a source group, where the header already names the source
   *  and carries the tag pills — so the per-item pills are suppressed too. */
  readonly showSource = input(true);
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));
}
