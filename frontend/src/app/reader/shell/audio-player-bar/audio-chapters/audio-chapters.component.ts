import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { IconComponent } from '../../../../shared/icon/icon.component';
import type { ChapterPlayhead } from '../../../audio/track-chapters';
import { formatDuration } from '../../../format';
import type { ChapterDto } from '../../../models';

/**
 * The chapter rows of the article's list as a component (#1810). It reads nothing from the
 * player, so the big player shows the playing track's chapters with it and a queue row can
 * show another track's.
 */
@Component({
  selector: 'app-audio-chapters',
  imports: [IconComponent],
  templateUrl: './audio-chapters.component.html',
  styleUrl: './audio-chapters.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioChaptersComponent {
  readonly chapters = input.required<readonly ChapterDto[]>();
  readonly playhead = input<ChapterPlayhead | null>(null);
  readonly pick = output<number>();

  protected readonly format = formatDuration;
}
