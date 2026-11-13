import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { LayoutService } from '../../../reader/layout.service';
import { FeedDirectoryNames } from '../feed-directory-names';
import { FeedDirectoryMode, FeedDirectoryRow, feedsIn, shownFeeds } from '../feed-directory-rows';

export const FEED_DIRECTORY_ROW_HEIGHT = { wide: 132, phone: 114 } as const;

export interface FeedDirectorySubscribeRequest {
  readonly row: FeedDirectoryRow;
  readonly anchor: HTMLElement;
}

@Component({
  selector: 'app-feed-directory-row',
  imports: [ButtonComponent, IconComponent, TranslocoPipe],
  templateUrl: './feed-directory-row.component.html',
  styleUrl: './feed-directory-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryRowComponent {
  readonly row = input.required<FeedDirectoryRow>();
  readonly mode = input.required<FeedDirectoryMode>();
  readonly subscribe = output<FeedDirectorySubscribeRequest>();
  readonly focusedCategory = input<string | null>(null);
  readonly categories = input<ReadonlySet<string>>(new Set());
  readonly edit = output<string>();
  readonly focusCategory = output<string>();

  protected readonly names = inject(FeedDirectoryNames);
  protected readonly layout = inject(LayoutService);

  protected readonly feeds = computed(() => shownFeeds(this.row(), this.mode(), this.categories()));
  protected readonly onlyFeed = computed(() =>
    this.feeds().length === 1 ? this.feeds()[0] : null,
  );
  protected readonly subscribedCount = computed(
    () => this.feeds().filter((feed) => feed.subscribed).length,
  );
  protected readonly pillCategories = computed(() => [
    ...new Set(feedsIn(this.row(), this.mode()).map((feed) => feed.category)),
  ]);
  protected readonly languageNames = computed(() =>
    this.row()
      .languages.map((code) => this.names.language(code))
      .join(', '),
  );

  protected request(event: Event): void {
    this.subscribe.emit({ row: this.row(), anchor: event.currentTarget as HTMLElement });
  }
}
