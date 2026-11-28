import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatOneDecimal } from '../../reader/format';
import { InfoTipComponent } from '../../shared/info-tip/info-tip.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { StepperBarComponent } from '../../shared/stepper-bar/stepper-bar.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import {
  FOR_YOU_STREAM_PRECISIONS,
  ForYouStreamPrecision,
  ForYouStreamState,
  RecommendationSettingsService,
} from './recommendation-settings.service';

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

  readonly stream = input.required<ForYouStreamState>();

  readonly precisionSteps = FOR_YOU_STREAM_PRECISIONS.length;
  readonly precisionIndex = computed(() =>
    FOR_YOU_STREAM_PRECISIONS.indexOf(this.stream().precision),
  );

  onEnabled(enabled: boolean): void {
    this.svc.saveForYouStream({ enabled, precision: this.stream().precision });
  }

  step(offset: -1 | 1): void {
    this.savePrecision(FOR_YOU_STREAM_PRECISIONS[this.precisionIndex() + offset]);
  }

  resetPrecision(): void {
    this.savePrecision(this.stream().defaultPrecision);
  }

  formatWeight(weight: number): string {
    return formatOneDecimal(weight, this.language.lang());
  }

  private savePrecision(precision: ForYouStreamPrecision): void {
    this.svc.saveForYouStream({ enabled: this.stream().enabled, precision });
  }
}
