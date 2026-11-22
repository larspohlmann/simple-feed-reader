import { Injectable, inject, signal } from '@angular/core';
import { onIdentityChange } from '../../core/auth/session-identity';
import { SocialNetworkName } from '../../core/social-network';
import { Composer } from './composer/composer.service';

/**
 * Opens the composer for a post of text alone, from whichever network asks, keeping one draft for the session:
 * a closed composer leaves it for the next one, a post that reached any network clears it, and so does a logout.
 */
@Injectable({ providedIn: 'root' })
export class TextPostComposer {
  private readonly composer = inject(Composer);
  private readonly draft = signal('');

  constructor() {
    onIdentityChange(() => this.draft.set(''));
  }

  open(network: SocialNetworkName): void {
    this.composer.open({ kind: 'post', network, draft: this.draft }).subscribe((posts) => {
      this.draft.set('');
      this.composer.announce(posts);
    });
  }
}
