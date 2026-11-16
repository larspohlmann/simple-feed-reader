import { ParamMap, UrlMatchResult, UrlSegment, convertToParamMap } from '@angular/router';
import { ParsedRoute, ViewContext, selectionFromParams } from './query';
import { entryIdFromParam } from './slug';

/**
 * Owns the root URL, the saved-search paths and the followed-story paths through one route config, so the
 * shell is never torn down moving between a list and a saved search or a followed story.
 */
export function readerMatcher(segments: UrlSegment[]): UrlMatchResult | null {
  if (segments.length === 0) {
    return { consumed: [] };
  }
  if (segments.length !== 3) return null;
  if (segments[0].path === 'searches' && segments[1].path === 'saved') {
    return { consumed: segments, posParams: { savedSearch: segments[2] } };
  }
  if (segments[0].path === 'trending' && segments[1].path === 'followed') {
    return { consumed: segments, posParams: { followedStory: segments[2] } };
  }
  return null;
}

export function selectionFromRoute(
  pathParams: ParamMap,
  queryParams: ParamMap,
  context: ViewContext,
): ParsedRoute {
  const followedStory = pathParams.get('followedStory');
  if (followedStory !== null) return followedStoryFromRoute(followedStory, queryParams, context);
  const savedSearch = pathParams.get('savedSearch');
  if (savedSearch === null) {
    return selectionFromParams(queryParams, context);
  }

  // The entry overlay composes on top of a saved-search path, so a merged
  // `?entry=` must still open the article rather than being dropped here.
  const entryId = entryIdFromParam(queryParams.get('entry'));
  const id = entryIdFromParam(savedSearch);
  // A slug with no leading id (hand-edited URL) has no single search to open,
  // so fall back to the combined view rather than fetching the plain list.
  if (savedSearch === 'all' || id === null) {
    return { selection: { kind: 'saved-searches', unread: false }, entryId, urlCorrection: null };
  }
  return { selection: { kind: 'saved-search', id, unread: false }, entryId, urlCorrection: null };
}

/** A path naming no story opens Trending itself; the path stays, as a saved-search path's does. */
function followedStoryFromRoute(
  followedStory: string,
  queryParams: ParamMap,
  context: ViewContext,
): ParsedRoute {
  const entryId = entryIdFromParam(queryParams.get('entry'));
  const id = entryIdFromParam(followedStory);
  if (id === null) {
    const trending = selectionFromParams(convertToParamMap({ view: 'trending' }), context);
    return { selection: trending.selection, entryId, urlCorrection: null };
  }
  return { selection: { kind: 'followed-story', id }, entryId, urlCorrection: null };
}
