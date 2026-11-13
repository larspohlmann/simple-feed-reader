import { Injectable } from '@angular/core';
import { NetworkExclusionsStore } from './network-exclusions.store';

@Injectable({ providedIn: 'root' })
export class AllItemsNetworksStore extends NetworkExclusionsStore {
  protected readonly surface = 'all-items';
}
