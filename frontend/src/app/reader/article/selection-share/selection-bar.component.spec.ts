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
import { SelectionBarComponent } from './selection-bar.component';

const QUOTE: SelectedQuote = { text: 'One two three', words: 3, anchorable: true };

describe('SelectionBarComponent', () => {
  const postActions = { postLink: jest.fn() };
  const selection = {
    quote: signal<SelectedQuote | null>(null),
    remembered: signal<SelectedQuote | null>(null),
  };
  const accounts = signal<SocialAccountRefDto[]>([
    { id: 1, network: 'bluesky', handle: 'me.bsky.social', status: 'connected' },
    { id: 2, network: 'mastodon', handle: '@me@mastodon.social', status: 'connected' },
  ]);
  const coarse = signal(true);
  let fixture: ComponentFixture<SelectionBarComponent>;

  beforeEach(() => {
    postActions.postLink.mockReset();
    selection.quote.set(null);
    selection.remembered.set(null);
    coarse.set(true);
    TestBed.configureTestingModule({
      imports: [SelectionBarComponent, provideTranslocoTesting()],
      providers: [
        { provide: ArticleSelection, useValue: selection },
        { provide: PostActions, useValue: postActions },
        { provide: SubscriptionsStore, useValue: { socialAccounts: accounts } },
        { provide: LayoutService, useValue: { isCoarse: coarse } },
        { provide: ActionSheet, useValue: { open: jest.fn() } },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    fixture = TestBed.createComponent(SelectionBarComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  });

  afterEach(() => fixture.destroy());

  const element = () => fixture.nativeElement as HTMLElement;

  function select(): void {
    selection.quote.set(QUOTE);
    selection.remembered.set(QUOTE);
    fixture.detectChanges();
  }

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

  it('stays away on a fine pointer, where the bubble owns the selection', () => {
    coarse.set(false);
    select();

    expect(element().querySelector('.selection-bar')).toBeNull();
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
});
