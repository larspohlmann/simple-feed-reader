import { Directive, inject, input } from '@angular/core';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';

/** Makes a card a button that opens its entry, marked read once the entry is. */
@Directive({
  selector: '[appOpensEntry]',
  host: {
    role: 'button',
    tabindex: '0',
    '[class.read]': 'appOpensEntry().isHidden',
    '(click)': 'open()',
    '(keydown.enter)': 'open()',
    '(keydown.space)': '$event.preventDefault(); open()',
  },
})
export class OpensEntryDirective {
  readonly appOpensEntry = input.required<EntryDto>();
  private readonly actions = inject(EntryActionHandler);

  protected open(): void {
    this.actions.open(this.appOpensEntry());
  }
}
