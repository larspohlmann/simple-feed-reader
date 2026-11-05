import { DestroyRef, Directive, ElementRef, Injector, inject } from '@angular/core';
import { PageLayer } from '../../shared/scroll-host/page-layer';
import { PageScrollOwner } from './page-scroll-owner';

/** A page-sized layer of the reader: the list column, or a full-screen article over it (#2038). */
@Directive({
  selector: '[appPageLayer]',
  exportAs: 'appPageLayer',
  providers: [
    {
      provide: PageLayer,
      useFactory: () => new PageLayer(inject<ElementRef<HTMLElement>>(ElementRef).nativeElement),
    },
  ],
})
export class PageLayerDirective {
  /** For an outlet whose template is declared elsewhere, and so cannot see this element's layer. */
  readonly injector = inject(Injector);

  constructor() {
    const owner = inject(PageScrollOwner);
    const layer = inject(PageLayer);
    owner.enter(layer);
    inject(DestroyRef).onDestroy(() => owner.leave(layer));
  }
}
