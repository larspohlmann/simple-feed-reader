import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { AudioPlayerService } from '../audio-player.service';
import { toAudioTrack } from '../audio-attachment';
import { formatDuration } from '../format';
import { MediaView } from '../reading-layout.service';
import { AudioItem, audioItems, pictureTiles, videoTiles } from '../media-view-projection';
import { EntryDto } from '../models';

/**
 * The body of a media-first view (#916): a tile grid of the current selection's
 * pictures or videos, or a row list of its audio enclosures. It holds no scroll
 * or paging state — the entry list owns those and hands it the loaded entries.
 */
@Component({
  selector: 'app-media-view',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './media-view.component.html',
  styleUrl: './media-view.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MediaViewComponent {
  readonly mode = input.required<MediaView>();
  readonly entries = input.required<EntryDto[]>();
  readonly open = output<number>();

  private readonly player = inject(AudioPlayerService);
  protected readonly format = formatDuration;

  protected readonly pictures = computed(() => pictureTiles(this.entries()));
  protected readonly videos = computed(() => videoTiles(this.entries()));
  protected readonly audios = computed(() => audioItems(this.entries()));

  protected listen(item: AudioItem): void {
    this.player.play(toAudioTrack(item.entry, item.attachment));
  }
}
