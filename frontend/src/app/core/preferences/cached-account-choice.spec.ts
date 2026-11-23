import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { CachedAccountChoice } from './cached-account-choice';
import { PREFERENCES_WRITER, ReadingPreferences } from './preferences-writer';

const LETTER_KEY = 'sfr.testLetter';

@Injectable({ providedIn: 'root' })
class LetterChoice extends CachedAccountChoice<'magazineStyle'> {
  constructor() {
    super({
      key: LETTER_KEY,
      choices: ['boxed', 'airy'],
      fallback: 'boxed',
      field: 'magazineStyle',
    });
  }
}

function userWith(magazineStyle: string): CurrentUser {
  return { preferences: { magazineStyle } } as CurrentUser;
}

describe('CachedAccountChoice', () => {
  let written: Partial<ReadingPreferences>[];
  let result: Observable<boolean>;

  function make(): LetterChoice {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: PREFERENCES_WRITER,
          useValue: {
            write: (change: Partial<ReadingPreferences>) => {
              written.push(change);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(LetterChoice);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('starts from a cached valid value', () => {
    localStorage.setItem(LETTER_KEY, 'airy');

    expect(make().value()).toBe('airy');
  });

  it('starts from the fallback when nothing valid is cached', () => {
    localStorage.setItem(LETTER_KEY, 'zzz');

    expect(make().value()).toBe('boxed');
  });

  it('caches a set value and writes it under its field', () => {
    const choice = make();

    choice.set('airy');

    expect(choice.value()).toBe('airy');
    expect(localStorage.getItem(LETTER_KEY)).toBe('airy');
    expect(written).toEqual([{ magazineStyle: 'airy' }]);
  });

  it('flags a failed account write and keeps the local value', () => {
    result = of(false);
    const choice = make();

    choice.set('airy');

    expect(choice.value()).toBe('airy');
    expect(choice.saveFailed()).toBe(true);
  });

  it('adopts a known account value without writing it back', () => {
    const choice = make();

    choice.adopt(userWith('airy'));

    expect(choice.value()).toBe('airy');
    expect(localStorage.getItem(LETTER_KEY)).toBe('airy');
    expect(written).toEqual([]);
  });

  it('ignores an account value it does not know', () => {
    const choice = make();

    choice.adopt(userWith('zzz'));

    expect(choice.value()).toBe('boxed');
    expect(localStorage.getItem(LETTER_KEY)).toBeNull();
    expect(written).toEqual([]);
  });

  it('resets to the fallback, cache and failure flag included', () => {
    result = of(false);
    const choice = make();
    choice.set('airy');

    choice.reset();

    expect(choice.value()).toBe('boxed');
    expect(localStorage.getItem(LETTER_KEY)).toBeNull();
    expect(choice.saveFailed()).toBe(false);
  });
});
