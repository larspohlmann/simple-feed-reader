// src/app/reader/query.ts
import { ParamMap } from '@angular/router';
import { EntryQuery, MarkReadScope } from './models';
import { entryIdFromParam } from './slug';

export interface Selection {
  kind: 'all' | 'tag' | 'subscription' | 'favorites' | 'kept';
  id: number | null;
  unread: boolean;
}

export function selectionFromParams(p: ParamMap): {
  selection: Selection;
  entryId: number | null;
} {
  const view = p.get('view');
  const tag = posInt(p.get('tag'));
  const subscription = posInt(p.get('subscription'));
  const unread = p.get('unread') !== '0';
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(p.get('entry'));

  let selection: Selection;
  if (view === 'favorites' || view === 'kept') {
    selection = { kind: view, id: null, unread: false };
  } else if (subscription != null) {
    selection = { kind: 'subscription', id: subscription, unread };
  } else if (tag != null) {
    selection = { kind: 'tag', id: tag, unread };
  } else {
    selection = { kind: 'all', id: null, unread };
  }
  return { selection, entryId };
}

export function queryFromSelection(s: Selection): EntryQuery {
  switch (s.kind) {
    case 'favorites':
      return { view: 'favorites' };
    case 'kept':
      return { view: 'kept' };
    case 'tag':
      return { view: s.unread ? 'unread' : 'all', tag: s.id ?? undefined };
    case 'subscription':
      return { view: s.unread ? 'unread' : 'all', subscription: s.id ?? undefined };
    case 'all':
      return { view: s.unread ? 'unread' : 'all' };
  }
}

export function markReadTarget(s: Selection): { scope: MarkReadScope; id?: number } | null {
  switch (s.kind) {
    case 'all':
      return { scope: 'all' };
    case 'tag':
      return s.id != null ? { scope: 'tag', id: s.id } : null;
    case 'subscription':
      return s.id != null ? { scope: 'feed', id: s.id } : null;
    default:
      return null;
  }
}

function posInt(v: string | null): number | null {
  if (v == null) return null;
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}
