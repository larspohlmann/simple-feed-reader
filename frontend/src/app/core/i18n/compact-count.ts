import { Injectable, computed, inject } from '@angular/core';
import { LanguageService } from './language.service';

@Injectable({ providedIn: 'root' })
export class CompactCount {
  private readonly language = inject(LanguageService);
  private readonly formatter = computed(
    () => new Intl.NumberFormat(this.language.lang(), { notation: 'compact' }),
  );

  format(count: number): string {
    return this.formatter().format(count);
  }
}
