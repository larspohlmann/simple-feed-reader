import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { QuotedPostDto } from './quoted-post';
import { LanguageService } from '../../core/i18n/language.service';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { avatarToneOf } from '../../shared/avatar-tone';
import { initialsOf } from '../../shared/initials';
import { formatLongDateTime, relativeTimeNarrow } from '../format';
import { PostMediaComponent } from '../media/post-media/post-media.component';

/** `block` sits in the magazine's quote share, clamped; `article` under a post in the reader, whole (#1612). */
export type QuotedPostLayout = 'block' | 'article';

const UNKNOWN_RATIO = 16 / 9;

@Component({
  selector: 'app-quoted-post',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [PostMediaComponent, ProxiedImageDirective, TranslocoPipe],
  templateUrl: './quoted-post.component.html',
  styleUrl: './quoted-post.component.scss',
  host: {
    '[class.block]': "layout() === 'block'",
    '[class.article]': "layout() === 'article'",
  },
})
export class QuotedPostComponent {
  readonly quote = input.required<QuotedPostDto>();
  readonly layout = input.required<QuotedPostLayout>();

  private readonly language = inject(LanguageService);
  protected readonly avatarFailed = linkedSignal({ source: this.quote, computation: () => false });
  protected readonly initials = computed(() => initialsOf(this.quote().authorName));
  protected readonly tone = computed(() => avatarToneOf(this.quote().authorHandle));
  protected readonly postedAt = computed(() => {
    const iso = this.quote().postedAt;
    if (iso === null) return '';
    const lang = this.language.lang();
    return this.layout() === 'article'
      ? formatLongDateTime(iso, lang)
      : relativeTimeNarrow(iso, lang);
  });
  protected readonly ratio = computed(() => {
    const first = this.quote().media[0];
    return first?.width && first.height ? first.width / first.height : UNKNOWN_RATIO;
  });
}
