import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { toggled } from '../../../../core/toggled-set';
import { IconComponent } from '../../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../../shared/icon-button/icon-button.directive';
import { TOUCH_DRAG_START_DELAY } from '../../../../shared/touch-drag-delay';
import { AudioPlayerService, AudioTrack } from '../../../audio-player.service';
import { formatDuration } from '../../../format';
import { AudioArtworkComponent } from '../audio-artwork/audio-artwork.component';
import { AudioChaptersComponent } from '../audio-chapters/audio-chapters.component';

interface PlaylistRow {
  track: AudioTrack;
  current: boolean;
  played: boolean;
  durationInSeconds: number | null;
}

/**
 * The queue's rows. It never scrolls itself: its host does,
 * marked cdkScrollable so a drag auto-scrolls it.
 */
@Component({
  selector: 'app-audio-playlist',
  imports: [
    IconComponent,
    IconButtonDirective,
    AudioArtworkComponent,
    AudioChaptersComponent,
    TranslocoPipe,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
  ],
  templateUrl: './audio-playlist.component.html',
  styleUrl: './audio-playlist.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioPlaylistComponent {
  protected readonly player = inject(AudioPlayerService);
  protected readonly format = formatDuration;
  protected readonly dragDelay = TOUCH_DRAG_START_DELAY;

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

  protected readonly expanded = signal<ReadonlySet<string>>(new Set());

  protected onDrop(event: CdkDragDrop<readonly AudioTrack[]>): void {
    this.player.move(event.previousIndex, event.currentIndex);
  }

  protected toggleChapters(url: string): void {
    this.expanded.update((urls) => toggled(urls, url));
  }
}
