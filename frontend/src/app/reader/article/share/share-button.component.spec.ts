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
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { ResponseActions } from '../composer/response-actions.service';
import { ShareButtonComponent } from './share-button.component';

const IMAGE_URL = 'https://example.com/a.jpg';

function account(
  id: number,
  network: SocialAccountRefDto['network'],
  handle: string,
): SocialAccountRefDto {
  return { id, network, handle, status: 'connected' };
}

describe('ShareButtonComponent', () => {
  const toast = { show: jest.fn() };
  const sheet = { open: jest.fn() };
  const responses = { respond: jest.fn() };
  const accounts = signal<SocialAccountRefDto[]>([]);
  let chosen: Subject<string | undefined>;
  let fixture: ComponentFixture<ShareButtonComponent>;

  function mount(coarse: boolean, title = 'First'): void {
    chosen = new Subject();
    sheet.open.mockReturnValue(chosen.asObservable());
    TestBed.configureTestingModule({
      imports: [ShareButtonComponent, provideTranslocoTesting()],
      providers: [
        { provide: ToastService, useValue: toast },
        { provide: ActionSheet, useValue: sheet },
        { provide: LayoutService, useValue: { isCoarse: signal(coarse) } },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: ResponseActions, useValue: responses },
      ],
    });
    fixture = TestBed.createComponent(ShareButtonComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss&id=1');
    fixture.componentRef.setInput('title', title);
    fixture.componentRef.setInput('imageUrl', IMAGE_URL);
    fixture.detectChanges();
  }

  beforeEach(() => {
    toast.show.mockReset();
    sheet.open.mockReset();
    responses.respond.mockReset();
    accounts.set([
      account(1, 'mastodon', '@me@mastodon.social'),
      account(2, 'bluesky', 'me.bsky.social'),
    ]);
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

  function items(menu: HTMLElement): HTMLButtonElement[] {
    return Array.from(menu.querySelectorAll<HTMLButtonElement>('[role="menuitem"]'));
  }

  function labelOf(button: HTMLButtonElement): string {
    return button.querySelector('.label')?.textContent?.trim() ?? '';
  }

  function item(menu: HTMLElement, label: string): HTMLButtonElement {
    return items(menu).find((button) => labelOf(button) === label)!;
  }

  const BLUESKY_COMPOSE =
    'https://bsky.app/intent/compose?text=First%20https%3A%2F%2Fexample.com%2Fa%3Fid%3D1';

  describe('on a fine pointer', () => {
    beforeEach(() => mount(false));

    it('lists the configured targets with their dividers and logos', () => {
      const menu = openMenu();

      expect(item(menu, 'Copy link')).toBeTruthy();
      expect(item(menu, 'Open in Bluesky app').querySelector('app-masked-logo')).not.toBeNull();
      expect(menu.querySelectorAll('hr').length).toBeGreaterThan(0);
      expect(sheet.open).not.toHaveBeenCalled();
    });

    it('opens a target with the article, tracking parameters removed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);

      item(openMenu(), 'Open in Bluesky app').click();
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

    it('marks the Bluesky app item as leaving the reader', () => {
      const glyph = item(openMenu(), 'Open in Bluesky app').querySelector('app-icon.external');

      expect(glyph?.textContent?.trim()).toBe('open_in_new');
      expect(
        item(document.querySelector('[role="menu"]')!, 'Copy link').querySelector('.external'),
      ).toBeNull();
    });
  });

  describe('on a fine pointer, with connected accounts', () => {
    beforeEach(() => mount(false));

    it('offers a post per connected network at the top, Bluesky first, then a divider', () => {
      const menu = openMenu();
      const [bluesky, mastodon] = items(menu);

      expect(labelOf(bluesky)).toBe('Post on Bluesky');
      expect(bluesky.querySelector('app-network-logo')).not.toBeNull();
      expect(labelOf(mastodon)).toBe('Post on Mastodon');
      expect(mastodon.nextElementSibling?.tagName).toBe('HR');
    });

    it('names the posting account in the tooltip', () => {
      expect(item(openMenu(), 'Post on Bluesky').title).toBe('as me.bsky.social');
    });

    it('points to the composer when a network has several accounts', () => {
      accounts.update((list) => [...list, account(3, 'bluesky', 'other.bsky.social')]);
      fixture.detectChanges();

      expect(item(openMenu(), 'Post on Bluesky').title).toBe('Choose the account in the composer');
    });

    it('ignores accounts that need reconnecting', () => {
      accounts.set([
        { ...account(1, 'mastodon', '@me@mastodon.social'), status: 'needs_reconnect' },
      ]);
      fixture.detectChanges();

      expect(labelOf(items(openMenu())[0])).not.toBe('Post on Mastodon');
    });

    it('offers nothing to post without an account', () => {
      accounts.set([]);
      fixture.detectChanges();

      const menu = openMenu();

      expect(item(menu, 'Post on Bluesky')).toBeUndefined();
      expect(item(menu, 'Post on Mastodon')).toBeUndefined();
    });

    it('opens the composer with the shared URL, tracking parameters removed', () => {
      item(openMenu(), 'Post on Mastodon').click();
      fixture.detectChanges();

      expect(responses.respond).toHaveBeenCalledWith({
        kind: 'link',
        network: 'mastodon',
        url: 'https://example.com/a?id=1',
        title: 'First',
        site: 'example.com',
        imageUrl: IMAGE_URL,
        prefill: 'First\n\nhttps://example.com/a?id=1',
      });
      expect(document.querySelector('[role="menu"]')).toBeNull();
    });
  });

  describe('on a fine pointer, for a page without a title', () => {
    beforeEach(() => mount(false, ''));

    it('titles the composer card with the host and starts the text with the URL', () => {
      item(openMenu(), 'Post on Mastodon').click();

      expect(responses.respond).toHaveBeenCalledWith(
        expect.objectContaining({
          title: 'example.com',
          site: 'example.com',
          prefill: 'https://example.com/a?id=1',
        }),
      );
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
      expect(action('Open in Bluesky app').logo).toEqual({
        url: 'share-targets/bluesky.svg',
        color: '#1185fe',
      });
      expect(sheetData().actions).toContain(ACTION_SHEET_DIVIDER);
    });

    it('fires the chosen target', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(action('Open in Bluesky app').id);

      expect(open).toHaveBeenCalledWith(BLUESKY_COMPOSE, '_blank', 'noopener,noreferrer');
    });

    it('does nothing when the sheet is dismissed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(undefined);

      expect(open).not.toHaveBeenCalled();
    });
  });

  describe('on a coarse pointer, with connected accounts', () => {
    beforeEach(() => mount(true));

    function sheetData(): ActionSheetData {
      return sheet.open.mock.calls[0][0] as ActionSheetData;
    }

    it('puts a post per network with its logo and account at the top of the sheet', () => {
      press();

      const [bluesky, mastodon, divider] = sheetData().actions;
      expect(bluesky).toEqual({
        id: 'post-bluesky',
        label: 'Post on Bluesky',
        hint: 'as me.bsky.social',
        logo: { url: 'share-targets/bluesky.svg', color: 'var(--brand-bluesky)' },
      });
      expect(mastodon).toEqual(
        expect.objectContaining({
          label: 'Post on Mastodon',
          logo: { url: 'share-targets/mastodon.svg', color: 'var(--brand-mastodon)' },
        }),
      );
      expect(divider).toBe(ACTION_SHEET_DIVIDER);
    });

    it('opens the composer for the chosen network', () => {
      press();

      chosen.next('post-bluesky');

      expect(responses.respond).toHaveBeenCalledWith(
        expect.objectContaining({ kind: 'link', network: 'bluesky', prefill: 'First' }),
      );
    });
  });
});
