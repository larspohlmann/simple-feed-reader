import { Provider } from '@angular/core';
import { LabelMenu } from '../app/reader/labels/label-menu.service';

/** A label menu for `app-entry-actions` that opens nothing. */
export function provideEntryActionsContext(): Provider[] {
  return [
    {
      provide: LabelMenu,
      useValue: { open: () => undefined, isOpenAt: () => false, closeWithin: () => undefined },
    },
  ];
}
