import { Injectable, computed, inject, signal } from '@angular/core';
import { LanguageService } from '../../core/i18n/language.service';
import { FeedDirectoryIndex } from './feed-directory.models';

function displayName(names: Intl.DisplayNames, code: string, fallback: string): string {
  try {
    return names.of(code) ?? fallback;
  } catch {
    return fallback;
  }
}

@Injectable({ providedIn: 'root' })
export class FeedDirectoryNames {
  readonly lang = inject(LanguageService).lang;
  private readonly regions = computed(
    () => new Intl.DisplayNames([this.lang()], { type: 'region', fallback: 'none' }),
  );
  private readonly languages = computed(
    () => new Intl.DisplayNames([this.lang()], { type: 'language', fallback: 'none' }),
  );
  private readonly numbers = computed(() => new Intl.NumberFormat(this.lang()));
  private readonly indexCountryNames = signal<ReadonlyMap<string, string>>(new Map());

  learnCountryNames(index: FeedDirectoryIndex): void {
    this.indexCountryNames.set(new Map(index.countries.map(({ code, name }) => [code, name])));
  }

  country(code: string): string {
    return displayName(this.regions(), code, this.indexCountryNames().get(code) ?? code);
  }

  language(code: string): string {
    return displayName(this.languages(), code, code);
  }

  count(value: number): string {
    return this.numbers().format(value);
  }
}
