import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ActionSheet } from './action-sheet.service';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetComponent,
  ActionSheetData,
} from './action-sheet.component';

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
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    sheet = TestBed.inject(ActionSheet);
    container = TestBed.inject(OverlayContainer).getContainerElement();
  });

  const open = (data: ActionSheetData = DATA) => {
    const closed = sheet.open(data);
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

  it('renders a disabled action as a disabled item', () => {
    open({
      title: 'News',
      actions: [{ id: 'manage', label: 'Reconnect @a in settings', disabled: true }],
    }).subscribe();
    expect((items()[0] as HTMLButtonElement).disabled).toBe(true);
  });

  it('resolves with the chosen action id', (done) => {
    open().subscribe((choice) => {
      expect(choice).toBe('delete');
      done();
    });
    items()[1].click();
  });

  it('puts the actions before the close button, so the first action takes the initial focus', () => {
    open().subscribe();
    const close = container.querySelector('button.close')!;
    expect(
      items()[0].compareDocumentPosition(close) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('closes from the corner button, resolving as a dismissal', (done) => {
    open().subscribe((choice) => {
      expect(choice).toBeUndefined();
      done();
    });
    const close = container.querySelector<HTMLButtonElement>('button.close')!;
    expect(close.getAttribute('aria-label')).toBe('Close');
    close.click();
  });

  it('pins the pane to the sheet panel class', () => {
    open().subscribe();
    expect(container.querySelector('.cdk-overlay-pane.app-action-sheet')).not.toBeNull();
  });

  it('keeps the title outside the menu — role=menu children are items and separators only', () => {
    open().subscribe();
    expect(container.querySelector('[role=menu] .title')).toBeNull();
    expect(container.querySelector('.title')!.textContent).toContain('News');
    const menuChildren = Array.from(container.querySelector('[role=menu]')!.children);
    expect(menuChildren.length).toBeGreaterThan(0);
    for (const child of menuChildren) {
      expect(['menuitem', 'separator']).toContain(child.getAttribute('role'));
    }
  });

  it('draws a symbol or a logo before the label and a divider between groups', () => {
    open({
      title: 'Share',
      actions: [
        { id: 'copy', label: 'Copy link', symbol: 'link' },
        ACTION_SHEET_DIVIDER,
        {
          id: 'bluesky',
          label: 'Bluesky',
          logo: { url: 'share-targets/bluesky.svg', color: 'rgb(17, 133, 254)' },
        },
      ],
    }).subscribe();

    const menu = container.querySelector('[role=menu]')!;
    expect(items()[0].querySelector('app-icon')).not.toBeNull();
    expect(menu.querySelector('[role=separator]')).not.toBeNull();
    const logo = items()[1].querySelector<HTMLElement>('app-masked-logo')!;
    expect(logo.style.maskImage).toBe('url(share-targets/bluesky.svg)');
    expect(logo.style.backgroundColor).toBe('rgb(17, 133, 254)');
  });

  it('walks the items with the arrow keys, wrapping at both ends', () => {
    open().subscribe();
    const key = (name: string) =>
      container
        .querySelector('app-action-sheet')!
        .dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));

    key('ArrowDown');
    expect(document.activeElement).toBe(items()[0]);
    key('ArrowDown');
    expect(document.activeElement).toBe(items()[1]);
    key('ArrowDown'); // wraps
    expect(document.activeElement).toBe(items()[0]);
    key('ArrowUp'); // wraps back
    expect(document.activeElement).toBe(items()[1]);
  });
});

describe('ActionSheetComponent swipe dismiss', () => {
  const mount = () => {
    const ref = { close: jest.fn() };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DIALOG_DATA, useValue: DATA },
        { provide: DialogRef, useValue: ref },
      ],
    });
    const fixture = TestBed.createComponent(ActionSheetComponent);
    fixture.detectChanges();
    const menu = fixture.nativeElement.querySelector('nav') as HTMLElement;
    return { cmp: fixture.componentInstance, ref, menu };
  };

  const touch = (clientY: number) => ({ touches: [{ clientY }] }) as unknown as TouchEvent;

  it('closes on a downward swipe past the threshold', () => {
    const { cmp, ref } = mount();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(180));
    cmp.onTouchEnd();
    expect(ref.close).toHaveBeenCalledWith();
  });

  it('keeps a list that was scrolled down open while it is scrolled back up', () => {
    const { cmp, ref, menu } = mount();
    Object.defineProperty(menu, 'scrollTop', { value: 40 });
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(180));
    cmp.onTouchEnd();
    expect(ref.close).not.toHaveBeenCalled();
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
