// src/app/reader/sidebar/sidebar-foot.component.ts
import { Component, computed, inject, model } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ViewControlsComponent } from '../view-controls/view-controls.component';
import { AuthService } from '../../core/auth.service';
import { VersionService } from '../../core/version.service';
import { LayoutService } from '../layout.service';
import { buildVersion } from '../../../environments/version';
import { trialDaysRemaining } from '../format';

/**
 * The sidebar drawer's foot: the Organise switch (coarse pointers only), the
 * layout/theme view controls, the trial countdown and the version/update and
 * feedback links. Split out of {@see SidebarComponent} so each keeps its own
 * focused stylesheet — none of the foot's styles are shared with the nav rows
 * above, so the seam is clean. `organising` is the only shared state and is a
 * two-way model: the switch here toggles it, the sidebar reads it to hide the
 * nav while organising.
 */
@Component({
  selector: 'app-sidebar-foot',
  imports: [RouterLink, IconComponent, ViewControlsComponent, TranslocoPipe],
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
