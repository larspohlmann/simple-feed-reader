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
import { SelectionBubbleComponent } from './selection-bubble.component';

const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };

function rangeAt(top: number): Range {
  const rect = { left: 100, top, bottom: top + 20, right: 300, width: 200, height: 20 } as DOMRect;
  return { getClientRects: () => ({ length: 1, item: () => rect }) } as unknown as Range;
}

describe('SelectionBubbleComponent', () => {
  const postActions = { postLink: jest.fn() };
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
    range: signal<Range | null>(null),
  };
  const accounts = signal<SocialAccountRefDto[]>([
    { id: 1, network: 'bluesky', handle: 'me.bsky.social', status: 'connected' },
  ]);
  const coarse = signal(false);
  let fixture: ComponentFixture<SelectionBubbleComponent>;

  beforeEach(() => {
    postActions.postLink.mockReset();
    selection.quote.set(null);
    selection.remembered.set(null);
    selection.range.set(null);
    coarse.set(false);
    TestBed.configureTestingModule({
      imports: [SelectionBubbleComponent, provideTranslocoTesting()],
      providers: [
        { provide: ArticleSelection, useValue: selection },
        { provide: PostActions, useValue: postActions },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: LayoutService, useValue: { isCoarse: coarse } },
        { provide: ActionSheet, useValue: { open: jest.fn() } },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    fixture = TestBed.createComponent(SelectionBubbleComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  });

  afterEach(() => fixture.destroy());

  const bubble = () => document.querySelector<HTMLElement>('.selection-bubble');

  function select(top = 180): void {
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    selection.range.set(rangeAt(top));
    fixture.detectChanges();
  }

  it('shows nothing without a quote', () => {
    expect(bubble()).toBeNull();
  });

  it('offers a quote per connected network and the share menu', () => {
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

  it('stays away on a coarse pointer', () => {
    coarse.set(true);

    select();

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
});
