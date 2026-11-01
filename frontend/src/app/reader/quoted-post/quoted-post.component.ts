import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { QuotedPostDto } from './quoted-post';
import { LanguageService } from '../../core/i18n/language.service';
import { AvatarComponent } from '../../shared/avatar/avatar.component';
import { formatLongDateTime, relativeTimeNarrow } from '../format';
import { mediumRatio } from '../entry-medium';
import { PostMediaComponent } from '../media/post-media/post-media.component';
import { LinkCardComponent, LinkCardReaderOpening } from '../link-card/link-card.component';

/** `block` sits in the magazine's quote share, clamped; `article` under a post in the reader, whole (#1612). */
export type QuotedPostLayout = 'block' | 'article';

@Component({
  selector: 'app-quoted-post',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AvatarComponent, LinkCardComponent, PostMediaComponent, TranslocoPipe],
  templateUrl: './quoted-post.component.html',
  styleUrl: './quoted-post.component.scss',
  host: {
    '[class]': 'layout()',
    '(click)': '$event.stopPropagation()',
    '(keydown.enter)': '$event.stopPropagation()',
    '(keydown.space)': '$event.stopPropagation()',
  },
})
export class QuotedPostComponent {
  readonly quote = input.required<QuotedPostDto>();
  readonly layout = input.required<QuotedPostLayout>();

  private readonly language = inject(LanguageService);
  protected readonly postedAt = computed(() => {
    const iso = this.quote().postedAt;
    if (iso === null) return '';
    const lang = this.language.lang();
    return this.layout() === 'article'
      ? formatLongDateTime(iso, lang)
      : relativeTimeNarrow(iso, lang);
  });
  protected readonly ratio = computed(() => mediumRatio(this.quote().media[0]));
  protected readonly cardOpening = computed<LinkCardReaderOpening>(() =>
    this.layout() === 'article' ? 'layer' : 'alone',
  );
}
