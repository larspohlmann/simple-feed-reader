import { inputBinding } from '@angular/core';
import { NarrationToggleComponent } from '../narration-toggle/narration-toggle.component';
import { ArticleIslands } from './article-islands';

export class ArticleNarration {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement): void {
    for (const player of Array.from(
      host.querySelectorAll<HTMLAudioElement>('audio.reader-narration'),
    )) {
      this.islands.mountBefore(player, NarrationToggleComponent, [
        inputBinding('player', () => player),
      ]);
    }
  }
}
