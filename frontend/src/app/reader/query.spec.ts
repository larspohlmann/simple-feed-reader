import { convertToParamMap } from '@angular/router';
import { markReadTarget, queryFromSelection, selectionFromParams } from './query';

const pm = (o: Record<string, string>) => convertToParamMap(o);

describe('selectionFromParams', () => {
  it('defaults to all-items, unread-only', () => {
    const { selection, entryId } = selectionFromParams(pm({}));
    expect(selection).toEqual({ kind: 'all', id: null, unread: true });
    expect(entryId).toBeNull();
  });
  it('reads unread=0 as show-all', () => {
    expect(selectionFromParams(pm({ unread: '0' })).selection.unread).toBe(false);
  });
  it('reads a subscription selection and open entry', () => {
    const { selection, entryId } = selectionFromParams(pm({ subscription: '7', entry: '42' }));
    expect(selection).toEqual({ kind: 'subscription', id: 7, unread: true });
    expect(entryId).toBe(42);
  });
  it('reads a tag selection', () => {
    expect(selectionFromParams(pm({ tag: '3' })).selection).toEqual({
      kind: 'tag',
      id: 3,
      unread: true,
    });
  });
  it('reads favorites/kept and ignores the unread toggle there', () => {
    expect(selectionFromParams(pm({ view: 'favorites', unread: '0' })).selection).toEqual({
      kind: 'favorites',
      id: null,
      unread: false,
    });
    expect(selectionFromParams(pm({ view: 'kept' })).selection.kind).toBe('kept');
  });
  it('rejects non-positive/garbage ids', () => {
    expect(selectionFromParams(pm({ subscription: '0' })).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ tag: 'x' })).selection.kind).toBe('all');
  });
});

describe('queryFromSelection', () => {
  it('maps all/tag/subscription through the unread toggle', () => {
    expect(queryFromSelection({ kind: 'all', id: null, unread: true })).toEqual({ view: 'unread' });
    expect(queryFromSelection({ kind: 'all', id: null, unread: false })).toEqual({ view: 'all' });
    expect(queryFromSelection({ kind: 'tag', id: 3, unread: true })).toEqual({
      view: 'unread',
      tag: 3,
    });
    expect(queryFromSelection({ kind: 'subscription', id: 7, unread: false })).toEqual({
      view: 'all',
      subscription: 7,
    });
  });
  it('maps curated views directly', () => {
    expect(queryFromSelection({ kind: 'favorites', id: null, unread: false })).toEqual({
      view: 'favorites',
    });
    expect(queryFromSelection({ kind: 'kept', id: null, unread: false })).toEqual({ view: 'kept' });
  });
});

describe('markReadTarget', () => {
  it('maps selection to a mark-read scope (feed=subscription id)', () => {
    expect(markReadTarget({ kind: 'all', id: null, unread: true })).toEqual({ scope: 'all' });
    expect(markReadTarget({ kind: 'tag', id: 3, unread: true })).toEqual({ scope: 'tag', id: 3 });
    expect(markReadTarget({ kind: 'subscription', id: 7, unread: true })).toEqual({
      scope: 'feed',
      id: 7,
    });
    expect(markReadTarget({ kind: 'favorites', id: null, unread: false })).toBeNull();
  });
});
