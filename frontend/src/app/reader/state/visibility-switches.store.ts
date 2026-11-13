import { Injectable, computed, inject } from '@angular/core';
import { bySurface } from '../feed-exclusions';
import { NetworkSurfaceSwitches } from './network-surface-switches.service';
import { TagsStore } from './tags.store';
import { VisibilitySwitches } from './visibility';

/** The stored switches `isVisibleIn` reads, gathered live from their stores. */
@Injectable({ providedIn: 'root' })
export class VisibilitySwitchesStore {
  private readonly tags = inject(TagsStore);
  private readonly networkSwitches = inject(NetworkSurfaceSwitches);

  readonly switches = computed<VisibilitySwitches>(() => ({
    tagsById: this.tags.tagsById(),
    excludedNetworks: bySurface(({ surface }) => this.networkSwitches.excludedNetworks(surface)),
  }));
}
