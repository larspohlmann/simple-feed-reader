import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  effect,
  ElementRef,
  inject,
  NgZone,
  signal,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatOneDecimal } from '../../reader/format';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { InfoTipComponent } from '../../shared/info-tip/info-tip.component';
import { observeResize } from '../../shared/observe-resize';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsRowComponent } from '../../shared/settings/settings-row/settings-row.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { StepperBarComponent } from '../../shared/stepper-bar/stepper-bar.component';
import { toastOnSaved } from '../../shared/toast/saved-toast';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import {
  YOUR_STREAM_PRECISIONS,
  YourStreamSettings,
  YourStreamSettingsService,
} from './your-stream-settings.service';

let nextWordClipId = 0;

@Component({
  selector: 'app-your-stream-section',
  imports: [
    ErrorBannerComponent,
    InfoTipComponent,
    SettingsGroupComponent,
    SettingsRowComponent,
    SettingsStackComponent,
    SpinnerComponent,
    StepperBarComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './your-stream-section.component.html',
  styleUrl: './your-stream-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class YourStreamSectionComponent implements OnInit {
  protected readonly service = inject(YourStreamSettingsService);
  private readonly language = inject(LanguageService);
  private readonly zone = inject(NgZone);

  private readonly wordBody = viewChild<ElementRef<HTMLElement>>('wordBody');
  private readonly wordLimit = viewChild<ElementRef<HTMLElement>>('wordLimit');
  readonly wordClipId = `your-stream-word-clip-${nextWordClipId++}`;
  readonly wordsOverflow = signal(false);
  readonly wordsExpanded = signal(false);

  readonly precisionSteps = YOUR_STREAM_PRECISIONS.length;
  readonly precisionIndex = computed(() => {
    const precision = this.service.shown()?.precision;
    return precision === undefined ? 0 : YOUR_STREAM_PRECISIONS.indexOf(precision);
  });

  constructor() {
    toastOnSaved(this.service, 'settings.yourStream.saved');
    effect((onCleanup) => {
      const body = this.wordBody()?.nativeElement;
      const limit = this.wordLimit()?.nativeElement;
      if (!body || !limit) return;
      const measure = (): void => this.wordsOverflow.set(body.offsetHeight > limit.offsetHeight);
      measure();
      onCleanup(observeResize(this.zone, [body], measure));
    });
  }

  ngOnInit(): void {
    this.service.load();
  }

  toggleWords(): void {
    this.wordsExpanded.update((expanded) => !expanded);
  }

  change(patch: Partial<YourStreamSettings>): void {
    this.service.saveInstant(patch);
  }

  step(offset: -1 | 1): void {
    this.change({ precision: YOUR_STREAM_PRECISIONS[this.precisionIndex() + offset] });
  }

  resetPrecision(): void {
    const shown = this.service.shown();
    if (shown) this.change({ precision: shown.defaultPrecision });
  }

  languageName(language: string | null): string {
    return language === null ? 'settings.yourStream.noLanguage' : `reader.feedLanguage.${language}`;
  }

  formatWeight(weight: number): string {
    return formatOneDecimal(weight, this.language.lang());
  }
}
