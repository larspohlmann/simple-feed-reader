import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AudioPlayerService } from '../../audio-player.service';
import { EmbedListen } from './embed-listen';

const WIDENED = 'reader-cinema--on';

@Component({
  selector: 'app-player-bar',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './player-bar.component.html',
  styleUrl: './player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'reader-player-bar' },
})
export class PlayerBarComponent {
  readonly cinemaBox = input<HTMLElement | null>(null);
  readonly listen = input<EmbedListen | null>(null);

  private readonly audio = inject(AudioPlayerService);

  protected readonly widened = linkedSignal(
    () => this.cinemaBox()?.classList.contains(WIDENED) ?? false,
  );
  protected readonly queued = computed(() => {
    const offer = this.listen();
    return offer !== null && this.audio.isQueued(offer.media.url);
  });

  protected toggleCinema(box: HTMLElement): void {
    this.widened.set(box.classList.toggle(WIDENED));
    box.scrollIntoView({ block: 'nearest' });
  }
}
