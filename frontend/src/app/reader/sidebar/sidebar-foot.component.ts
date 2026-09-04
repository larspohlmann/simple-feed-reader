import { Component, computed, inject, model } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { BrightnessControlComponent } from './brightness-control.component';
import { ViewControlsComponent } from '../view-controls/view-controls.component';
import { AuthService } from '../../core/auth.service';
import { VersionService } from '../../core/version.service';
import { LayoutService } from '../layout.service';
import { buildVersion } from '../../../environments/version';
import { trialDaysRemaining } from '../format';

/**
 * The sidebar drawer's foot: Organise switch (coarse pointers only), brightness
 * stepper, view controls, trial countdown, and version/feedback links. Split
 * out of {@see SidebarComponent} for its own focused stylesheet. `organising`
 * is the only shared state, a two-way model the sidebar reads to hide the nav.
 */
@Component({
  selector: 'app-sidebar-foot',
  imports: [
    RouterLink,
    IconComponent,
    BrightnessControlComponent,
    ViewControlsComponent,
    TranslocoPipe,
  ],
  templateUrl: './sidebar-foot.component.html',
  styleUrl: './sidebar-foot.component.scss',
})
export class SidebarFootComponent {
  /** Baked in at build time, so it names the bundle actually running. */
  readonly version = buildVersion.version;

  private readonly versions = inject(VersionService);
  private readonly auth = inject(AuthService);
  readonly screen = inject(LayoutService);

  /** Toggled by the Organise switch and read by the sidebar to hide the nav. */
  readonly organising = model(false);

  /** The release to update to, or null when the running build is current. The
   *  update badge renders only when this is set. The shell triggers the check
   *  on app load; this only reads its result. */
  readonly availableUpdate = computed(() =>
    this.versions.updateAvailable() ? this.versions.latest() : null,
  );

  /** Whole days left in the current trial, or null when the account has no
   *  active trial. Expired trials read as null here — the account is suspended
   *  by then and never reaches this view. */
  readonly trialDaysLeft = computed<number | null>(() =>
    trialDaysRemaining(this.auth.user()?.trialEndsAt ?? null),
  );

  /** The last stretch of a trial is emphasised. */
  readonly trialEndingSoon = computed(() => {
    const daysLeft = this.trialDaysLeft();
    return daysLeft !== null && daysLeft <= 3;
  });
}
