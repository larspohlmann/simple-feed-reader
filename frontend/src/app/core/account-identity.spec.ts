import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity, userIdClaim } from './account-identity';
import { AuthService } from './auth.service';
import { TokenStore } from './token.store';

function jwtWith(claims: object): string {
  const encode = (part: object): string =>
    btoa(JSON.stringify(part)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  return `${encode({ alg: 'RS256' })}.${encode(claims)}.signature`;
}

describe('userIdClaim', () => {
  it('reads the userId claim', () => {
    expect(userIdClaim(jwtWith({ userId: 4711, username: 'a@b.c' }))).toBe(4711);
  });

  it('decodes a base64url payload that plain base64 would reject', () => {
    const token = jwtWith({ userId: 58, username: '>>>???>>>???' });
    expect(token.split('.')[1]).toMatch(/[-_]/);
    expect(userIdClaim(token)).toBe(58);
  });

  it('answers null for a token without the claim', () => {
    expect(userIdClaim(jwtWith({ username: 'a@b.c' }))).toBeNull();
  });

  it('answers null for no token, a token that is no JWT, and a claim that is no integer', () => {
    expect(userIdClaim(null)).toBeNull();
    expect(userIdClaim('stub-token-for-the-guard')).toBeNull();
    expect(userIdClaim('a.%%%.c')).toBeNull();
    expect(userIdClaim(jwtWith({ userId: '4711' }))).toBeNull();
    expect(userIdClaim(jwtWith({ userId: 47.5 }))).toBeNull();
  });
});

describe('AccountIdentity', () => {
  function setup(user: { id: number } | null) {
    localStorage.clear();
    const accountLoadFailed = signal(false);
    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthService,
          useValue: { user: signal(user), accountLoadFailed },
        },
      ],
    });
    return {
      identity: TestBed.inject(AccountIdentity),
      tokens: TestBed.inject(TokenStore),
      accountLoadFailed,
    };
  }

  it("prefers the stored token's claim over the loaded account", () => {
    const { identity, tokens } = setup({ id: 12 });
    tokens.set(jwtWith({ userId: 34 }));
    expect(identity.userId()).toBe(34);
  });

  it('falls back to the loaded account for a token issued before the claim', () => {
    const { identity, tokens } = setup({ id: 12 });
    tokens.set('legacy.token.value');
    expect(identity.userId()).toBe(12);
  });

  it('knows no account before either answers', () => {
    expect(setup(null).identity.userId()).toBeNull();
  });

  it('is settled once the account is known', () => {
    expect(setup({ id: 12 }).identity.settled()).toBe(true);
  });

  it('is unsettled while the account is unknown and still loading', () => {
    expect(setup(null).identity.settled()).toBe(false);
  });

  it('is settled when loading the account failed, though no account is known', () => {
    const { identity, accountLoadFailed } = setup(null);
    accountLoadFailed.set(true);
    expect(identity.userId()).toBeNull();
    expect(identity.settled()).toBe(true);
  });
});
