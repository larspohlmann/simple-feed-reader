import { inputBinding } from '@angular/core';
import { embedMediaOfFrame } from '../../audio/embed-media';
import { EmbedListen, ListenActions } from '../player-bar/embed-listen';
import { PlayerBarComponent } from '../player-bar/player-bar.component';
import { ArticleIslands } from './article-islands';

const CINEMA_BOX = '.reader-cinema';
const EMBED_BOX = '.reader-embed';

/** A rendered body's player bars: one in every cinema box and after every embed Listen plays (#1888). */
export class ArticlePlayerBars {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement, actions: ListenActions | undefined): void {
    for (const box of Array.from(host.querySelectorAll<HTMLElement>(CINEMA_BOX))) {
      const player = box.firstElementChild;
      if (!player) continue;
      this.mountBar(player, box, listenOf(box.querySelector(EMBED_BOX), actions));
    }
    for (const embed of Array.from(host.querySelectorAll<HTMLElement>(EMBED_BOX))) {
      if (embed.closest(CINEMA_BOX)) continue;
      const listen = listenOf(embed, actions);
      if (listen) this.mountBar(embed, null, listen);
    }
  }

  private mountBar(
    after: Element,
    cinemaBox: HTMLElement | null,
    listen: EmbedListen | null,
  ): void {
    this.islands.mountAfter(after, PlayerBarComponent, [
      inputBinding('cinemaBox', () => cinemaBox),
      inputBinding('listen', () => listen),
    ]);
  }
}

function listenOf(embed: Element | null, actions: ListenActions | undefined): EmbedListen | null {
  const frame = embed?.querySelector<HTMLIFrameElement>(':scope > iframe') ?? null;
  const media = frame && embedMediaOfFrame(frame);
  return actions && frame && media ? { media, frame, actions } : null;
}
