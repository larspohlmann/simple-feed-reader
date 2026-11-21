import { Observable } from 'rxjs';
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
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { SocialCountsDto, likeableSocial } from '../../entry-social';
import { EntryDto } from '../../models';
import { SocialCountsRowComponent } from './social-counts-row.component';
import { SocialNetworkName } from '../../../core/social-network';
import { PostActions } from '../composer/post-actions.service';
import { buildItemKey } from '../../state/item-key';
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
  protected readonly likeEntry = (): Observable<void> => this.actions.like(this.entry());
  private readonly service = inject(SocialCountsService);
  private readonly responses = inject(PostActions);
  readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly key = computed(() => buildItemKey(this.entry()));
  private readonly loaded = computed(() => {
    const state = this.service.state(this.entry())();
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
      this.key();
      untracked(() => this.service.load(this.entry()));
    });
  }

  protected toggleRepost(): void {
    this.service.toggleRepost(this.entry());
  }

  protected reply(network: SocialNetworkName): void {
    this.responses.reply(this.entry(), network, null);
  }

  protected quote(network: SocialNetworkName): void {
    this.responses.quote(this.entry(), network, null);
  }

  reload(): void {
    this.service.reload(this.entry());
  }
}
