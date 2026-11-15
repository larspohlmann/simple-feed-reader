import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { entryRelativeTime } from '../../format';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { feedAvatarUrl } from '../../state/social-sections';
import { sourceNameOf } from '../../entry/entry-source/source-name';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { RenditionsDirective } from '../renditions.directive';
import { MINI_CARD_THUMB_SIZES } from '../rendition-sizes';
import { StorySharesComponent } from '../../trending/story-shares/story-shares.component';
import { articleFaceOf } from '../../trending/shared-article-card';

let nextId = 0;

@Component({
  selector: 'app-more-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    TranslocoPipe,
    FaviconComponent,
    IconComponent,
    EntryDuplicatesComponent,
    RenditionsDirective,
    StorySharesComponent,
  ],
  templateUrl: './more-sources.component.html',
  styleUrl: './more-sources.component.scss',
})
export class MoreSourcesComponent {
  readonly entry = input.required<EntryDto>();

  private readonly actions = inject(EntryActionHandler);
  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);

  protected readonly copies = computed(() => this.entry().duplicates ?? []);
  protected readonly countKey = computed(() =>
    this.copies().length === 1 ? 'reader.moreArticlesOne' : 'reader.moreArticles',
  );
  protected readonly thumbSizes = MINI_CARD_THUMB_SIZES;
  private readonly brokenThumbs = linkedSignal<readonly EntryDto[], ReadonlySet<number>>({
    source: this.copies,
    computation: () => new Set(),
  });
  protected readonly sources = computed(() =>
    this.copies().map((copy) => ({
      copy,
      feed: sourceNameOf(copy, this.rows),
      avatarUrl: feedAvatarUrl(this.rows.find(copy.subscriptionId)),
      ...this.faceOf(copy),
      when: entryRelativeTime(copy, this.language.lang()),
      shareCount: copy.shares?.length ?? 0,
    })),
  );
  protected readonly expanded = signal(false);
  protected readonly panelId = `more-sources-${nextId++}`;

  toggle(event: Event): void {
    event.stopPropagation();
    this.expanded.update((open) => !open);
  }

  private faceOf(copy: EntryDto): { title: string; dek: string; picture: string | null } {
    const face = articleFaceOf(copy);
    return { ...face, picture: this.brokenThumbs().has(copy.id) ? null : face.picture };
  }

  protected markBroken(copyId: number): void {
    this.brokenThumbs.update((broken) => new Set(broken).add(copyId));
  }

  open(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    this.actions.open(copy);
  }
}
