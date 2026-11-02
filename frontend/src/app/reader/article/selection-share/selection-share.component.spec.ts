import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { LayoutService } from '../../layout.service';
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from './article-selection.service';
import { SelectedQuote } from './selected-quote';
import { SelectionShareComponent } from './selection-share.component';

const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };

describe('SelectionShareComponent', () => {
  const postActions = { postLink: jest.fn() };
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
    range: signal<Range | null>(null),
    releasePageSelection: jest.fn(),
  };
  const bluesky: SocialAccountRefDto = {
    id: 1,
    network: 'bluesky',
    handle: 'me.bsky.social',
    status: 'connected',
  };
  const mastodon: SocialAccountRefDto = {
    id: 2,
    network: 'mastodon',
    handle: '@me@mastodon.social',
    status: 'connected',
  };
  const accounts = signal<SocialAccountRefDto[]>([]);
  const coarse = signal(false);
  let lastLineTop: number;
  let fixture: ComponentFixture<SelectionShareComponent>;

  const element = () => fixture.nativeElement as HTMLElement;

  const lastLine = (): DOMRect =>
    ({
      left: 100,
      top: lastLineTop,
      bottom: lastLineTop + 20,
      right: 300,
      width: 200,
      height: 20,
    }) as DOMRect;
  const range = { getClientRects: () => ({ length: 1, item: lastLine }) } as unknown as Range;

  function mount(onCoarsePointer: boolean): void {
    coarse.set(onCoarsePointer);
    fixture = TestBed.createComponent(SelectionShareComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  }

  function select(top = 180): void {
    lastLineTop = top;
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    selection.range.set(range);
    fixture.detectChanges();
  }

  beforeEach(() => {
    postActions.postLink.mockReset();
    selection.releasePageSelection.mockReset();
    selection.quote.set(null);
    selection.remembered.set(null);
    selection.range.set(null);
    accounts.set([bluesky, mastodon]);
    TestBed.configureTestingModule({
      imports: [SelectionShareComponent, provideTranslocoTesting()],
      providers: [
        { provide: ArticleSelection, useValue: selection },
        { provide: PostActions, useValue: postActions },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: LayoutService, useValue: { isCoarse: coarse } },
        { provide: ActionSheet, useValue: { open: jest.fn() } },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
  });

  afterEach(() => fixture.destroy());

  describe('on a fine pointer', () => {
    beforeEach(() => mount(false));

    const bubble = () => document.querySelector<HTMLElement>('.selection-bubble');

    it('shows nothing without a quote', () => {
      expect(bubble()).toBeNull();
    });

    it('offers a quote per connected network and the share menu', () => {
      accounts.set([bluesky]);
      select();

      expect(bubble()!.querySelector('[aria-label="Quote on Bluesky"]')).not.toBeNull();
      expect(bubble()!.querySelector('[aria-label="Quote on Mastodon"]')).toBeNull();
      expect(bubble()!.querySelector('[aria-label="Share"]')).not.toBeNull();
    });

    it('opens the composer on the quote with a link that points at it', () => {
      select();

      bubble()!.querySelector<HTMLButtonElement>('[aria-label="Quote on Bluesky"]')!.click();

      expect(postActions.postLink).toHaveBeenCalledWith('bluesky', {
        url: 'https://example.com/a#:~:text=One%20two%20three',
        title: 'First',
        quote: 'One two three',
      });
    });

    it('keeps the selection when pressed', () => {
      select();
      const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });

      bubble()!.querySelector('button')!.dispatchEvent(press);

      expect(press.defaultPrevented).toBe(true);
    });

    it('closes when the selection scrolls out of view', () => {
      select(-100);

      expect(bubble()).toBeNull();
    });

    it('closes when a scroll takes the selection out of view', async () => {
      select();
      lastLineTop = -100;

      document.dispatchEvent(new Event('scroll'));
      await new Promise(requestAnimationFrame);
      fixture.detectChanges();

      expect(bubble()).toBeNull();
    });

    it('stays away from an article without an address', () => {
      fixture.componentRef.setInput('url', null);

      select();

      expect(bubble()).toBeNull();
    });

    it('closes when the selection ends', () => {
      select();
      selection.quote.set(null);
      selection.range.set(null);
      fixture.detectChanges();

      expect(bubble()).toBeNull();
    });

    it('docks no bar, the bubble owns the selection', () => {
      select();

      expect(element().querySelector('.selection-bar')).toBeNull();
    });
  });

  describe('on a fine pointer, quoting from the bubble', () => {
    beforeEach(() => mount(false));

    it('wears no touch class and keeps the small share glyph', () => {
      select();

      const bubble = document.querySelector('.selection-bubble')!;
      const glyph = bubble.querySelector<HTMLElement>('[aria-label="Share"] app-icon')!;
      expect(bubble.classList).not.toContain('touch');
      expect(glyph.style.fontSize).toBe('var(--icon-sm)');
    });

    it('keeps the page selection highlighted', () => {
      select();

      document.querySelector<HTMLButtonElement>('[aria-label="Quote on Mastodon"]')!.click();

      expect(selection.releasePageSelection).not.toHaveBeenCalled();
    });
  });

  describe('on a coarse pointer', () => {
    beforeEach(() => mount(true));

    it('stays away without a selection', () => {
      expect(element().querySelector('.selection-bar')).toBeNull();
    });

    it('docks with the word count, a quote per network and the share button', () => {
      select();

      const bar = element().querySelector('.selection-bar')!;
      expect(bar.querySelector('.count')?.textContent?.trim()).toBe('3 words selected');
      expect(bar.querySelectorAll('[aria-label^="Quote on"]')).toHaveLength(2);
      expect(bar.querySelector('[aria-label="Share"]')).not.toBeNull();
    });

    it('wears the touch class and a share glyph a size up, for tap-sized buttons', () => {
      select();

      const bar = element().querySelector('.selection-bar')!;
      const glyph = bar.querySelector<HTMLElement>('[aria-label="Share"] app-icon')!;
      expect(bar.classList).toContain('touch');
      expect(glyph.style.fontSize).toBe('var(--icon-md)');
    });

    it('raises no bubble, the phone callout owns that spot', () => {
      select();

      expect(document.querySelector('.selection-bubble')).toBeNull();
    });

    it('stays away for an entry without a link', () => {
      fixture.componentRef.setInput('url', null);
      select();

      expect(element().querySelector('.selection-bar')).toBeNull();
    });

    it('stays while a press on it lasts, though the press has collapsed the selection', async () => {
      select();
      const bar = element().querySelector('.selection-bar')!;
      bar.dispatchEvent(new Event('pointerdown', { bubbles: true }));
      selection.quote.set(null);
      fixture.detectChanges();

      expect(element().querySelector('.selection-bar')).not.toBeNull();

      bar.dispatchEvent(new Event('pointerup', { bubbles: true }));
      fixture.detectChanges();
      expect(element().querySelector('.selection-bar')).not.toBeNull();

      await new Promise((resolve) => setTimeout(resolve));
      fixture.detectChanges();
      expect(element().querySelector('.selection-bar')).toBeNull();
    });

    it('leaves once a cancelled press ends', async () => {
      select();
      const bar = element().querySelector('.selection-bar')!;
      bar.dispatchEvent(new Event('pointerdown', { bubbles: true }));
      selection.quote.set(null);
      bar.dispatchEvent(new Event('pointercancel', { bubbles: true }));

      await new Promise((resolve) => setTimeout(resolve));
      fixture.detectChanges();
      expect(element().querySelector('.selection-bar')).toBeNull();
    });

    it('quotes the remembered selection when the tap has collapsed it', () => {
      select();
      const mastodon = element().querySelector<HTMLButtonElement>(
        '[aria-label="Quote on Mastodon"]',
      )!;
      selection.quote.set(null);

      mastodon.click();

      expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
        url: 'https://example.com/a#:~:text=One%20two%20three',
        title: 'First',
        quote: 'One two three',
      });
    });

    it('collapses the page selection when a network tap opens the composer, which still gets the quote', () => {
      select();
      selection.releasePageSelection.mockImplementation(() => selection.remembered.set(null));

      element().querySelector<HTMLButtonElement>('[aria-label="Quote on Mastodon"]')!.click();

      expect(selection.releasePageSelection).toHaveBeenCalledTimes(1);
      expect(postActions.postLink).toHaveBeenCalledWith('mastodon', {
        url: 'https://example.com/a#:~:text=One%20two%20three',
        title: 'First',
        quote: 'One two three',
      });
    });
  });
});
