// src/app/shared/user-avatar/user-avatar.component.ts
import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import { IconComponent } from '../icon/icon.component';
import { gravatarUrl, normalizeEmail, sha256Hex } from '../../core/gravatar';

/**
 * The user's Gravatar when their email has one, falling back to the generic
 * account icon otherwise (or while the hash resolves, or with no email). The
 * hash is computed client-side; only the hash — never the raw email — is sent
 * to Gravatar.
 */
@Component({
  selector: 'app-user-avatar',
  imports: [IconComponent],
  templateUrl: './user-avatar.component.html',
  styleUrl: './user-avatar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UserAvatarComponent {
  readonly email = input<string | null | undefined>(null);
  readonly size = input(24);

  private readonly src = signal<string | null>(null);
  private readonly failed = signal(false);
  readonly showImage = computed(() => this.src() !== null && !this.failed());
  readonly url = this.src.asReadonly();

  constructor() {
    effect(() => {
      const email = this.email();
      const size = this.size();
      this.failed.set(false);
      this.src.set(null);
      if (!email) return;
      void sha256Hex(normalizeEmail(email)).then((hash) => {
        // Ignore a hash that resolved after the input changed to another user.
        if (this.email() === email) this.src.set(gravatarUrl(hash, size * 2));
      });
    });
  }

  onError(): void {
    this.failed.set(true);
  }
}
