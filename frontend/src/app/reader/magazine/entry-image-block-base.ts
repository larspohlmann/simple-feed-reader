// src/app/reader/magazine/entry-image-block-base.ts
import { Directive, computed, effect, signal } from '@angular/core';
import { EntryBlockBase } from './entry-block-base';
import { entryImage } from '../preview-image';

/** Adds the image-error gate shared by every image-bearing block (split, wide,
 *  thumb). Quote and kicker are deliberately text-only and extend
 *  `EntryBlockBase` directly instead — they must never carry these members. */
@Directive()
export abstract class EntryImageBlockBase extends EntryBlockBase {
  readonly imgError = signal(false);
  readonly image = computed(() => entryImage(this.entry()));
  readonly showImage = computed(() => !!this.image() && !this.imgError());

  // Reset the error gate when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
  });
}
