import { Provider, signal } from '@angular/core';
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
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { SelectedQuote } from '../selection-share/selected-quote';
import { ShareButtonAppearance, ShareButtonComponent } from './share-button.component';

function account(
  id: number,
  network: SocialAccountRefDto['network'],
  handle: string,
): SocialAccountRefDto {
  return { id, network, handle, status: 'connected' };
}

let selectionProviders: Provider[] = [];

describe('ShareButtonComponent', () => {
  const toast = { show: jest.fn() };
  const sheet = { open: jest.fn() };
  const postActions = { postLink: jest.fn() };
  const accounts = signal<SocialAccountRefDto[]>([]);
  let chosen: Subject<string | undefined>;
  let fixture: ComponentFixture<ShareButtonComponent>;

  function mount(
    coarse: boolean,
    title = 'First',
    appearance: ShareButtonAppearance = 'toolbar',
  ): void {
    chosen = new Subject();
    sheet.open.mockReturnValue(chosen.asObservable());
    TestBed.configureTestingModule({
      imports: [ShareButtonComponent, provideTranslocoTesting()],
      providers: [
        { provide: ToastService, useValue: toast },
        { provide: ActionSheet, useValue: sheet },
        { provide: LayoutService, useValue: { isCoarse: signal(coarse) } },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: PostActions, useValue: postActions },
        ...selectionProviders,
      ],
    });
    fixture = TestBed.createComponent(ShareButtonComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss&id=1');
    fixture.componentRef.setInput('title', title);
    fixture.componentRef.setInput('appearance', appearance);
    fixture.detectChanges();
  }

  beforeEach(() => {
    toast.show.mockReset();
    sheet.open.mockReset();
    postActions.postLink.mockReset();
    selectionProviders = [];
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

      expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
        url: 'https://example.com/a?id=1',
        title: 'First',
        quote: null,
      });
      expect(document.querySelector('[role="menu"]')).toBeNull();
    });
  });

  describe('in the article corner', () => {
    beforeEach(() => mount(false, 'First', 'corner'));

    it('is a round icon-only trigger that opens the same menu', () => {
      const trigger = (fixture.nativeElement as HTMLElement).querySelector('button')!;
      expect(trigger.classList).toContain('corner-share');
      expect(trigger.getAttribute('aria-label')).toBe('Share');
      expect(trigger.querySelector('.txt')).toBeNull();

      const menu = openMenu();

      expect(labelOf(items(menu)[0])).toBe('Post on Bluesky');
      expect(item(menu, 'Copy link')).toBeTruthy();
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

      expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
        url: 'https://example.com/a?id=1',
        title: 'First',
        quote: null,
      });
    });

    it('still acts on the choice when the trigger is gone before the sheet closes', () => {
      press();
      fixture.destroy();

      chosen.next('post-bluesky');

      expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
        url: 'https://example.com/a?id=1',
        title: 'First',
        quote: null,
      });
    });
  });

  const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };
  const FRAGMENT = 'https://example.com/a?id=1#:~:text=One%20two%20three';
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
  };

  function inArticle(coarse: boolean, appearance: ShareButtonAppearance = 'toolbar'): void {
    selection.quote.set(null);
    selection.remembered.set(null);
    selectionProviders = [{ provide: ArticleSelection, useValue: selection }];
    mount(coarse, 'First', appearance);
  }

  describe('outside an article', () => {
    beforeEach(() => mount(false));

    it('hints at no quote', () => {
      expect(openMenu().querySelector('.quote-hint')).toBeNull();
    });
  });

  describe('in an article, on a fine pointer', () => {
    beforeEach(() => inArticle(false));

    it('hints under the network entries that a selection can be quoted', () => {
      const hint = openMenu().querySelector('.quote-hint');

      expect(hint?.textContent?.trim()).toBe('Select text in the article to share it as a quote');
      expect(hint?.previousElementSibling?.textContent).toContain('Post on Mastodon');
    });

    it('hints at nothing without a network to post to', () => {
      accounts.set([]);
      fixture.detectChanges();

      expect(openMenu().querySelector('.quote-hint')).toBeNull();
    });

    describe('with a selection', () => {
      beforeEach(() => selection.quote.set(QUOTE));

      it('heads the menu with the quote and quotes on the networks', () => {
        const menu = openMenu();

        expect(menu.querySelector('.snippet')?.textContent?.trim()).toBe('“One two three”');
        expect(item(menu, 'Quote on Bluesky')).toBeTruthy();
        expect(item(menu, 'Copy quote with link')).toBeTruthy();
        expect(menu.querySelector('.quote-hint')).toBeNull();
      });

      it('dims a target that takes only a link and says so', () => {
        const x = item(openMenu(), 'X');

        expect(x.classList).toContain('link-only');
        expect(x.textContent).toContain('(link only)');
      });

      it('opens the composer on the quote with a link that points at it', () => {
        item(openMenu(), 'Quote on Mastodon').click();

        expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
          url: FRAGMENT,
          title: 'First',
          quote: 'One two three',
        });
      });

      it('copies the quote above its link', async () => {
        const writeText = jest.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        item(openMenu(), 'Copy quote with link').click();
        await Promise.resolve();

        expect(writeText).toHaveBeenCalledWith(`“One two three”\n\n${FRAGMENT}`);
        expect(toast.show).toHaveBeenCalledWith(
          expect.objectContaining({ message: 'Quote copied' }),
        );
      });

      it('fills a text target with the quote and its link', () => {
        const open = jest.spyOn(window, 'open').mockReturnValue(null);

        item(openMenu(), 'Open in Bluesky app').click();

        expect(open).toHaveBeenCalledWith(
          `https://bsky.app/intent/compose?text=${encodeURIComponent(`“One two three” ${FRAGMENT}`)}`,
          '_blank',
          'noopener,noreferrer',
        );
      });

      it('shares the plain link through a link-only target', () => {
        const open = jest.spyOn(window, 'open').mockReturnValue(null);

        item(openMenu(), 'X').click();

        expect(open).toHaveBeenCalledWith(
          'https://x.com/intent/post?text=First&url=https%3A%2F%2Fexample.com%2Fa%3Fid%3D1',
          '_blank',
          'noopener,noreferrer',
        );
      });

      it('keeps the quote it opened with when the selection collapses', () => {
        const menu = openMenu();
        selection.quote.set(null);
        fixture.detectChanges();

        expect(menu.querySelector('.snippet')).not.toBeNull();
      });

      it('keeps the selection when the trigger is pressed', () => {
        const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        (fixture.nativeElement as HTMLElement).querySelector('button')!.dispatchEvent(press);

        expect(press.defaultPrevented).toBe(true);
      });
    });
  });

  describe('in an article, on a coarse pointer', () => {
    const sheetData = (): ActionSheetData => sheet.open.mock.calls[0][0] as ActionSheetData;

    describe.each<ShareButtonAppearance>(['toolbar', 'corner'])(
      'as the %s trigger',
      (appearance) => {
        beforeEach(() => inArticle(true, appearance));

        it('puts the hint on the sheet as a note under the network entries', () => {
          press();

          expect(sheetData().actions[2]).toEqual({
            note: 'Select text in the article to share it as a quote',
          });
        });

        it('shares the link once the selection is gone, whatever is remembered', () => {
          selection.remembered.set(QUOTE);
          press();

          expect(sheetData().title).toBe('Share');
        });

        it('quotes the live selection', () => {
          selection.quote.set(QUOTE);
          selection.remembered.set(QUOTE);
          press();

          expect(sheetData().title).toBe('“One two three”');
        });
      },
    );
  });

  describe('handed a quote by its host, on a coarse pointer', () => {
    beforeEach(() => inArticle(true, 'compact'));

    const sheetData = (): ActionSheetData => sheet.open.mock.calls[0][0] as ActionSheetData;

    function offer(quote: SelectedQuote | null): void {
      fixture.componentRef.setInput('offeredQuote', quote);
      fixture.detectChanges();
    }

    it('opens the sheet in quote mode from the offered quote', () => {
      offer(QUOTE);
      press();

      const actions = sheetData().actions as ActionSheetAction[];
      expect(sheetData().title).toBe('“One two three”');
      expect(actions[0].label).toBe('Quote on Bluesky');
      expect(actions.find((action) => action.label === 'X')?.hint).toBe('(link only)');
    });

    it('opens the composer on the offered quote', () => {
      offer(QUOTE);
      press();

      chosen.next('post-bluesky');

      expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
        url: FRAGMENT,
        title: 'First',
        quote: 'One two three',
      });
    });

    it('shares the link when its host offers no quote, whatever is selected', () => {
      selection.quote.set(QUOTE);
      offer(null);
      press();

      expect(sheetData().title).toBe('Share');
    });
  });

  describe('as a compact trigger', () => {
    beforeEach(() => mount(false, 'First', 'compact'));

    it('is an icon button that opens the same menu', () => {
      const trigger = (fixture.nativeElement as HTMLElement).querySelector('button')!;

      expect(trigger.classList).toContain('ib');
      expect(trigger.getAttribute('aria-label')).toBe('Share');
      expect(item(openMenu(), 'Copy link')).toBeTruthy();
    });
  });
});
