import { ChangeDetectionStrategy, Component, computed, effect, signal } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { entryImage, widestRenditionWidth } from '../../../preview-image';
import { EntryBlockBase } from '../../entry-block-base';
import { RenditionsDirective } from '../../../renditions.directive';
import { FULL_COLUMN_SIZES } from '../../../rendition-sizes';

@Component({
  selector: 'app-entry-hero',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    RenditionsDirective,
  ],
  templateUrl: './entry-hero.component.html',
  styleUrl: './entry-hero.component.scss',
})
export class EntryHeroComponent extends EntryBlockBase {
  protected readonly fullColumnSizes = FULL_COLUMN_SIZES;
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
  /** A srcset makes `naturalWidth` the slot width, so a ladder is judged by its widest rung. */
  onLoad(event: Event): void {
    const width =
      widestRenditionWidth(this.entry().imageRenditions) ??
      (event.target as HTMLImageElement).naturalWidth;
    if (width && width < 200) this.tooSmall.set(true);
  }

  // Reset the gates when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
    this.tooSmall.set(false);
  });
}
