import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { accountSignal } from './session-identity';
import { TokenStore } from './token.store';

@Injectable({ providedIn: 'root' })
class AccountHolder {
  readonly rows = accountSignal<string[]>([]);
}

describe('accountSignal', () => {
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
    tokens = TestBed.inject(TokenStore);
    tokens.set('account-a.jwt');
  });

  const holderFilledByAccountA = (): AccountHolder => {
    const holder = TestBed.inject(AccountHolder);
    holder.rows.set(['a-row']);
    TestBed.tick();
    return holder;
  };

  it('returns to its initial value when the account signs out', () => {
    const holder = holderFilledByAccountA();

    tokens.clear();
    TestBed.tick();

    expect(holder.rows()).toEqual([]);
  });

  it('returns to its initial value when another account signs in', () => {
    const holder = holderFilledByAccountA();

    tokens.set('account-b.jwt');
    TestBed.tick();

    expect(holder.rows()).toEqual([]);
  });

  it('keeps its value when the same token is set again', () => {
    const holder = holderFilledByAccountA();

    tokens.set('account-a.jwt');
    TestBed.tick();

    expect(holder.rows()).toEqual(['a-row']);
  });

  it('does not treat the token present at creation as a change', () => {
    const holder = holderFilledByAccountA();

    TestBed.tick();

    expect(holder.rows()).toEqual(['a-row']);
  });
});
