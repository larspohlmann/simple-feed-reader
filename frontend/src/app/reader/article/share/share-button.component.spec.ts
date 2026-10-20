import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetAction,
  ActionSheetData,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ToastService } from '../../../shared/toast/toast.service';
import { ShareButtonComponent } from './share-button.component';

describe('ShareButtonComponent', () => {
  const toast = { show: jest.fn() };
  const sheet = { open: jest.fn() };
  let chosen: Subject<string | undefined>;
  let fixture: ComponentFixture<ShareButtonComponent>;

  function mount(coarse: boolean): void {
    chosen = new Subject();
    sheet.open.mockReturnValue(chosen.asObservable());
    TestBed.configureTestingModule({
      imports: [ShareButtonComponent, provideTranslocoTesting()],
      providers: [
        { provide: ToastService, useValue: toast },
        { provide: ActionSheet, useValue: sheet },
        { provide: LayoutService, useValue: { isCoarse: signal(coarse) } },
      ],
    });
    fixture = TestBed.createComponent(ShareButtonComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss&id=1');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  }

  beforeEach(() => {
    toast.show.mockReset();
    sheet.open.mockReset();
  });

  afterEach(() => {
    fixture.destroy();
    jest.restoreAllMocks();
  });

  function press(): void {
    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('button')!.click();
    fixture.detectChanges();
  }

  function openMenu(): HTMLElement {
    press();
    return document.querySelector<HTMLElement>('[role="menu"]')!;
  }

  function item(menu: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(menu.querySelectorAll<HTMLButtonElement>('[role="menuitem"]')).find(
      (button) => button.textContent?.trim().endsWith(label),
    )!;
  }

  const BLUESKY_COMPOSE =
    'https://bsky.app/intent/compose?text=First%20https%3A%2F%2Fexample.com%2Fa%3Fid%3D1';

  describe('on a fine pointer', () => {
    beforeEach(() => mount(false));

    it('lists the configured targets with their dividers and logos', () => {
      const menu = openMenu();

      expect(item(menu, 'Copy link')).toBeTruthy();
      expect(item(menu, 'Bluesky').querySelector('app-masked-logo')).not.toBeNull();
      expect(menu.querySelectorAll('hr').length).toBeGreaterThan(0);
      expect(sheet.open).not.toHaveBeenCalled();
    });

    it('opens a target with the article, tracking parameters removed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);

      item(openMenu(), 'Bluesky').click();
      fixture.detectChanges();

      expect(open).toHaveBeenCalledWith(BLUESKY_COMPOSE, '_blank', 'noopener,noreferrer');
      expect(document.querySelector('[role="menu"]')).toBeNull();
    });

    it('copies the link and confirms it', async () => {
      const writeText = jest.fn().mockResolvedValue(undefined);
      Object.assign(navigator, { clipboard: { writeText } });

      item(openMenu(), 'Copy link').click();
      await Promise.resolve();

      expect(writeText).toHaveBeenCalledWith('https://example.com/a?id=1');
      expect(toast.show).toHaveBeenCalledWith(expect.objectContaining({ message: 'Link copied' }));
    });
  });

  describe('on a coarse pointer', () => {
    beforeEach(() => mount(true));

    function sheetData(): ActionSheetData {
      return sheet.open.mock.calls[0][0] as ActionSheetData;
    }

    function action(label: string): ActionSheetAction {
      return sheetData().actions.find(
        (entry: ActionSheetItem): entry is ActionSheetAction =>
          'label' in entry && entry.label === label,
      )!;
    }

    it('opens the action sheet with the same entries instead of the popover', () => {
      press();

      expect(document.querySelector('[role="menu"]')).toBeNull();
      expect(sheetData().title).toBe('Share');
      expect(action('Copy link')).toEqual({
        id: expect.any(String),
        label: 'Copy link',
        symbol: 'link',
      });
      expect(action('Bluesky').logo).toEqual({
        url: 'share-targets/bluesky.svg',
        color: '#1185fe',
      });
      expect(sheetData().actions).toContain(ACTION_SHEET_DIVIDER);
    });

    it('fires the chosen target', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(action('Bluesky').id);

      expect(open).toHaveBeenCalledWith(BLUESKY_COMPOSE, '_blank', 'noopener,noreferrer');
    });

    it('does nothing when the sheet is dismissed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(undefined);

      expect(open).not.toHaveBeenCalled();
    });
  });
});
