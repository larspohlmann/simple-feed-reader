import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  TemplateRef,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';
import { ArticleGestures } from '../../article/article-layer/article-gestures.service';
import { EntryListComponent } from '../../list/entry-list/entry-list.component';
import { ListPreferences } from '../../list/list-preferences.service';
import { ListOrderService } from '../../list/list-order.service';
import { SubscriptionTagDto } from '../../models';
import { ReadingLayout } from '../../reading-layout.service';
import { FollowedStoryVisit } from '../../state/followed-story-visit';
import { ListContext } from '../../state/list-context';
import { OpenStory } from '../../state/open-story';
import { StoryTitleComponent } from '../../trending/story-title/story-title.component';
import { TrendingFollowAction } from '../../trending/trending-follow-action';
import { ListCover } from '../list-cover';
import { ListHeading } from '../list-heading.service';
import { MarkReadActions } from '../mark-read-actions.service';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';
import { StoryListContext } from './story-list-context';
import { provideStoryLayerActions } from './story-layer-actions';

/** A list's end loads more of it, so no pull past the end takes the story off as it does an article. */
const NO_PULL_BACK = signal<ScrollHost | undefined>(undefined);

/** A Trending story's list over the list the reader was on (#2329): the reader's own entry list and header,
 *  scoped to the story by a list context of its own, with the way back ahead of the title, and the story's counts,
 *  Follow and Hide on its title card as on a Trending card (#2442). Full-screen, a rightward swipe takes it off as it does an article. */
@Component({
  selector: 'app-story-layer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryListComponent,
    IconComponent,
    IconButtonDirective,
    NgTemplateOutlet,
    StoryTitleComponent,
    TranslocoPipe,
  ],
  templateUrl: './story-layer.component.html',
  styleUrl: './story-layer.component.scss',
  providers: [
    StoryListContext,
    { provide: ListContext, useExisting: StoryListContext },
    ListHeading,
    OpenStory,
    FollowedStoryVisit,
    TrendingFollowAction,
    StoryFollowing,
    provideStoryLayerActions(),
    ArticleGestures,
  ],
  host: {
    '[style.transform]': 'gestures.swipeTransform()',
    '[style.transition]': 'gestures.snapTransition()',
  },
})
export class StoryLayerComponent {
  readonly layout = input<ReadingLayout>('list');
  /** The immersive layout below the wide breakpoint, where the article's back-swipe applies. */
  readonly fullscreen = input(false);
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());
  readonly openEntryId = input<number | null>(null);
  /** The list's own leading and corner pieces, so the story's list shows what every list does. */
  readonly listLeading = input<TemplateRef<unknown> | null>(null);
  readonly cornerTrailing = input<TemplateRef<unknown> | null>(null);

  protected readonly overlay = inject(StoryOverlay);
  protected readonly context = inject(StoryListContext);
  protected readonly heading = inject(ListHeading);
  protected readonly visit = inject(FollowedStoryVisit);
  protected readonly listPreferences = inject(ListPreferences);
  protected readonly listOrder = inject(ListOrderService);
  protected readonly markRead = inject(MarkReadActions);
  protected readonly gestures = inject(ArticleGestures);
  private readonly openStory = inject(OpenStory);
  protected readonly card = computed(() => this.openStory.cardOf(this.context.selection().id));
  private readonly headlineTemplate = viewChild.required<TemplateRef<unknown>>('headlineTemplate');
  protected readonly topBlock = computed(() => (this.card() ? this.headlineTemplate() : null));
  readonly list = viewChild(EntryListComponent);

  constructor() {
    inject(ListCover).coverWith(
      { context: this.context, heading: this.heading, open: this.overlay.open },
      inject(DestroyRef),
    );
    this.gestures.connect({
      fullscreen: computed(() => this.fullscreen() && this.overlay.returnsToList()),
      scroller: NO_PULL_BACK,
      back: () => this.overlay.close(),
    });
  }

  protected markAboveRead(ids: number[]): void {
    this.markRead.confirmMarkAboveRead(ids, () => this.list()?.hideAboveMarked(ids));
  }
}
