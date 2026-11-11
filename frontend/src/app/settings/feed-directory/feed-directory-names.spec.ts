import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { FeedDirectoryNames } from './feed-directory-names';
import { FeedDirectoryIndex } from './feed-directory.models';

const INDEX: FeedDirectoryIndex = {
  countries: [
    { code: 'DE', name: 'Germany (index)', sites: 1, languages: { de: 1 } },
    { code: 'QQ', name: 'Qland', sites: 1, languages: { de: 1 } },
  ],
  languages: { de: 2 },
};

describe('FeedDirectoryNames', () => {
  let names: FeedDirectoryNames;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    names = TestBed.inject(FeedDirectoryNames);
  });

  it('names a country in the active language', () => {
    expect(names.country('DE')).toBe('Germany');

    TestBed.inject(TranslocoService).setActiveLang('de');

    expect(names.country('DE')).toBe('Deutschland');
  });

  it('prefers the Intl name over the index name', () => {
    names.learnCountryNames(INDEX);

    expect(names.country('DE')).toBe('Germany');
  });

  it('falls back to the index name, then the code, for a country Intl cannot name', () => {
    expect(names.country('QQ')).toBe('QQ');

    names.learnCountryNames(INDEX);

    expect(names.country('QQ')).toBe('Qland');
  });

  it('falls back to the code for a malformed code instead of failing', () => {
    expect(names.country('not a code!')).toBe('not a code!');
    expect(names.language('not a code!')).toBe('not a code!');
  });

  it('formats a count in the active language', () => {
    TestBed.inject(TranslocoService).setActiveLang('de');

    expect(names.count(12525)).toBe('12.525');
  });
});
