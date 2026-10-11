import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  Signal,
  effect,
  inject,
  signal,
} from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { decorateArticle } from '../decorators/decorate-article';
import { toggleCinemaByKey } from '../decorators/reader-cinema';
import { replaceUnplayableVideo } from '../decorators/unplayable-videos';

export interface ArticleBodyParts {
  readonly content: Signal<ElementRef<HTMLElement> | undefined>;
  readonly html: Signal<string>;
  readonly pageUrl: () => string | null;
  readonly enclosureUrl: () => string | null;
  readonly decorated: (host: HTMLElement) => void;
}

/** The rendered article body's DOM wiring: its decorations, its media recovery and the
 *  `t` cinema key. `connect` creates its effects, so the host decides where they fall
 *  in its own effect order. */
@Injectable()
export class ArticleBody {
  private readonly i18n = inject(TranslocoService);
  private readonly injector = inject(Injector);
  private content: Signal<ElementRef<HTMLElement> | undefined> = signal(undefined);

  constructor() {
    const onKeydown = (event: KeyboardEvent): void =>
      toggleCinemaByKey(event, this.content()?.nativeElement);
    document.addEventListener('keydown', onKeydown);
    inject(DestroyRef).onDestroy(() => document.removeEventListener('keydown', onKeydown));
  }

  connect(parts: ArticleBodyParts): void {
    this.content = parts.content;
    this.recoverFailedMedia(parts);
    this.decorateEachRender(parts);
  }

  /** Body media arrive through [innerHTML], so one capturing listener gives images the proxy
   *  fallback and failed videos a link out (error events do not bubble). */
  private recoverFailedMedia(parts: ArticleBodyParts): void {
    effect(
      (onCleanup) => {
        const content = parts.content()?.nativeElement;
        if (!content) return;
        const recover = (event: Event) => {
          if (event.target instanceof HTMLImageElement) {
            void this.injector.get(ImageProxyService).recover(event.target);
            return;
          }
          replaceUnplayableVideo(event.target, () => ({
            pageUrl: parts.pageUrl(),
            title: this.i18n.translate('reader.unplayableVideo.title'),
            action: this.i18n.translate('reader.unplayableVideo.action'),
          }));
        };
        content.addEventListener('error', recover, true);
        onCleanup(() => content.removeEventListener('error', recover, true));
      },
      { injector: this.injector },
    );
  }

  /** Re-decorate whenever the rendered HTML changes (new article, or Reader/Original toggle). */
  private decorateEachRender(parts: ArticleBodyParts): void {
    effect(
      () => {
        parts.html();
        // Depend on the container too, not just the HTML: when extraction fails,
        // the HTML recomputes to the same string and never notifies, so the
        // container replacing the placeholder is the only render signal (#101).
        if (!parts.content()) return;
        queueMicrotask(() => {
          const host = parts.content()?.nativeElement;
          if (!host) return;
          decorateArticle(host, this.i18n, parts.enclosureUrl());
          parts.decorated(host);
        });
      },
      { injector: this.injector },
    );
  }
}
