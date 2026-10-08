import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { CdkScrollable } from '@angular/cdk/scrolling';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AudioPlayerService, SKIP_SECONDS } from '../../audio-player.service';
import { scrubFill } from '../../audio/scrub-fill';
import { formatDuration } from '../../format';
import { AudioArtworkComponent } from './audio-artwork/audio-artwork.component';
import { AudioPlaylistComponent } from './audio-playlist/audio-playlist.component';
import { AudioSurface } from './audio-surface.service';

/**
 * The reader's mini audio player, pinned to the bottom of the shell. It holds no
 * playback state: every control drives AudioPlayerService and every readout
 * reflects its signals, so the bar can unmount and remount without touching
 * playback (#915).
 */
@Component({
  selector: 'app-audio-player-bar',
  imports: [
    IconComponent,
    AudioArtworkComponent,
    TranslocoPipe,
    CdkScrollable,
    AudioPlaylistComponent,
  ],
  templateUrl: './audio-player-bar.component.html',
  styleUrl: './audio-player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioPlayerBarComponent {
  protected readonly player = inject(AudioPlayerService);
  protected readonly skipStep = SKIP_SECONDS;
  protected readonly format = formatDuration;
  protected readonly surface = inject(AudioSurface);

  protected readonly progress = computed(() =>
    scrubFill(this.player.position(), this.player.buffered(), this.player.duration()),
  );

  protected onScrub(event: Event): void {
    this.player.seek((event.target as HTMLInputElement).valueAsNumber);
  }
}
