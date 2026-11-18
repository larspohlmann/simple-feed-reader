import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { ArticleLayerComponent } from '../../article/article-layer/article-layer.component';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListOrderService } from '../../list/list-order.service';
import { SubscriptionTagDto } from '../../models';
import { SelectionOf, listOrderOf } from '../../query/query';
import { ReadingLayout } from '../../reading-layout.service';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { OpenStory } from '../../state/open-story';
import { StoryListStore } from '../../state/story-list.store';
import { STORY_ICON } from '../../trending/story';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';

/** A Trending story's list as a layer over the list the reader was on (#2329): the article's layer and bar —
 *  back, the story glyph and title, then the order and Follow at the end — over the story's rows. */
@Component({
  selector: 'app-story-layer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ArticleLayerComponent,
    EntryListComponent,
    IconComponent,
    ListActionDirective,
    TranslocoPipe,
  ],
  templateUrl: './story-layer.component.html',
  styleUrl: './story-layer.component.scss',
  providers: [StoryFollowing],
})
export class StoryLayerComponent {
  readonly selection = input.required<SelectionOf<'trending-story'>>();
  readonly fullscreen = input(false);
  readonly layout = input<ReadingLayout>('list');
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());
  readonly openEntryId = input<number | null>(null);
  readonly leavingIds = input<ReadonlySet<number>>(new Set());

  protected readonly overlay = inject(StoryOverlay);
  protected readonly storyList = inject(StoryListStore);
  protected readonly following = inject(StoryFollowing);
  protected readonly visit = inject(FollowedStoryVisit);
  private readonly openStory = inject(OpenStory);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly listOrder = inject(ListOrderService);
  private readonly i18n = inject(TranslocoService);

  protected readonly storyIcon = STORY_ICON;
  protected readonly oldestFirst = computed(() => listOrderOf(this.selection()) === 'oldest');
  protected readonly resetKey = computed(() => `story-${this.selection().id}`);
  protected readonly title = computed(() => {
    const id = this.selection().id;
    return (
      this.openStory.titleOf(id) ??
      this.followedStories.byId(id)?.headline ??
      this.i18n.translate('reader.storyFallback')
    );
  });
  protected readonly progressRail = new ScrollProgressRail({
    scroller: () => undefined,
    contentBottom: () => null,
    layout: signal(null),
  });

  protected flipOrder(): void {
    this.listOrder.set(this.selection(), this.oldestFirst() ? 'newest' : 'oldest');
  }
}
