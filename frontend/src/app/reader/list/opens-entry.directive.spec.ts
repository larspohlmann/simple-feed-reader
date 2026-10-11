import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OpensEntryDirective } from './opens-entry.directive';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';
import { anEntry } from '../../../testing/entry-testing';

@Component({
  imports: [OpensEntryDirective],
  template: `<article [appOpensEntry]="entry()"></article>`,
})
class HostComponent {
  readonly entry = signal<EntryDto>(anEntry({ isHidden: false }));
}

const open = jest.fn();

function mount() {
  TestBed.configureTestingModule({
    imports: [HostComponent],
    providers: [{ provide: EntryActionHandler, useValue: { open } }],
  });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  const card = (fixture.nativeElement as HTMLElement).querySelector('article')!;
  return { fixture, card };
}

describe('OpensEntryDirective', () => {
  beforeEach(() => open.mockReset());
  afterEach(() => TestBed.resetTestingModule());

  it('makes the card a focusable button', () => {
    const { card } = mount();
    expect(card.getAttribute('role')).toBe('button');
    expect(card.getAttribute('tabindex')).toBe('0');
  });

  it('opens the entry on click, Enter and Space', () => {
    const { fixture, card } = mount();
    card.click();
    card.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    card.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }));
    expect(open).toHaveBeenCalledTimes(3);
    expect(open).toHaveBeenCalledWith(fixture.componentInstance.entry());
  });

  it('keeps Space from scrolling the page', () => {
    const { card } = mount();
    const space = new KeyboardEvent('keydown', { key: ' ', cancelable: true });
    card.dispatchEvent(space);
    expect(space.defaultPrevented).toBe(true);
  });

  it('marks the card read once its entry is', () => {
    const { fixture, card } = mount();
    expect(card.classList).not.toContain('read');
    fixture.componentInstance.entry.set(anEntry({ isHidden: true }));
    fixture.detectChanges();
    expect(card.classList).toContain('read');
  });
});
