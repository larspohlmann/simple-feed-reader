import { Binding, inputBinding } from '@angular/core';
import { isTextEntryTarget } from '../../../shared/text-entry-target';
import { embedMediaOfFrame } from '../../audio/embed-media';
import { CINEMA_BOX, EMBED_BOX } from '../decorators/player-box';
import { EmbedListen, ListenActions } from '../player-bar/embed-listen';
import { PlayerBarComponent } from '../player-bar/player-bar.component';
import { ArticleIslands } from './article-islands';

export class ArticlePlayerBars {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement, actions: ListenActions | undefined): void {
    for (const box of Array.from(host.querySelectorAll<HTMLElement>(CINEMA_BOX))) {
      const listen = listenOf(box.querySelector(EMBED_BOX), actions);
      this.islands.mountAtEnd(box, PlayerBarComponent, barBindings(box, listen));
    }
    for (const embed of Array.from(host.querySelectorAll<HTMLElement>(EMBED_BOX))) {
      if (embed.closest(CINEMA_BOX)) continue;
      const listen = listenOf(embed, actions);
      if (listen) this.islands.mountAfter(embed, PlayerBarComponent, barBindings(null, listen));
    }
  }

  /** `t` widens or narrows the video in view, else the first one, like YouTube's theatre key. */
  toggleCinemaByKey(event: KeyboardEvent): void {
    if (!isCinemaKey(event)) return;
    const offered = this.islands
      .instancesOf(PlayerBarComponent)
      .filter((bar) => bar.offersCinema());
    const bar = offered.find((candidate) => candidate.isInView()) ?? offered[0];
    if (!bar) return;
    event.preventDefault();
    bar.toggleCinema();
  }
}

function barBindings(cinemaBox: HTMLElement | null, listen: EmbedListen | null): Binding[] {
  return [inputBinding('cinemaBox', () => cinemaBox), inputBinding('listen', () => listen)];
}

function isCinemaKey(event: KeyboardEvent): boolean {
  if (event.key !== 't' || event.defaultPrevented) return false;
  if (event.metaKey || event.ctrlKey || event.altKey) return false;
  return !isTextEntryTarget(event.target);
}

function listenOf(embed: Element | null, actions: ListenActions | undefined): EmbedListen | null {
  const frame = embed?.querySelector<HTMLIFrameElement>(':scope > iframe') ?? null;
  const media = frame && embedMediaOfFrame(frame);
  if (!actions || !frame || !media) return null;
  return {
    media,
    listen: () => actions.listen(media, frame),
    toggleQueued: () => actions.toggleQueued(media),
  };
}
