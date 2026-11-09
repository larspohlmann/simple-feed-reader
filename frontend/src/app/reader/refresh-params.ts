import { RefreshScope } from './query/query';

export function refreshParams(refresh: boolean): Record<string, string> {
  return refresh ? { refresh: '1' } : {};
}

export function refreshScopeParams(scope: RefreshScope = {}): Record<string, string> {
  if (scope.feedId != null) return { feedId: String(scope.feedId) };
  if (scope.tagId != null) return { tag: String(scope.tagId) };
  if (scope.network != null) return networkParams(scope.network, scope);
  if (scope.group != null) return { group: scope.group };
  return scope.neverFetched ? { neverFetched: '1' } : {};
}

function networkParams(network: string, scope: RefreshScope): Record<string, string> {
  return scope.feedKind != null ? { network, feedKind: scope.feedKind } : { network };
}
