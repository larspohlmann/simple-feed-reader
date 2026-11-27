import { Provider, Signal } from '@angular/core';
import { LabelMenu } from '../app/reader/labels/label-menu.service';
import { Selection } from '../app/reader/query/query';
import { provideRouteListForTest } from './list-context-testing';

/** What `app-entry-actions` reads beside its handler: the list on screen, and a label menu that opens nothing. */
export function provideEntryActionsContext(selection?: Signal<Selection>): Provider[] {
  return [
    ...provideRouteListForTest({}, selection),
    {
      provide: LabelMenu,
      useValue: { open: () => undefined, isOpenAt: () => false, closeWithin: () => undefined },
    },
  ];
}
