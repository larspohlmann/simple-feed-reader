import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { InfoTipComponent } from '../../shared/info-tip/info-tip.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { StepperBarComponent } from '../../shared/stepper-bar/stepper-bar.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import {
  FOR_YOU_STREAM_PRECISIONS,
  ForYouStreamPrecision,
  RecommendationSettingsService,
} from './recommendation-settings.service';

/** The For you stream's switch, precision dial and the AI's read-only word list.
 *  Every change persists at once through the card's `RecommendationSettingsService`. */
@Component({
  selector: 'app-for-you-stream-group',
  imports: [
    InfoTipComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    StepperBarComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './for-you-stream-group.component.html',
  styleUrl: './for-you-stream-group.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForYouStreamGroupComponent {
  private readonly svc = inject(RecommendationSettingsService);
  private readonly language = inject(LanguageService);

  readonly precisionSteps = FOR_YOU_STREAM_PRECISIONS.length;
  readonly enabled = computed(() => this.svc.forYouStream()?.enabled ?? false);
  readonly precision = computed<ForYouStreamPrecision>(
    () => this.svc.forYouStream()?.precision ?? 'balanced',
  );
  /** One-based position on the stepper bar, narrowest first. */
  readonly precisionStep = computed(() => FOR_YOU_STREAM_PRECISIONS.indexOf(this.precision()) + 1);
  readonly phrases = computed(() => this.svc.state()?.forYouStream.phrases ?? []);

  onEnabled(enabled: boolean): void {
    this.svc.saveForYouStream({ enabled, precision: this.precision() });
  }

  step(offset: -1 | 1): void {
    this.savePrecision(FOR_YOU_STREAM_PRECISIONS[this.precisionStep() - 1 + offset]);
  }

  resetPrecision(): void {
    const defaultPrecision = this.svc.state()?.forYouStream.defaultPrecision;
    if (defaultPrecision) this.savePrecision(defaultPrecision);
  }

  formatWeight(weight: number): string {
    return new Intl.NumberFormat(this.language.lang(), {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }).format(weight);
  }

  private savePrecision(precision: ForYouStreamPrecision): void {
    this.svc.saveForYouStream({ enabled: this.enabled(), precision });
  }
}
