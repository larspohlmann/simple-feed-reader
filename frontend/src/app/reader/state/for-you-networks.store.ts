import { Injectable } from '@angular/core';
import { NetworkExclusionsStore } from './network-exclusions.store';

@Injectable({ providedIn: 'root' })
export class ForYouNetworksStore extends NetworkExclusionsStore {
  protected readonly surface = 'for-you';
}
