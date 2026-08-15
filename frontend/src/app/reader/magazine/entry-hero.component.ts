// src/app/reader/magazine/entry-hero.component.ts
import { Component, computed, effect, signal } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { entryImage } from '../preview-image';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-hero',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-hero.component.html',
  styleUrl: './entry-hero.component.scss',
})
export class EntryHeroComponent extends EntryBlockBase {
  readonly imgError = signal(false);
  readonly tooSmall = signal(false);
  readonly image = computed(() => entryImage(this.entry()));
  readonly showImage = computed(() => !!this.image() && !this.imgError() && !this.tooSmall());
  /** Honour the feed's own ratio so a square image is not cropped by 46%.
   *  Unknown dimensions keep the editorial default. */
  readonly aspect = computed(() => {
    const img = this.image();
    return img?.width && img?.height ? `${img.width} / ${img.height}` : '16 / 9';
  });
  onLoad(ev: Event): void {
    const img = ev.target as HTMLImageElement;
    if (img.naturalWidth && img.naturalWidth < 200) this.tooSmall.set(true);
  }

  // Reset the gates when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
    this.tooSmall.set(false);
  });
}
