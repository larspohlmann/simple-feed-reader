import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { FeedDirectoryNames } from '../feed-directory-names';
import { FeedDirectoryMode, FeedDirectoryRow, feedsIn } from '../feed-directory-rows';

export const FEED_DIRECTORY_ROW_HEIGHT = 132;

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
  host: { '[style.--row-h.px]': 'height' },
})
export class FeedDirectoryRowComponent {
  readonly row = input.required<FeedDirectoryRow>();
  readonly mode = input.required<FeedDirectoryMode>();
  readonly subscribe = output<FeedDirectorySubscribeRequest>();

  protected readonly names = inject(FeedDirectoryNames);
  protected readonly height = FEED_DIRECTORY_ROW_HEIGHT;

  protected readonly feeds = computed(() => feedsIn(this.row(), this.mode()));
  protected readonly onlyFeed = computed(() =>
    this.feeds().length === 1 ? this.feeds()[0] : null,
  );
  protected readonly subscribedCount = computed(
    () => this.feeds().filter((feed) => feed.subscribed).length,
  );
  protected readonly categories = computed(() => [
    ...new Set(this.feeds().map((feed) => feed.category)),
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
