import { refreshScopeParams } from './refresh-scope-params';

describe('refreshScopeParams', () => {
  it.each([
    [{ kind: 'all' } as const, {}],
    [{ kind: 'feed', feedId: 42 } as const, { feedId: '42' }],
    [{ kind: 'tag', tagId: 3 } as const, { tag: '3' }],
    [{ kind: 'network', network: 'mastodon' } as const, { network: 'mastodon' }],
    [
      { kind: 'network', network: 'mastodon', feedKind: 'profile' } as const,
      { network: 'mastodon', feedKind: 'profile' },
    ],
    [{ kind: 'group', group: 'feeds' } as const, { group: 'feeds' }],
    [{ kind: 'neverFetched' } as const, { neverFetched: '1' }],
  ])('maps %o to its query parameters', (scope, params) => {
    expect(refreshScopeParams(scope)).toEqual(params);
  });

  it('maps no scope to every due feed', () => {
    expect(refreshScopeParams()).toEqual({});
  });
});
