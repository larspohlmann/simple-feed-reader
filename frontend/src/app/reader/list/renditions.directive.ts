import { Directive, computed, input } from '@angular/core';
import { ImageRenditionDto } from '../models';
import { renditionSrcset } from './preview-image';

/** Lets the browser load the smallest rendition that covers the image's rendered width;
 *  an image without renditions keeps its plain `src`. */
@Directive({
  selector: 'img[appRenditions]',
  host: { '[attr.srcset]': 'srcset()', '[attr.sizes]': 'sizes()' },
})
export class RenditionsDirective {
  readonly appRenditions = input.required<readonly ImageRenditionDto[] | undefined>();
  /** The image's rendered width as a `sizes` value, read off its block's CSS. Bind it
   *  (`[renditionSizes]="'132px'"`): a static attribute would also land in the DOM. */
  readonly renditionSizes = input.required<string>();

  protected readonly srcset = computed(() => renditionSrcset(this.appRenditions()));
  protected readonly sizes = computed(() =>
    this.srcset() === null ? null : this.renditionSizes(),
  );
}
