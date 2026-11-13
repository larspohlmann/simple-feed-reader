import { Injectable, inject } from '@angular/core';
import { EntriesStore } from './entries.store';
import { NetworkExclusionsStore } from './network-exclusions.store';

@Injectable({ providedIn: 'root' })
export class TrendingNetworksStore extends NetworkExclusionsStore {
  protected readonly surface = 'trending';
  private readonly entries = inject(EntriesStore);

  protected override refreshSurface(): void {
    super.refreshSurface();
    this.entries.reloadTrending();
  }
}
