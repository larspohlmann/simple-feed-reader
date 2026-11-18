import {
  ChangeDetectionStrategy,
  Component,
  TemplateRef,
  computed,
  inject,
  input,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListPreferences } from '../../list/list-preferences.service';
import { ListOrderService } from '../../list/list-order.service';
import { TitleCount } from '../../list/list-header/list-header.component';
import { SubscriptionTagDto } from '../../models';
import { SelectionOf } from '../../query/query';
import { ReadingLayout } from '../../reading-layout.service';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { OpenStory } from '../../state/open-story';
import { STORY_ENTRIES } from '../../state/story-entries';
import { ListOnScreen, MarkReadActions } from '../mark-read-actions.service';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';

/** A Trending story's list over the list the reader was on (#2329): the reader's own entry list and header,
 *  scoped to the story, with the way back ahead of the title and Follow beside the list's actions. */
@Component({
  selector: 'app-story-layer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryListComponent,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    NgTemplateOutlet,
    TranslocoPipe,
  ],
  templateUrl: './story-layer.component.html',
  styleUrl: './story-layer.component.scss',
  providers: [StoryFollowing],
})
export class StoryLayerComponent {
  readonly selection = input.required<SelectionOf<'trending-story'>>();
  readonly layout = input<ReadingLayout>('list');
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());
  readonly openEntryId = input<number | null>(null);
  readonly leavingIds = input<ReadonlySet<number>>(new Set());
  /** The list's own leading and corner pieces, so the story's list shows what every list does. */
  readonly listLeading = input<TemplateRef<unknown> | null>(null);
  readonly cornerTrailing = input<TemplateRef<unknown> | null>(null);

  protected readonly overlay = inject(StoryOverlay);
  protected readonly story = inject(STORY_ENTRIES);
  protected readonly following = inject(StoryFollowing);
  protected readonly visit = inject(FollowedStoryVisit);
  protected readonly listPreferences = inject(ListPreferences);
  protected readonly listOrder = inject(ListOrderService);
  private readonly markRead = inject(MarkReadActions);
  private readonly openStory = inject(OpenStory);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly i18n = inject(TranslocoService);
  private readonly list = viewChild(EntryListComponent);

  protected readonly title = computed(() => {
    const id = this.selection().id;
    return (
      this.openStory.titleOf(id) ??
      this.followedStories.byId(id)?.headline ??
      this.i18n.translate('reader.storyFallback')
    );
  });
  /** The rows it lists, as a list without server tallies counts them: the unread ones under "Only unread". */
  protected readonly titleCount = computed((): TitleCount => ({
    value: this.story.entries().length,
    counts: this.selection().unread ? 'unread' : 'items',
  }));
  private readonly onScreen = computed((): ListOnScreen => ({
    selection: this.selection(),
    entries: this.story,
  }));

  protected markAllRead(): void {
    this.markRead.confirmMarkAllReadIn(this.onScreen());
  }

  protected markAboveRead(ids: number[]): void {
    this.markRead.confirmMarkAboveReadIn(this.onScreen(), ids, () =>
      this.list()?.hideAboveMarked(ids),
    );
  }
}
