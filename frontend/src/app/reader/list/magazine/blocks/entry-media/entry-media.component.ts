import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterRenderEffect,
  computed,
  inject,
  signal,
} from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { sharedMedia } from '../../magazine-slot-fit';
import { EntryMediumDto, entryMediumRatio } from '../../../../entry-medium';
import { PlayingEmbedDirective } from '../../../../media/playing-embed.directive';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { PostMediaComponent } from '../../../../media/post-media/post-media.component';
import { autoplayingShortPlayer, mutedShortPlayer } from './short-embed';
import { AutoplayPlayer } from '../../../../media/autoplay/autoplay-player';
import { ListAutoplayCoordinator } from '../../../../media/autoplay/list-autoplay-coordinator';
import { pauseEmbedFrame } from '../../../../media/embed-frame-commands';
import { playerCommand, postToPlayerFrame } from '../../../../media/youtube-player-protocol';
import { NearViewportMedia } from '../../../../media/near-viewport-media';
import { OpensEntryDirective } from '../../../opens-entry.directive';

@Component({
  selector: 'app-entry-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    PlayingEmbedDirective,
    PostMediaComponent,
    ProxiedImageDirective,
    TranslocoPipe,
  ],
  templateUrl: './entry-media.component.html',
  styleUrl: './entry-media.component.scss',
  host: { '(media-pause)': 'stopShort()' },
})
export class EntryMediaComponent extends EntryBlockBase {
  private readonly autoplay = inject(ListAutoplayCoordinator, { optional: true });
  private shortAutoplay?: AutoplayPlayer;
  private readonly nearViewport = inject(NearViewportMedia, { optional: true });
  private readonly origin = inject(DOCUMENT).location.origin;
  private watchedShort: Element | null = null;
  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  readonly media = computed(() => sharedMedia(this.entry()));
  readonly shortPlayer = computed(() =>
    autoplayingShortPlayer(this.entry().shortPlayerUrl, this.origin),
  );
  protected readonly playingShort = signal<string | null>(null);

  constructor() {
    super();
    afterRenderEffect(() => {
      this.playingShort();
      this.pauseWhenScrolledAway();
      this.registerShortForAutoplay();
      this.removeShortWhenFar();
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
      if (this.watchedShort) this.nearViewport?.unwatch(this.watchedShort);
      if (this.shortAutoplay) this.autoplay?.unregister(this.shortAutoplay);
    });
  }

  private pauseWhenScrolledAway(): void {
    const players = Array.from(this.host.querySelectorAll('.short-player'));
    if (typeof IntersectionObserver === 'undefined' || players.length === 0) return;
    this.scrollAway ??= new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (!change.isIntersecting) this.pauseShort();
      }
    });
    for (const player of players) this.scrollAway.observe(player);
  }

  private registerShortForAutoplay(): void {
    if (this.autoplay === null || this.shortAutoplay) return;
    const element = this.host.querySelector('.short-player');
    if (element === null) return;
    this.shortAutoplay = {
      element,
      start: () => this.startMutedShort(),
      stop: () => this.pauseShort(),
    };
    this.autoplay.register(this.shortAutoplay);
  }

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.playingShort.set(this.shortPlayer());
    if (this.shortAutoplay) this.autoplay?.nowPlaying(this.shortAutoplay);
  }

  protected stopShort(): void {
    this.playingShort.set(null);
  }

  private startMutedShort(): void {
    const frame = this.shortFrame();
    if (frame) {
      postToPlayerFrame(frame, playerCommand('playVideo', []));
      return;
    }
    const player = this.shortPlayer();
    if (player !== null) this.playingShort.set(mutedShortPlayer(player));
  }

  private pauseShort(): void {
    const frame = this.shortFrame();
    if (frame) pauseEmbedFrame(frame, 'youtube');
  }

  private removeShortWhenFar(): void {
    if (this.nearViewport === null || this.watchedShort) return;
    const box = this.host.querySelector('.short-player');
    if (box === null) return;
    this.watchedShort = box;
    this.nearViewport.watch(box, { approach: () => undefined, leave: () => this.stopShort() });
  }

  private shortFrame(): HTMLIFrameElement | null {
    return this.host.querySelector<HTMLIFrameElement>('.short-player iframe');
  }

  protected ratio(medium: EntryMediumDto): number {
    return entryMediumRatio(this.entry(), medium);
  }
}
