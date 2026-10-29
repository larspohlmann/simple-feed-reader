import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  input,
  linkedSignal,
  viewChild,
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
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly cinemaToggle = viewChild<ElementRef<HTMLButtonElement>>('cinemaToggle');

  protected readonly widened = linkedSignal(
    () => this.cinemaBox()?.classList.contains(WIDENED) ?? false,
  );
  protected readonly queued = computed(() => {
    const offer = this.listen();
    return offer !== null && this.audio.isQueued(offer.media.url);
  });

  toggleCinema(): void {
    const box = this.cinemaBox();
    if (!box) return;
    this.widened.set(box.classList.toggle(WIDENED));
    box.scrollIntoView({ block: 'nearest' });
  }

  offersCinema(): boolean {
    const toggle = this.cinemaToggle()?.nativeElement;
    return toggle !== undefined && getComputedStyle(toggle).display !== 'none';
  }

  isInView(): boolean {
    const { top, bottom } = this.host.nativeElement.getBoundingClientRect();
    return bottom > 0 && top < window.innerHeight;
  }
}
