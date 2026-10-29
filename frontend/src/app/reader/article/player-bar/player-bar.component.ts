import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AudioPlayerService } from '../../audio-player.service';
import { EmbedListen } from './embed-listen';

const WIDENED = 'reader-cinema--on';

/** The bar under a body player: Listen, the queue toggle and Cinema (#1479, #1815, #1888). */
@Component({
  selector: 'app-player-bar',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './player-bar.component.html',
  styleUrl: './player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'reader-player-bar', '[class.reader-listen]': 'listen() !== null' },
})
export class PlayerBarComponent {
  readonly cinemaBox = input<HTMLElement | null>(null);
  readonly listen = input<EmbedListen | null>(null);

  private readonly audio = inject(AudioPlayerService);

  protected readonly widened = signal(false);
  protected readonly queued = computed(() => {
    const offer = this.listen();
    return offer !== null && this.audio.isQueued(offer.media.url);
  });

  protected play(offer: EmbedListen): void {
    offer.actions.listen(offer.media, offer.frame);
  }

  protected toggleQueued(offer: EmbedListen): void {
    offer.actions.toggleQueued(offer.media);
  }

  protected toggleCinema(box: HTMLElement): void {
    const widened = !this.widened();
    box.classList.toggle(WIDENED, widened);
    this.widened.set(widened);
    box.scrollIntoView({ block: 'nearest' });
  }
}
