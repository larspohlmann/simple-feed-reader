import { Provider, Signal, signal } from '@angular/core';
import { ListCover } from '../app/reader/shell/list-cover';
import { Selection } from '../app/reader/query/query';
import { EntriesStore } from '../app/reader/state/entries.store';
import { ListContext } from '../app/reader/state/list-context';

const ALL_ITEMS: Selection = { kind: 'all', unread: false };

/** A host's list over a stand-in store: only what a spec reaches on it needs to be there. */
export class TestListContext extends ListContext {
  constructor(
    readonly entries: EntriesStore,
    readonly selection: Signal<Selection> = signal(ALL_ITEMS),
  ) {
    super();
  }
}

/** The reader shell's own list, nothing laid over it, holding `entries` and listing `selection`. */
export function provideRouteListForTest(
  entries: object,
  selection: Signal<Selection> = signal(ALL_ITEMS),
): Provider[] {
  return [
    ListCover,
    { provide: ListContext, useValue: new TestListContext(entries as EntriesStore, selection) },
  ];
}
