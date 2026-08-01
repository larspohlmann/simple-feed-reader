import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { ActionSheet } from './action-sheet.service';
import { ActionSheetComponent, ActionSheetData } from './action-sheet.component';

const DATA: ActionSheetData = {
  title: 'News',
  actions: [
    { id: 'edit', label: 'Edit tag' },
    { id: 'delete', label: 'Delete tag', danger: true },
  ],
};

describe('ActionSheet', () => {
  let sheet: ActionSheet;
  let container: HTMLElement;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    sheet = TestBed.inject(ActionSheet);
    container = TestBed.inject(OverlayContainer).getContainerElement();
  });

  const open = () => {
    const closed = sheet.open(DATA);
    TestBed.inject(ApplicationRef).tick();
    return closed;
  };

  const items = () => container.querySelectorAll<HTMLElement>('[role=menuitem]');

  it('renders the title and one menu item per action, flagging danger', () => {
    open().subscribe();
    expect(container.querySelector('[role=menu]')!.getAttribute('aria-label')).toBe('News');
    expect(items()).toHaveLength(2);
    expect(items()[0].textContent).toContain('Edit tag');
    expect(items()[1].textContent).toContain('Delete tag');
    expect(items()[1].classList).toContain('danger');
    expect(items()[0].classList).not.toContain('danger');
  });

  it('resolves with the chosen action id', (done) => {
    open().subscribe((choice) => {
      expect(choice).toBe('delete');
      done();
    });
    items()[1].click();
  });

  it('pins the pane to the sheet panel class', () => {
    open().subscribe();
    expect(container.querySelector('.cdk-overlay-pane.app-action-sheet')).not.toBeNull();
  });
});

describe('ActionSheetComponent swipe dismiss', () => {
  const mount = () => {
    const ref = { close: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: DIALOG_DATA, useValue: DATA },
        { provide: DialogRef, useValue: ref },
      ],
    });
    return { cmp: TestBed.createComponent(ActionSheetComponent).componentInstance, ref };
  };

  const touch = (clientY: number) => ({ touches: [{ clientY }] }) as unknown as TouchEvent;

  it('closes on a downward swipe past the threshold', () => {
    const { cmp, ref } = mount();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(180));
    cmp.onTouchEnd();
    expect(ref.close).toHaveBeenCalledWith();
  });

  it('ignores a swipe under the threshold and an upward swipe', () => {
    const { cmp, ref } = mount();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(140));
    cmp.onTouchEnd();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(20));
    cmp.onTouchEnd();
    expect(ref.close).not.toHaveBeenCalled();
  });
});
