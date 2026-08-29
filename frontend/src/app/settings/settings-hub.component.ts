import { ChangeDetectionStrategy, Component, effect, inject } from '@angular/core';
import { Router } from '@angular/router';
import { LayoutService } from '../reader/layout.service';
import { SettingsNavComponent } from './settings-nav.component';

/** The mobile landing page of the settings area — the "hub" of hub-and-spoke.
 *  A desktop viewport already shows the same navigation in the shell's rail,
 *  so this page forwards to the first section instead; replaceUrl keeps the
 *  redirect out of the back stack. The effect also catches the viewport
 *  crossing the breakpoint while the hub is open. */
@Component({
  selector: 'app-settings-hub',
  imports: [SettingsNavComponent],
  template: '<app-settings-nav variant="hub" />',
  styleUrl: './settings-hub.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsHubComponent {
  private readonly layout = inject(LayoutService);
  private readonly router = inject(Router);

  constructor() {
    effect(() => {
      if (this.layout.isWide()) {
        void this.router.navigate(['/settings/organise'], { replaceUrl: true });
      }
    });
  }
}
