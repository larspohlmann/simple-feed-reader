import { Component, computed, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryCompactComponent } from './entry-compact.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryDto, SubscriptionTagDto } from '../models';
import { selectionQueryParams } from '../query';

@Component({
  selector: 'app-source-group',
  imports: [
    RouterLink,
    FaviconComponent,
    IconComponent,
    EntryCompactComponent,
    SourceTagsComponent,
    TranslocoPipe,
  ],
  templateUrl: './source-group.component.html',
  styleUrl: './source-group.component.scss',
})
export class SourceGroupComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  /** The run's whole owned tail. */
  readonly entries = input.required<EntryDto[]>();
  /** How many rows to show before the tail is expanded. */
  readonly previewCount = input.required<number>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();
  /** Forwarded from the rows. The group is not an `EntryBlockBase`, so unlike
   *  every block it has to declare these itself. */
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();

  /** Ephemeral: the widget starts collapsed on every fresh render. Survives an
   *  article open/close (the list stays mounted), resets on reload/reselect. */
  readonly expanded = signal(false);
  readonly visibleEntries = computed(() =>
    this.expanded() ? this.entries() : this.entries().slice(0, this.previewCount()),
  );
  readonly hiddenCount = computed(() => this.entries().length - this.previewCount());

  toggle(): void {
    this.expanded.update((open) => !open);
  }
}
