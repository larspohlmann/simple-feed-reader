import { CdkTrapFocus } from '@angular/cdk/a11y';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  computed,
  inject,
  input,
  output,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { feedGroups, feedPath } from '../feed-directory-feed-groups';
import { FeedDirectoryFeed } from '../feed-directory-rows';

@Component({
  selector: 'app-feed-directory-feeds-popover',
  imports: [ButtonComponent, CdkTrapFocus, IconButtonDirective, IconComponent, TranslocoPipe],
  templateUrl: './feed-directory-feeds-popover.component.html',
  styleUrl: './feed-directory-feeds-popover.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryFeedsPopoverComponent {
  readonly host = input.required<string>();
  readonly feeds = input.required<readonly FeedDirectoryFeed[]>();
  readonly choose = output<string>();
  readonly edit = output<string>();
  readonly dismiss = output();

  protected readonly groups = computed(() =>
    feedGroups(this.feeds()).map((group) => ({
      ...group,
      feeds: group.feeds.map((feed) => ({ ...feed, path: feedPath(feed.url, this.host()) })),
    })),
  );

  constructor() {
    const element = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    afterNextRender(() =>
      (
        element.querySelector<HTMLElement>('.feed button') ?? element.querySelector('button')
      )?.focus({ preventScroll: true }),
    );
  }
}
