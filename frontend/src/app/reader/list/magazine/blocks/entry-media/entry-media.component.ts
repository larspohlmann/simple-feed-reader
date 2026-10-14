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
import { EntryMediumDto } from '../../../../entry-medium';
import { PlayingEmbedDirective } from '../../../../media/playing-embed.directive';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { PostMediaComponent } from '../../../../media/post-media/post-media.component';
import { autoplayingShortPlayer } from './short-embed';
import { OpensEntryDirective } from '../../../opens-entry.directive';

const UNKNOWN_RATIO = 16 / 9;

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
  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  readonly media = computed(() => sharedMedia(this.entry()));
  readonly shortPlayer = computed(() => autoplayingShortPlayer(this.entry().shortPlayerUrl));
  protected readonly playingShort = signal(false);

  constructor() {
    super();
    afterRenderEffect(() => {
      this.playingShort();
      this.pauseWhenScrolledAway();
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
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

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.playingShort.set(true);
  }

  protected stopShort(): void {
    this.playingShort.set(false);
  }

  protected ratio(medium: EntryMediumDto): number {
    const entry = this.entry();
    if (medium.url === entry.imageUrl && entry.imageAspectRatio) return entry.imageAspectRatio;
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
}
