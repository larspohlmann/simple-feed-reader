import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../../core/api';

export type ProxyOutcome = 'recovered' | 'failed' | 'superseded';

const MAX_IN_FLIGHT = 4;

/** Retries an image the browser could not load direct through the backend proxy (#475). */
@Injectable({ providedIn: 'root' })
export class ImageProxyService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tried = new WeakMap<HTMLImageElement, string>();
  private inFlight = 0;
  private readonly waiting: (() => void)[] = [];

  async recover(image: HTMLImageElement): Promise<ProxyOutcome> {
    const source = image.currentSrc || image.src;
    if (!isForeignHttp(source) || this.tried.get(image) === source) return 'failed';
    this.tried.set(image, source);

    let bytes: Blob | null;
    try {
      bytes = await this.fetchWhileShowing(image, source);
    } catch {
      bytes = null;
    }
    if (shows(image) !== source) return 'superseded';
    if (!bytes) return 'failed';
    swapIn(image, URL.createObjectURL(bytes));
    return 'recovered';
  }

  private async fetchWhileShowing(image: HTMLImageElement, source: string): Promise<Blob | null> {
    const turn = this.acquire();
    if (turn) await turn;
    try {
      if (shows(image) !== source) return null;
      return await firstValueFrom(
        this.http.get(`${this.base}/api/image-proxy`, {
          params: { url: source },
          responseType: 'blob',
        }),
      );
    } finally {
      this.release();
    }
  }

  private acquire(): Promise<void> | null {
    if (this.inFlight < MAX_IN_FLIGHT) {
      this.inFlight++;
      return null;
    }
    return new Promise<void>((resolve) => this.waiting.push(resolve));
  }

  private release(): void {
    const next = this.waiting.shift();
    if (next) next();
    else this.inFlight--;
  }
}

function shows(image: HTMLImageElement): string {
  return image.currentSrc || image.src;
}

function isForeignHttp(source: string): boolean {
  return (
    /^https?:\/\//i.test(source) &&
    URL.canParse(source) &&
    new URL(source).origin !== location.origin
  );
}

function swapIn(image: HTMLImageElement, objectUrl: string): void {
  const release = () => URL.revokeObjectURL(objectUrl);
  image.addEventListener('load', release, { once: true });
  image.addEventListener('error', release, { once: true });
  image.removeAttribute('srcset');
  image.removeAttribute('sizes');
  image.src = objectUrl;
}
