import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { translationOf } from './translation-of';

describe('translationOf', () => {
  let i18n: TranslocoService;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    i18n = TestBed.inject(TranslocoService);
  });

  it('gives the active language text for a key the dictionary has', () => {
    expect(translationOf(i18n, 'dialog.cancel')).toBe('Cancel');
    i18n.setActiveLang('de');
    expect(translationOf(i18n, 'dialog.cancel')).toBe('Abbrechen');
  });

  it('gives null for a key the dictionary lacks', () => {
    expect(translationOf(i18n, 'problem.no_such_type')).toBeNull();
  });
});
