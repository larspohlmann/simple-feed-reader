import { computed, effect, Injectable, signal } from '@angular/core';
import { parseTextSize, TEXT_SIZE_DEFAULT, TEXT_SIZE_KEY, TEXT_SIZE_STEPS } from './text-size';

const LAST_INDEX = TEXT_SIZE_STEPS.length - 1;

@Injectable({ providedIn: 'root' })
export class TextSizeService {
  private readonly saved = signal(parseTextSize(localStorage.getItem(TEXT_SIZE_KEY)));
  readonly percent = this.saved.asReadonly();
  private readonly index = computed(() => TEXT_SIZE_STEPS.indexOf(this.percent()));

  readonly canDecrease = computed(() => this.index() > 0);
  readonly canIncrease = computed(() => this.index() < LAST_INDEX);
  readonly fillPercent = computed(() => (this.index() / LAST_INDEX) * 100);

  constructor() {
    // index.html's no-flash script paints the first frame; this keeps it in step afterwards.
    effect(() =>
      document.documentElement.style.setProperty('--text-scale', String(this.percent() / 100)),
    );
  }

  set(percent: number): void {
    if (!TEXT_SIZE_STEPS.includes(percent)) {
      return;
    }
    localStorage.setItem(TEXT_SIZE_KEY, String(percent));
    this.saved.set(percent);
  }

  increase(): void {
    this.set(TEXT_SIZE_STEPS[Math.min(this.index() + 1, LAST_INDEX)]);
  }

  decrease(): void {
    this.set(TEXT_SIZE_STEPS[Math.max(this.index() - 1, 0)]);
  }

  reset(): void {
    this.set(TEXT_SIZE_DEFAULT);
  }
}
