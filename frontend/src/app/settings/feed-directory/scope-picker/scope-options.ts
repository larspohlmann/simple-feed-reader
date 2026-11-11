import { FeedDirectoryNames } from '../feed-directory-names';
import { FeedDirectoryIndex } from '../feed-directory.models';

export interface ScopeOption {
  readonly code: string;
  readonly name: string;
  readonly sites: number;
}

export function countryOptions(
  index: FeedDirectoryIndex,
  names: FeedDirectoryNames,
): ScopeOption[] {
  const options = index.countries.map((country) => ({
    code: country.code,
    name: names.country(country.code),
    sites: country.sites,
  }));

  return byName(options, names.lang());
}

export function languageOptions(
  index: FeedDirectoryIndex,
  names: FeedDirectoryNames,
): ScopeOption[] {
  const options = Object.entries(index.languages).map(([code, sites]) => ({
    code,
    name: names.language(code),
    sites,
  }));

  return byName(options, names.lang());
}

function byName(options: ScopeOption[], lang: string): ScopeOption[] {
  return options.sort((left, right) => left.name.localeCompare(right.name, lang));
}
