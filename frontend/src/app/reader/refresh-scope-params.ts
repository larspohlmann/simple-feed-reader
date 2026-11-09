import { RefreshScope } from './query/query';

export function refreshScopeParams(scope: RefreshScope = { kind: 'all' }): Record<string, string> {
  switch (scope.kind) {
    case 'all':
      return {};
    case 'feed':
      return { feedId: String(scope.feedId) };
    case 'tag':
      return { tag: String(scope.tagId) };
    case 'network':
      return scope.feedKind != null
        ? { network: scope.network, feedKind: scope.feedKind }
        : { network: scope.network };
    case 'group':
      return { group: scope.group };
    case 'neverFetched':
      return { neverFetched: '1' };
  }
}
