import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../../core/api';

/** Retries an image the browser could not load direct through the backend proxy (#475). */
@Injectable({ providedIn: 'root' })
export class ImageProxyService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tried = new WeakMap<HTMLImageElement, string>();

  async recover(image: HTMLImageElement): Promise<boolean> {
    const source = image.currentSrc || image.src;
    if (!isForeignHttp(source) || this.tried.get(image) === source) return false;
    this.tried.set(image, source);

    let bytes: Blob;
    try {
      bytes = await firstValueFrom(
        this.http.get(`${this.base}/api/image-proxy`, {
          params: { url: source },
          responseType: 'blob',
        }),
      );
    } catch {
      return false;
    }
    swapIn(image, URL.createObjectURL(bytes));
    return true;
  }
}

function isForeignHttp(source: string): boolean {
  return /^https?:\/\//i.test(source) && new URL(source).origin !== location.origin;
}

function swapIn(image: HTMLImageElement, objectUrl: string): void {
  const release = () => URL.revokeObjectURL(objectUrl);
  image.addEventListener('load', release, { once: true });
  image.addEventListener('error', release, { once: true });
  image.removeAttribute('srcset');
  image.removeAttribute('sizes');
  image.src = objectUrl;
}
