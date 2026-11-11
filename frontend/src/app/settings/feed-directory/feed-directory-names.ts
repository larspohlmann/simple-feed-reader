import { Injectable, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { FeedDirectoryIndex } from './feed-directory.models';

export function displayName(names: Intl.DisplayNames, code: string, fallback: string): string {
  try {
    return names.of(code) ?? fallback;
  } catch {
    return fallback;
  }
}

@Injectable({ providedIn: 'root' })
export class FeedDirectoryNames {
  private readonly transloco = inject(TranslocoService);
  readonly lang = toSignal(this.transloco.langChanges$, {
    initialValue: this.transloco.getActiveLang(),
  });
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
