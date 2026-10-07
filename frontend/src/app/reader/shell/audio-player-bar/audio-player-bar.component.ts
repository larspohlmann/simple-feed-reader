import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { TOUCH_DRAG_START_DELAY } from '../../../shared/touch-drag-delay';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../../audio-player.service';
import { formatDuration } from '../../format';
import { AudioArtworkComponent } from './audio-artwork/audio-artwork.component';

interface PlaylistRow {
  track: AudioTrack;
  current: boolean;
  played: boolean;
  durationInSeconds: number | null;
}

/**
 * The reader's mini audio player, pinned to the bottom of the shell. It holds no
 * playback state: every control drives AudioPlayerService and every readout
 * reflects its signals, so the bar can unmount and remount without touching
 * playback (#915). Only whether the playlist panel is open is its own (#1429).
 */
@Component({
  selector: 'app-audio-player-bar',
  imports: [
    IconComponent,
    IconButtonDirective,
    AudioArtworkComponent,
    TranslocoPipe,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
  ],
  templateUrl: './audio-player-bar.component.html',
  styleUrl: './audio-player-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioPlayerBarComponent {
  protected readonly player = inject(AudioPlayerService);
  protected readonly skipStep = SKIP_SECONDS;
  protected readonly format = formatDuration;
  protected readonly dragDelay = TOUCH_DRAG_START_DELAY;
  protected readonly playlistOpen = signal(false);

  /** The current row reads the element's duration, which corrects the feed's declared one. */
  protected readonly rows = computed(() => {
    const current = this.player.index();
    const measured = this.player.duration();
    return this.player.tracks().map((track, index): PlaylistRow => ({
      track,
      current: index === current,
      played: index < current,
      durationInSeconds: index === current && measured > 0 ? measured : track.durationInSeconds,
    }));
  });

  protected onScrub(event: Event): void {
    this.player.seek((event.target as HTMLInputElement).valueAsNumber);
  }

  protected onDrop(event: CdkDragDrop<readonly AudioTrack[]>): void {
    this.player.move(event.previousIndex, event.currentIndex);
  }
}
