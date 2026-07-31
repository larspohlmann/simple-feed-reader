// src/app/settings/about-section.component.ts
import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReleaseVersion, VersionService } from '../core/version.service';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { DEVELOPMENT_VERSION, buildVersion } from '../../environments/version';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';

/** One line of the About table: which half of the app, and what it reports. */
interface VersionRow {
  labelKey: string;
  release: ReleaseVersion | null;
}

@Component({
  selector: 'app-about-section',
  imports: [SettingsCardComponent, SpinnerComponent, TranslocoPipe],
  templateUrl: './about-section.component.html',
  styleUrl: './about-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AboutSectionComponent implements OnInit {
  private readonly versions = inject(VersionService);
  private readonly language = inject(LanguageService);

  readonly unavailable = this.versions.unavailable;

  /** True until the API version arrives or the endpoint is confirmed unreachable.
   *  The baked-in app version is always available, so only the API half can be
   *  pending. */
  readonly loading = computed(() => this.versions.apiVersion() === null && !this.unavailable());

  /** Empty for a development build, which has no build date to show. */
  buildDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  readonly rows = computed<VersionRow[]>(() => [
    { labelKey: 'settings.about.app', release: buildVersion },
    { labelKey: 'settings.about.api', release: this.versions.apiVersion() },
  ]);

  /**
   * Both halves ship together, so a difference means the browser is holding a
   * bundle from an earlier release. Only compares real releases: a development
   * build on either side is not evidence of a stale cache.
   */
  readonly staleBundle = computed(() => {
    const api = this.versions.apiVersion();
    if (api === null) {
      return false;
    }
    const bothAreReleases =
      api.version !== DEVELOPMENT_VERSION && buildVersion.version !== DEVELOPMENT_VERSION;

    return bothAreReleases && api.version !== buildVersion.version;
  });

  ngOnInit(): void {
    this.versions.load();
  }
}
