import { Injectable, signal } from '@angular/core';
import { SocialNetworkName } from '../social-feed';

/** Which network each social feed belongs to, keyed by subscription id, for the pills on entries. */
@Injectable({ providedIn: 'root' })
export class FeedNetworks {
  private readonly byId = signal<ReadonlyMap<number, SocialNetworkName>>(new Map());

  networkOf(subscriptionId: number): SocialNetworkName | null {
    return this.byId().get(subscriptionId) ?? null;
  }

  replace(networks: ReadonlyMap<number, SocialNetworkName>): void {
    this.byId.set(networks);
  }
}
