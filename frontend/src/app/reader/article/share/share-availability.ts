import { Injectable, computed, inject } from '@angular/core';
import { SharingService } from '../../../core/preferences/sharing.service';
import { CONFIGURED_TARGETS } from './share-targets';

/** Removing every target turns sharing off just as the switch does, the "Post as" entries included. */
@Injectable({ providedIn: 'root' })
export class ShareAvailability {
  private readonly sharing = inject(SharingService);

  readonly available = computed(
    () =>
      this.sharing.enabled() &&
      CONFIGURED_TARGETS.some((target) => !this.sharing.hidden().has(target.id)),
  );
}
