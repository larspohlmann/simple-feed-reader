import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { chapterPlayhead } from '../../audio/track-chapters';
import { ChapterDto } from '../../models';
import { AudioChaptersComponent } from '../../shell/audio-player-bar/audio-chapters/audio-chapters.component';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** The episode's chapters atop its article, following the player (#1808, #1880). */
@Component({
  selector: 'app-episode-chapters',
  imports: [TranslocoPipe, ReaderPanelComponent, AudioChaptersComponent],
  templateUrl: './episode-chapters.component.html',
  styleUrl: './episode-chapters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EpisodeChaptersComponent {
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly track = input.required<AudioTrack>();

  private readonly player = inject(AudioPlayerService);
  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));

  protected readonly open = linkedSignal({ source: this.chapters, computation: () => false });
  protected readonly playhead = computed(() =>
    this.player.current()?.url === this.track().url
      ? chapterPlayhead(this.starts(), this.player.playbackPosition())
      : null,
  );

  protected play(index: number): void {
    this.player.playFrom(this.track(), this.chapters()[index].startSeconds);
  }
}
