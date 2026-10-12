import { Directive, ElementRef, effect, inject, input, untracked } from '@angular/core';
import { embedFrame } from './media-embeds';

/** Builds its frame through `embedFrame`, never a template `[src]` (#1594). */
@Directive({ selector: '[appPlayingEmbed]' })
export class PlayingEmbedDirective {
  readonly appPlayingEmbed = input.required<string>();
  readonly embedTitle = input.required<string>();
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  constructor() {
    effect(() => {
      const frame = embedFrame(this.appPlayingEmbed(), untracked(this.embedTitle));
      frame.setAttribute('allow', 'autoplay; fullscreen');
      this.host.replaceChildren(frame);
    });
  }
}
