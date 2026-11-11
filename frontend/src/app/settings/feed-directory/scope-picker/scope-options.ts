import { displayName } from '../feed-directory-names';
import { FeedDirectoryIndex } from '../feed-directory.models';

export interface ScopeOption {
  readonly code: string;
  readonly name: string;
  readonly sites: number;
}

export function countryOptions(index: FeedDirectoryIndex, lang: string): ScopeOption[] {
  const names = new Intl.DisplayNames([lang], { type: 'region', fallback: 'none' });
  const options = index.countries.map((country) => ({
    code: country.code,
    name: displayName(names, country.code, country.name),
    sites: country.sites,
  }));

  return byName(options, lang);
}

export function languageOptions(index: FeedDirectoryIndex, lang: string): ScopeOption[] {
  const names = new Intl.DisplayNames([lang], { type: 'language', fallback: 'none' });
  const options = Object.entries(index.languages).map(([code, sites]) => ({
    code,
    name: displayName(names, code, code),
    sites,
  }));

  return byName(options, lang);
}

function byName(options: ScopeOption[], lang: string): ScopeOption[] {
  return options.sort((left, right) => left.name.localeCompare(right.name, lang));
}
