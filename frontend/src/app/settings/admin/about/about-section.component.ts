import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReleaseVersion, VersionService } from '../../../core/version.service';
import { LanguageService } from '../../../core/i18n/language.service';
import { formatLongDate } from '../../../reader/format';
import { DEVELOPMENT_VERSION, buildVersion } from '../../../../environments/version';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';
import { IconComponent } from '../../../shared/icon/icon.component';

/** One line of the version table: which half of the app, and what it reports. */
interface VersionRow {
  labelKey: string;
  release: ReleaseVersion | null;
}

const REPOSITORY_URL = 'https://github.com/larspohlmann/simple-feed-reader';

@Component({
  selector: 'app-about-section',
  imports: [
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SpinnerComponent,
    IconComponent,
    TranslocoPipe,
  ],
  templateUrl: './about-section.component.html',
  styleUrl: './about-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AboutSectionComponent implements OnInit {
  private readonly versions = inject(VersionService);
  private readonly language = inject(LanguageService);

  readonly author = 'Lars Pohlmann';
  readonly copyrightYear = new Date().getFullYear();
  readonly links = {
    source: REPOSITORY_URL,
    issues: `${REPOSITORY_URL}/issues`,
    license: `${REPOSITORY_URL}/blob/main/LICENSE`,
    changelog: `${REPOSITORY_URL}/blob/main/CHANGELOG.md`,
  };

  readonly unavailable = this.versions.unavailable;

  /** True until the API version arrives or the endpoint is confirmed
   *  unreachable. The baked-in app version is always available. */
  readonly loading = computed(() => this.versions.apiVersion() === null && !this.unavailable());

  readonly rows = computed<VersionRow[]>(() => [
    { labelKey: 'settings.about.app', release: buildVersion },
    { labelKey: 'settings.about.api', release: this.versions.apiVersion() },
  ]);

  /** Both halves ship together, so a difference means a stale browser bundle.
   *  Only compares real releases: a development build on either side is not
   *  evidence of staleness. */
  readonly staleBundle = computed(() => {
    const api = this.versions.apiVersion();
    if (api === null) {
      return false;
    }
    const bothAreReleases =
      api.version !== DEVELOPMENT_VERSION && buildVersion.version !== DEVELOPMENT_VERSION;

    return bothAreReleases && api.version !== buildVersion.version;
  });

  /** Empty for a development build, which has no build date to show. */
  buildDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  ngOnInit(): void {
    this.versions.load();
  }
}
