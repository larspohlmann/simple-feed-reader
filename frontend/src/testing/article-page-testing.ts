import { Provider, signal } from '@angular/core';
import { ListAutoplayService } from '../app/core/preferences/list-autoplay.service';
import { PostActions } from '../app/reader/article/composer/post-actions.service';
import { SubscriptionsStore } from '../app/reader/state/subscriptions.store';
import { ImageProxyService } from '../app/shared/proxied-image/image-proxy.service';
import { failingImageProxy } from './image-proxy-testing';

/** The collaborators an article page's shell reaches for, stubbed inert. */
export function provideArticlePageStubs(): Provider[] {
  return [
    { provide: ListAutoplayService, useValue: { enabled: signal(false) } },
    { provide: SubscriptionsStore, useValue: { socialAccounts: signal([]) } },
    { provide: PostActions, useValue: {} },
    { provide: ImageProxyService, useValue: failingImageProxy() },
  ];
}
