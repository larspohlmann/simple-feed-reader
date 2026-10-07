import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  signal,
} from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../../audio-player.service';
import { formatDuration } from '../../format';
import { AudioArtworkComponent } from './audio-artwork/audio-artwork.component';

const HEIGHT_PROPERTY = '--audio-player-height';

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
  /** The sidebar's value: a touch must hold before it drags, so a swipe still scrolls the list. */
  protected readonly dragDelay = { touch: 180, mouse: 0 };
  protected readonly playlistOpen = signal(false);

  constructor() {
    this.publishHeight();
  }

  protected onScrub(event: Event): void {
    this.player.seek((event.target as HTMLInputElement).valueAsNumber);
  }

  /** The current row reads the element's own duration, which corrects the feed's declared one. */
  protected rowDuration(index: number, track: AudioTrack): number | null {
    if (index === this.player.index() && this.player.duration() > 0) return this.player.duration();
    return track.durationInSeconds;
  }

  /** `--audio-player-height` on the root lets a viewport-fixed control (the reader's
   *  to-top button) sit clear of the bar, which paints above every shell layer. */
  private publishHeight(): void {
    if (typeof ResizeObserver === 'undefined') return;
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const root = document.documentElement.style;
    const observer = new ResizeObserver(() =>
      root.setProperty(HEIGHT_PROPERTY, `${Math.ceil(host.getBoundingClientRect().height)}px`),
    );
    observer.observe(host);
    inject(DestroyRef).onDestroy(() => {
      observer.disconnect();
      root.removeProperty(HEIGHT_PROPERTY);
    });
  }

  protected onDrop(event: CdkDragDrop<readonly AudioTrack[]>): void {
    this.player.move(event.previousIndex, event.currentIndex);
  }
}
