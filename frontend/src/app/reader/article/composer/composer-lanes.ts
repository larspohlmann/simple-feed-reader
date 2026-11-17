import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { PostTargetDto } from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { ComposerLane } from './composer-lane';

/** The lanes of one open composer; provided by the dialog, so they go when it closes. */
@Injectable()
export class ComposerLanes {
  private readonly api = inject(SocialPostApi);
  private readonly i18n = inject(TranslocoService);
  private readonly built = signal<readonly ComposerLane[]>([]);

  readonly lanes = this.built.asReadonly();
  /** The lane whose subject and starting text the dialog shows. */
  readonly primary = computed(() => {
    const first = this.built()[0];
    if (first === undefined) throw new Error('ComposerLanes.init has not run');
    return first;
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => this.dispose());
  }

  init(target: PostTargetDto): void {
    this.dispose();
    const lane = new ComposerLane(target, this.api, this.i18n);
    this.built.set([lane]);
    lane.load();
  }

  private dispose(): void {
    for (const lane of this.built()) lane.dispose();
  }
}
