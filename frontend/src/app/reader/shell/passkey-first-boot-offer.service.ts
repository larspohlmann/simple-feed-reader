import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { catchError, of } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { SetupService } from '../../core/setup/setup.service';
import { isPasskeySupported } from '../../core/auth/webauthn';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { PasskeyOfferDialogComponent } from './passkey-offer-dialog.component';
import { ReaderOnboarding } from './reader-onboarding.service';

@Injectable()
export class PasskeyFirstBootOffer {
  private readonly dialog = inject(Dialog);
  private readonly auth = inject(AuthService);
  private readonly setup = inject(SetupService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly onboarding = inject(ReaderOnboarding);

  private readonly eligible = computed(() => {
    if (!isPasskeySupported()) return false;
    // Exactly true: an instance that cannot sign in by passkey must not enrol one.
    if (this.setup.passkeySignInAvailable() !== true) return false;
    const user = this.auth.user();
    if (!user || user.preferences.passkeyOfferAnswered) return false;
    return this.subscriptions.resolved() && !this.onboarding.running();
  });

  private readonly shown = signal(false);

  constructor() {
    // The reader route is never behind setupRedirectGuard, so nothing else loads the flag.
    if (isPasskeySupported()) {
      this.setup
        .ensureLoaded()
        .pipe(catchError(() => of(false)))
        .subscribe();
    }
    effect(() => {
      if (!this.eligible() || this.shown()) return;
      untracked(() => {
        this.shown.set(true);
        this.dialog.open<void>(PasskeyOfferDialogComponent, { panelClass: 'app-dialog' });
      });
    });
  }
}
