import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  input,
  NgZone,
  signal,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatOneDecimal } from '../../reader/format';
import { InfoTipComponent } from '../../shared/info-tip/info-tip.component';
import { observeResize } from '../../shared/observe-resize';
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

let nextPhraseClipId = 0;

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

  private readonly zone = inject(NgZone);

  private readonly phraseBody = viewChild<ElementRef<HTMLElement>>('phraseBody');
  private readonly phraseLimit = viewChild<ElementRef<HTMLElement>>('phraseLimit');
  readonly phraseClipId = `for-you-stream-phrase-clip-${nextPhraseClipId++}`;
  readonly phrasesOverflow = signal(false);
  readonly phrasesExpanded = signal(false);

  readonly precisionSteps = FOR_YOU_STREAM_PRECISIONS.length;
  readonly precisionIndex = computed(() =>
    FOR_YOU_STREAM_PRECISIONS.indexOf(this.stream().precision),
  );

  constructor() {
    effect((onCleanup) => {
      const body = this.phraseBody()?.nativeElement;
      const limit = this.phraseLimit()?.nativeElement;
      if (!body || !limit) return;
      const measure = (): void => this.phrasesOverflow.set(body.offsetHeight > limit.offsetHeight);
      measure();
      onCleanup(observeResize(this.zone, [body], measure));
    });
  }

  togglePhrases(): void {
    this.phrasesExpanded.update((expanded) => !expanded);
  }

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
