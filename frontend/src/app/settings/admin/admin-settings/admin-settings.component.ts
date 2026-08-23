// src/app/settings/admin/admin-settings/admin-settings.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem, parseProblem } from '../../../core/problem';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { AdminSettingsApi, InstanceSettings } from './admin-settings-api';

/** The registration-gate toggles (#224): whether a new signup needs email
 *  confirmation and/or admin approval before it becomes active. Each toggle
 *  saves immediately on change, mirroring the admin user queue's row actions —
 *  there is no separate save step to forget. */
@Component({
  selector: 'app-admin-settings',
  imports: [
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SkeletonComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './admin-settings.component.html',
})
export class AdminSettingsComponent implements OnInit {
  private readonly api = inject(AdminSettingsApi);

  readonly requireEmailConfirmation = signal(false);
  readonly requireApproval = signal(false);
  // mailEnabled reflects the deploy-time MAIL_DISABLED flag (#230), not a
  // toggle the admin can flip — it only explains why the email-confirmation
  // switch is disabled.
  readonly mailEnabled = signal(false);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get().subscribe({
      next: (settings) => {
        this.applySettings(settings);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  toggleEmailConfirmation(): void {
    this.save(!this.requireEmailConfirmation(), this.requireApproval());
  }

  toggleApproval(): void {
    this.save(this.requireEmailConfirmation(), !this.requireApproval());
  }

  private save(requireEmailConfirmation: boolean, requireApproval: boolean): void {
    this.error.set(null);
    this.api.update(requireEmailConfirmation, requireApproval).subscribe({
      next: (settings) => this.applySettings(settings),
      error: (failure: HttpErrorResponse) => this.error.set(parseProblem(failure)),
    });
  }

  private applySettings(settings: InstanceSettings): void {
    this.requireEmailConfirmation.set(settings.requireEmailConfirmation);
    this.requireApproval.set(settings.requireApproval);
    this.mailEnabled.set(settings.mailEnabled);
  }
}
