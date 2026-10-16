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
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { sharedMedia } from '../../magazine-slot-fit';
import { EntryMediumDto, mediumRatio } from '../../../../entry-medium';
import { PlayingEmbedDirective } from '../../../../media/playing-embed.directive';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { PostMediaComponent } from '../../../../media/post-media/post-media.component';
import { autoplayingShortPlayer, mutedShortPlayer } from './short-embed';
import { AutoplayPlayer } from '../../../../media/autoplay/autoplay-player';
import { ListAutoplayCoordinator } from '../../../../media/autoplay/list-autoplay-coordinator';
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
  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  readonly media = computed(() => sharedMedia(this.entry()));
  readonly shortPlayer = computed(() => autoplayingShortPlayer(this.entry().shortPlayerUrl));
  protected readonly playingShort = signal<string | null>(null);

  constructor() {
    super();
    afterRenderEffect(() => {
      this.playingShort();
      this.pauseWhenScrolledAway();
      this.registerShortForAutoplay();
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
      if (this.shortAutoplay) this.autoplay?.unregister(this.shortAutoplay);
    });
  }

  private pauseWhenScrolledAway(): void {
    const players = Array.from(this.host.querySelectorAll('.short-player'));
    if (typeof IntersectionObserver === 'undefined' || players.length === 0) return;
    this.scrollAway ??= new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (!change.isIntersecting) this.stopShort();
      }
    });
    for (const player of players) this.scrollAway.observe(player);
  }

  private registerShortForAutoplay(): void {
    const element = this.host.querySelector('.short-player');
    if (this.autoplay === null || element === null || this.shortAutoplay) return;
    this.shortAutoplay = {
      element,
      start: () => this.startShort(mutedShortPlayer),
      stop: () => this.stopShort(),
    };
    this.autoplay.register(this.shortAutoplay);
  }

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.startShort((player) => player);
    if (this.shortAutoplay) this.autoplay?.nowPlaying(this.shortAutoplay);
  }

  protected stopShort(): void {
    this.playingShort.set(null);
  }

  private startShort(variant: (player: string) => string): void {
    const player = this.shortPlayer();
    if (player !== null) this.playingShort.set(variant(player));
  }

  protected ratio(medium: EntryMediumDto): number {
    const entry = this.entry();
    if (medium.url === entry.imageUrl && entry.imageAspectRatio) return entry.imageAspectRatio;
    return mediumRatio(medium);
  }
}
