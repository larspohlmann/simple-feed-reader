import { inputBinding } from '@angular/core';
import { SlideshowControlsComponent } from '../slideshow/slideshow-controls.component';
import { ArticleIslands } from './article-islands';

export class ArticleSlideshows {
  constructor(private readonly islands: ArticleIslands) {}

  mount(host: HTMLElement): void {
    for (const figure of Array.from(host.querySelectorAll<HTMLElement>('.reader-slideshow'))) {
      const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
      const track = slides[0]?.parentElement;
      if (slides.length < 2 || !track) continue;
      this.islands.mountAfter(track, SlideshowControlsComponent, [
        inputBinding('figure', () => figure),
        inputBinding('slides', () => slides),
      ]);
    }
  }
}
