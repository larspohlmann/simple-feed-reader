import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { AudioPlayerService, SKIP_SECONDS } from '../audio-player.service';
import { formatDuration } from '../format';

/**
 * The reader's mini audio player, pinned to the bottom of the shell. It holds no
 * state: every control drives AudioPlayerService and every readout reflects its
 * signals, so the bar can unmount and remount without touching playback (#915).
 */
@Component({
  selector: 'app-audio-player-bar',
  imports: [IconComponent, FaviconComponent, TranslocoPipe],
  templateUrl: './audio-player-bar.component.html',
  styleUrl: './audio-player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioPlayerBarComponent {
  protected readonly player = inject(AudioPlayerService);
  protected readonly skipStep = SKIP_SECONDS;
  protected readonly format = formatDuration;

  protected onScrub(event: Event): void {
    this.player.seek((event.target as HTMLInputElement).valueAsNumber);
  }
}
