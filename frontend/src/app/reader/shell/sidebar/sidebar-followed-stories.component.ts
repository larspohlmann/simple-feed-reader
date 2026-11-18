import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryDto } from '../../trending/trending.models';
import { storyListLink } from '../../trending/story';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';

/** The sidebar's "Followed stories" card (#2128): one row per followed Trending story, in the server's order. */
@Component({
  selector: 'app-sidebar-followed-stories',
  imports: [RouterLink, TranslocoPipe, IconComponent, SidebarRowMenuComponent],
  templateUrl: './sidebar-followed-stories.component.html',
  styleUrl: './sidebar-followed-stories.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarFollowedStoriesComponent {
  readonly stories = input<FollowedStoryDto[]>([]);
  readonly activeStoryId = input<number | null>(null);

  protected readonly followed = inject(FollowedStoriesStore);
  protected readonly rows = inject(SidebarRowActions);
  protected readonly storyListLink = storyListLink;

  readonly expanded = signal(false);
  protected readonly newTotal = computed(() =>
    this.stories().reduce((sum, story) => sum + story.newCount, 0),
  );

  toggle(): void {
    this.expanded.update((open) => !open);
  }
}
