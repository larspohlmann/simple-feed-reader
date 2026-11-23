import { signal } from '@angular/core';
import { SubscriptionsStore } from '../app/reader/state/subscriptions.store';
import { SocialAccountRefDto } from '../app/reader/social-feed';

export function aMastodonAccount(serviceUrl = 'https://home.example'): SocialAccountRefDto {
  return {
    id: 1,
    network: 'mastodon',
    handle: '@me@home.example',
    status: 'connected',
    serviceUrl,
  };
}

export function provideSocialAccounts(accounts: SocialAccountRefDto[] = []) {
  return {
    provide: SubscriptionsStore,
    useValue: { lemmyHosts: signal([]), socialAccounts: signal(accounts) },
  };
}
