import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
  signal,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { entryRelativeTime } from '../../format';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { leadOf, otherArticlesOf } from '../../state/list-item';
import { sameMembers } from '../../state/same-members';
import { SubscriptionRows } from '../../state/subscription-rows';
import { feedAvatarUrl } from '../../state/social-sections';
import { sourceNameOf } from '../../entry/entry-source/source-name';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { RenditionsDirective } from '../renditions.directive';
import { MINI_CARD_THUMB_SIZES } from '../rendition-sizes';
import { StorySharersComponent } from '../../trending/story-sharers/story-sharers.component';
import { ArticleFace, articleFaceOf } from '../../trending/shared-article-card';
import { OpenInReaderComponent } from '../../link-card/open-in-reader.component';
import { StoryActionsComponent } from '../../trending/story-actions/story-actions.component';
import { StorySourceCountComponent } from '../../trending/story-source-count/story-source-count.component';
import { STORY_ICON } from '../../trending/story';
import { injectStory } from '../../trending/story-context';
import { StoryArticleDto } from '../../trending/trending.models';

let nextId = 0;

const sameArticles = (
  left: readonly StoryArticleDto[],
  right: readonly StoryArticleDto[],
): boolean => sameMembers(new Set(left), new Set(right));

@Component({
  selector: 'app-more-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    FaviconComponent,
    IconComponent,
    EntryDuplicatesComponent,
    RenditionsDirective,
    StorySharersComponent,
    OpenInReaderComponent,
    StoryActionsComponent,
    StorySourceCountComponent,
  ],
  templateUrl: './more-sources.component.html',
  styleUrl: './more-sources.component.scss',
})
export class MoreSourcesComponent {
  readonly entry = input.required<EntryDto>();
  protected readonly story = injectStory();

  protected readonly leadOf = leadOf;
  private readonly actions = inject(EntryActionHandler);
  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);

  protected readonly hasDuplicates = computed(() => (this.entry().duplicates?.length ?? 0) > 0);
  protected readonly copies = computed(
    () => {
      const story = this.story();
      return story ? otherArticlesOf(story) : [];
    },
    { equal: sameArticles },
  );
  protected readonly countKey = computed(() =>
    this.copies().length === 1 ? 'reader.moreArticlesOne' : 'reader.moreArticles',
  );
  protected readonly thumbSizes = MINI_CARD_THUMB_SIZES;
  protected readonly storyIcon = STORY_ICON;
  private readonly brokenThumbs = linkedSignal<readonly StoryArticleDto[], ReadonlySet<number>>({
    source: this.copies,
    computation: () => new Set(),
  });
  protected readonly sources = computed(() =>
    this.copies().map((copy) => {
      const face = this.faceOf(copy);
      return {
        copy,
        ...face,
        feed: face.site ?? sourceNameOf(copy, this.rows),
        avatarUrl: feedAvatarUrl(this.rows.find(copy.subscriptionId)),
        when: entryRelativeTime(copy, this.language.lang()),
      };
    }),
  );
  protected readonly expanded = signal(false);
  protected readonly panelId = `more-sources-${nextId++}`;

  toggle(event: Event): void {
    event.stopPropagation();
    this.expanded.update((open) => !open);
  }

  private faceOf(copy: StoryArticleDto): ArticleFace {
    const face = articleFaceOf(copy);
    return { ...face, picture: this.brokenThumbs().has(copy.id) ? null : face.picture };
  }

  protected markBroken(copyId: number): void {
    this.brokenThumbs.update((broken) => new Set(broken).add(copyId));
  }

  open(copy: EntryDto): void {
    this.actions.open(copy);
  }
}
