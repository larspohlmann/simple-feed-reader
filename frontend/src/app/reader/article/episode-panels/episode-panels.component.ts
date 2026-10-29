import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { AudioTrack } from '../../audio-player.service';
import { ChapterDto, TranscriptDto } from '../../models';
import { EpisodeChaptersComponent } from '../episode-chapters/episode-chapters.component';
import { EpisodeTranscriptComponent } from '../episode-transcript/episode-transcript.component';

/** The episode's chapters and transcript, stacked in the article header (#1880). */
@Component({
  selector: 'app-episode-panels',
  imports: [EpisodeChaptersComponent, EpisodeTranscriptComponent],
  templateUrl: './episode-panels.component.html',
  styleUrl: './episode-panels.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EpisodePanelsComponent {
  readonly track = input.required<AudioTrack | null>();
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly transcript = input.required<TranscriptDto | null>();
  readonly transcriptIsTheBody = input.required<boolean>();
}
