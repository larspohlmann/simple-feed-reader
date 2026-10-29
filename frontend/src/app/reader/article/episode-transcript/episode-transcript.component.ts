import { ChangeDetectionStrategy, Component, inject, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { formatDuration } from '../../format';
import { TranscriptDto } from '../../models';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** The episode's transcript atop its article; with a track, each timestamp starts it there (#1809, #1880). */
@Component({
  selector: 'app-episode-transcript',
  imports: [TranslocoPipe, ReaderPanelComponent],
  templateUrl: './episode-transcript.component.html',
  styleUrl: './episode-transcript.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EpisodeTranscriptComponent {
  readonly transcript = input.required<TranscriptDto>();
  readonly track = input<AudioTrack | null>(null);
  readonly startOpen = input(false);

  protected readonly player = inject(AudioPlayerService);

  protected readonly open = linkedSignal({
    source: () => ({ transcript: this.transcript(), startOpen: this.startOpen() }),
    computation: ({ startOpen }) => startOpen,
  });
  protected readonly format = formatDuration;
}
