// src/app/reader/magazine/source-group.component.ts
import { Component, computed, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { EntryCompactComponent } from './entry-compact.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryDto, SubscriptionTagDto } from '../models';

@Component({
  selector: 'app-source-group',
  imports: [
    RouterLink,
    FaviconComponent,
    EntryCompactComponent,
    SourceTagsComponent,
    TranslocoPipe,
  ],
  templateUrl: './source-group.component.html',
  styleUrl: './source-group.component.scss',
})
export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  /** The run's whole owned tail. */
  readonly entries = input.required<EntryDto[]>();
  /** How many rows to show before the tail is expanded. */
  readonly previewCount = input.required<number>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();

  readonly visibleEntries = computed(() => this.entries().slice(0, this.previewCount()));
  readonly hiddenCount = computed(() => this.entries().length - this.previewCount());
}
