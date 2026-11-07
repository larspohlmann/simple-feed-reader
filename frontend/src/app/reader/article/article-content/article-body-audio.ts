import { inputBinding } from '@angular/core';
import { AudioPlayerService } from '../../audio-player.service';
import { BodyAudio, bodyAudioTrack } from '../../audio/body-audio';
import { EmbedTrackOrigin } from '../../audio/embed-track';
import { ListenLauncher } from '../../audio/listen-launcher';
import { ListenRowComponent } from '../listen-row/listen-row.component';
import { ArticleIslands } from './article-islands';

const REPLACED = 'reader-audio--replaced';

/** Hidden, not removed: a re-decoration over the same body must find the player again. */
export class ArticleBodyAudio {
  constructor(
    private readonly islands: ArticleIslands,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  mount(host: HTMLElement, origin: EmbedTrackOrigin | null): void {
    if (!origin) return;
    for (const element of Array.from(
      host.querySelectorAll<HTMLAudioElement>('audio:not(.reader-narration)'),
    )) {
      const track = bodyAudioTrack(element, origin);
      if (!track) continue;
      const offer = new BodyAudio(track, this.player, this.launcher);
      this.islands.mountBefore(element, ListenRowComponent, [inputBinding('audio', () => offer)]);
      element.classList.add(REPLACED);
    }
  }
}
