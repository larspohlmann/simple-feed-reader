import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  untracked,
} from '@angular/core';
import { problemMessage } from '../../../core/problem';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { SocialCountsDto, likeableSocial } from '../../entry-social';
import { EntryDto } from '../../models';
import { SocialCountsRowComponent } from './social-counts-row.component';
import { SocialNetworkName } from '../../../core/social-network';
import { ReplyActions } from '../composer/reply-actions.service';
import { SocialCountsService } from './social-counts.service';

@Component({
  selector: 'app-social-counts',
  imports: [SocialCountsRowComponent],
  templateUrl: './social-counts.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialCountsComponent {
  readonly entry = input.required<EntryDto>();

  protected readonly actions = inject(EntryActionHandler);
  private readonly service = inject(SocialCountsService);
  private readonly toast = inject(ToastService);
  private readonly replies = inject(ReplyActions);
  readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly entryId = computed(() => this.entry().id);
  private readonly loaded = computed(() => {
    const state = this.service.state(this.entryId())();
    return state.status === 'ok' ? state : null;
  });

  protected readonly likeable = computed(() => likeableSocial(this.entry()) !== null);
  protected readonly viewer = computed(() => this.loaded()?.actions ?? null);
  /** The loaded counts with the likes moved by a like given or taken back since they loaded. */
  protected readonly counts = computed<SocialCountsDto | null>(() => {
    const loaded = this.loaded();
    if (!loaded) return null;
    const likedSince = Number(this.entry().social?.isLiked ?? false) - Number(loaded.likedAtLoad);
    return { ...loaded.counts, likes: Math.max(0, loaded.counts.likes + likedSince) };
  });

  constructor() {
    effect(() => {
      const id = this.entryId();
      untracked(() => this.service.load({ id, social: this.entry().social }));
    });
  }

  protected toggleRepost(): void {
    this.service.toggleRepost(this.entry(), (problem) =>
      this.toast.show({ message: problemMessage(problem) }),
    );
  }

  protected reply(network: SocialNetworkName): void {
    const entry = this.entry();
    this.replies.reply({
      entryId: entry.id,
      network,
      address: null,
      authorName: entry.poster?.name ?? entry.author,
      authorHandle: null,
      excerpt: entry.excerpt,
    });
  }

  reload(): void {
    this.service.reload(this.entry());
  }
}
