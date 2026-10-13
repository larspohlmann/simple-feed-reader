import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { LanguageService } from '../../../core/i18n/language.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { observeOnSight } from '../../list/paging';
import { likeableSocial } from '../../entry-social';
import { EntryDto } from '../../models';
import { SocialCountsService } from './social-counts.service';

type CountKind = 'likes' | 'reposts' | 'boosts' | 'replies' | 'quotes';

interface ShownCount {
  kind: CountKind;
  icon: string;
  count: number;
}

@Component({
  selector: 'app-social-counts',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './social-counts.component.html',
  styleUrl: './social-counts.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialCountsComponent {
  readonly entry = input.required<EntryDto>();
  /** The pane whose viewport the counts load on sight of. */
  readonly scroller = input.required<HTMLElement>();

  protected readonly actions = inject(EntryActionHandler);
  private readonly service = inject(SocialCountsService);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly entryId = computed(() => this.entry().id);
  private readonly compact = computed(
    () => new Intl.NumberFormat(this.language.lang(), { notation: 'compact' }),
  );
  private readonly loaded = computed(() => {
    const state = this.service.state(this.entryId())();
    return state.status === 'ok' ? state : null;
  });

  protected readonly likeable = computed(() => likeableSocial(this.entry()));
  protected readonly likes = computed(() => {
    const loaded = this.loaded();
    if (!loaded) return null;
    const likedSince = Number(this.entry().social?.isLiked ?? false) - Number(loaded.likedAtLoad);
    return Math.max(0, loaded.counts.likes + likedSince);
  });
  protected readonly counts = computed<ShownCount[]>(() => {
    const loaded = this.loaded();
    const likes = this.likes();
    if (!loaded || likes === null) return [];
    const { reposts, replies, quotes } = loaded.counts;
    const repostKind: CountKind =
      this.entry().social?.network === 'mastodon' ? 'boosts' : 'reposts';
    const shown: ShownCount[] = [
      { kind: repostKind, icon: 'repeat', count: reposts },
      { kind: 'replies', icon: 'chat_bubble', count: replies },
    ];
    if (quotes !== null) shown.push({ kind: 'quotes', icon: 'format_quote', count: quotes });
    if (!this.likeable()) shown.push({ kind: 'likes', icon: 'thumb_up', count: likes });
    return shown;
  });
  protected readonly shown = computed(() => this.likeable() !== null || this.loaded() !== null);

  constructor() {
    effect((onCleanup) =>
      onCleanup(
        observeOnSight(this.host.nativeElement, this.scroller(), () =>
          this.service.load({ id: this.entryId(), social: this.entry().social }),
        ),
      ),
    );
  }

  protected format(count: number): string {
    return this.compact().format(count);
  }

  protected labelKey(kind: CountKind, count: number): string {
    return pluralKey(`reader.socialCounts.${kind}`, count);
  }
}
