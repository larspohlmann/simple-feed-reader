import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { CachedAccountChoice } from './cached-account-choice';
import { PREFERENCES_WRITER, ReadingPreferences } from './preferences-writer';

type Letter = 'a' | 'b';

const LETTER_KEY = 'sfr.testLetter';

@Injectable({ providedIn: 'root' })
class LetterChoice extends CachedAccountChoice<Letter> {
  constructor() {
    super({ key: LETTER_KEY, choices: ['a', 'b'], fallback: 'a', field: 'magazineStyle' });
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
    localStorage.setItem(LETTER_KEY, 'b');

    expect(make().value()).toBe('b');
  });

  it('starts from the fallback when nothing valid is cached', () => {
    localStorage.setItem(LETTER_KEY, 'z');

    expect(make().value()).toBe('a');
  });

  it('caches a set value and writes it under its field', () => {
    const choice = make();

    choice.set('b');

    expect(choice.value()).toBe('b');
    expect(localStorage.getItem(LETTER_KEY)).toBe('b');
    expect(written).toEqual([{ magazineStyle: 'b' }]);
  });

  it('flags a failed account write and keeps the local value', () => {
    result = of(false);
    const choice = make();

    choice.set('b');

    expect(choice.value()).toBe('b');
    expect(choice.saveFailed()).toBe(true);
  });

  it('adopts a known account value without writing it back', () => {
    const choice = make();

    choice.adopt(userWith('b'));

    expect(choice.value()).toBe('b');
    expect(localStorage.getItem(LETTER_KEY)).toBe('b');
    expect(written).toEqual([]);
  });

  it('ignores an account value it does not know', () => {
    const choice = make();

    choice.adopt(userWith('z'));

    expect(choice.value()).toBe('a');
    expect(localStorage.getItem(LETTER_KEY)).toBeNull();
    expect(written).toEqual([]);
  });

  it('resets to the fallback, cache and failure flag included', () => {
    result = of(false);
    const choice = make();
    choice.set('b');

    choice.reset();

    expect(choice.value()).toBe('a');
    expect(localStorage.getItem(LETTER_KEY)).toBeNull();
    expect(choice.saveFailed()).toBe(false);
  });
});
