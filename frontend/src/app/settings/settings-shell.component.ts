import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { filter, map } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { LayoutService } from '../reader/layout.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsNavComponent } from './settings-nav.component';
import { SETTINGS_SECTIONS } from './settings-sections';

/** The frame around every settings section: top bar, the desktop nav rail and
 *  the routed content column. Owns the two pieces of cross-section logic —
 *  where "back" leads, and which sections escape the default column width. */
@Component({
  selector: 'app-settings-shell',
  imports: [RouterLink, RouterOutlet, TranslocoPipe, IconComponent, SettingsNavComponent],
  templateUrl: './settings-shell.component.html',
  styleUrl: './settings-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsShellComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly language = inject(LanguageService);
  private readonly layout = inject(LayoutService);
  private readonly router = inject(Router);

  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  private readonly section = computed(
    () => SETTINGS_SECTIONS.find((s) => this.url().startsWith(`/settings/${s.path}`)) ?? null,
  );

  readonly wideSection = computed(() => this.section()?.wide === true);

  /** On a phone a section page steps back to the hub; everywhere else the bar
   *  leads back to the reader. */
  readonly backTarget = computed(() =>
    !this.layout.isWide() && this.section() !== null ? '/settings' : '/',
  );

  readonly backLabelKey = computed(() =>
    this.backTarget() === '/settings' ? 'settings.title' : 'settings.backReader',
  );

  ngOnInit(): void {
    // A deep link lands here without a loaded user, and the nav needs it to
    // decide on the admin group. authGuard has already ensured a token exists.
    if (this.auth.user() === null) {
      this.auth.loadMe().subscribe({
        next: (user) => this.language.adopt(user.locale),
        error: () => undefined,
      });
    }
  }
}
