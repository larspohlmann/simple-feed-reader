import { Directive, ElementRef, Injector, inject, output } from '@angular/core';
import { ImageProxyService } from './image-proxy.service';

/** An <img> that retries through the backend proxy before it counts as broken. */
@Directive({
  selector: 'img[appProxiedImage]',
  host: { '(error)': 'onError()' },
})
export class ProxiedImageDirective {
  readonly imageFailed = output<void>();

  private readonly image = inject<ElementRef<HTMLImageElement>>(ElementRef).nativeElement;
  // Resolved on the first error only, so hosts rendered without HttpClient never need one.
  private readonly injector = inject(Injector);

  protected onError(): void {
    void this.injector
      .get(ImageProxyService)
      .recover(this.image)
      .then((recovered) => {
        if (!recovered) this.imageFailed.emit();
      });
  }
}
