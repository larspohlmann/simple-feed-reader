import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { LanguageService } from '../../../core/i18n/language.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { SocialNetworkName } from '../../../core/social-network';
import { SocialCountsDto } from '../../entry-social';

type CountKind = 'likes' | 'reposts' | 'boosts' | 'replies' | 'quotes';

interface ShownCount {
  kind: CountKind;
  icon: string;
  count: number;
}

/** A post's counters and its like button; `liked` is null when the reader cannot like the post. */
@Component({
  selector: 'app-social-counts-row',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './social-counts-row.component.html',
  styleUrl: './social-counts-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialCountsRowComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly counts = input.required<SocialCountsDto | null>();
  readonly liked = input.required<boolean | null>();
  readonly likeToggled = output<void>();

  private readonly language = inject(LanguageService);
  private readonly compact = computed(
    () => new Intl.NumberFormat(this.language.lang(), { notation: 'compact' }),
  );

  protected readonly shown = computed(() => this.liked() !== null || this.counts() !== null);
  protected readonly likeKey = computed(
    () => `${this.liked() ? 'unlike' : 'like'}.${this.network()}`,
  );
  protected readonly shownCounts = computed<ShownCount[]>(() => {
    const counts = this.counts();
    if (!counts) return [];
    const repostKind: CountKind = this.network() === 'mastodon' ? 'boosts' : 'reposts';
    const shown: ShownCount[] = [
      { kind: repostKind, icon: 'repeat', count: counts.reposts },
      { kind: 'replies', icon: 'chat_bubble', count: counts.replies },
    ];
    if (counts.quotes !== null) {
      shown.push({ kind: 'quotes', icon: 'format_quote', count: counts.quotes });
    }
    if (this.liked() === null) shown.push({ kind: 'likes', icon: 'thumb_up', count: counts.likes });
    return shown;
  });

  protected format(count: number): string {
    return this.compact().format(count);
  }

  protected labelKey(kind: CountKind, count: number): string {
    return pluralKey(`reader.socialCounts.${kind}`, count);
  }
}
