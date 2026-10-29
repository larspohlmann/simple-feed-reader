import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ChapterPlayhead, chapterPlayhead } from '../../audio/track-chapters';
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import { ChapterDto } from '../../models';
import { AudioChaptersComponent } from '../../shell/audio-player-bar/audio-chapters/audio-chapters.component';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

@Component({
  selector: 'app-embed-chapters',
  imports: [TranslocoPipe, ReaderPanelComponent, AudioChaptersComponent],
  templateUrl: './embed-chapters.component.html',
  styleUrl: './embed-chapters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EmbedChaptersComponent {
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly player = input.required<ChapterPlayer>();

  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));

  protected readonly open = signal(false);
  protected readonly playhead = signal<ChapterPlayhead | null>(null);

  constructor() {
    effect((onCleanup) => {
      const player = this.player();
      untracked(() => this.follow(player));
      onCleanup(() => player.release());
    });
  }

  protected play(index: number): void {
    this.player().playFrom(this.chapters()[index].startSeconds);
    this.playhead.set({ index, progress: null });
  }

  private follow(player: ChapterPlayer): void {
    this.playhead.set(null);
    player.watch((position) => this.playhead.set(this.playheadAt(position)));
  }

  private playheadAt(position: PlaybackPosition | null): ChapterPlayhead | null {
    return position === null ? null : chapterPlayhead(this.starts(), position);
  }
}
