import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { LayoutService } from '../../layout.service';
import { RepostButtonComponent } from './repost-button.component';

describe('RepostButtonComponent', () => {
  let fixture: ComponentFixture<RepostButtonComponent>;
  let isCoarse: WritableSignal<boolean>;
  let sheet: { open: jest.Mock };
  let toggled: jest.Mock;
  let quoteRequested: jest.Mock;

  beforeEach(() => {
    isCoarse = signal(false);
    sheet = { open: jest.fn(() => of('toggle')) };
    TestBed.configureTestingModule({
      imports: [RepostButtonComponent, provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse } },
        { provide: ActionSheet, useValue: sheet },
      ],
    });
    fixture = TestBed.createComponent(RepostButtonComponent);
    toggled = jest.fn();
    fixture.componentInstance.toggled.subscribe(toggled);
    quoteRequested = jest.fn();
    fixture.componentInstance.quoteRequested.subscribe(quoteRequested);
  });

  afterEach(() =>
    document.querySelectorAll('.cdk-overlay-container').forEach((node) => node.remove()),
  );

  function show(
    inputs: Partial<{
      network: string;
      countText: string | null;
      countLabel: string | null;
      reposted: boolean;
      canRepost: boolean;
      canQuote: boolean;
    }> = {},
  ): HTMLButtonElement {
    const settled = {
      network: 'bluesky',
      countText: '5',
      countLabel: '5 reposts',
      reposted: false,
      canRepost: true,
      canQuote: true,
      ...inputs,
    };
    for (const [name, value] of Object.entries(settled)) fixture.componentRef.setInput(name, value);
    fixture.detectChanges();
    return fixture.nativeElement.querySelector('button.repost');
  }

  const menuItems = () =>
    Array.from(document.querySelectorAll<HTMLButtonElement>('[role="menuitem"]'));
  const labelOf = (item: HTMLElement) =>
    item.textContent?.replace(item.querySelector('app-icon')?.textContent ?? '', '').trim();

  it('shows the count behind the repost glyph and opens a menu', () => {
    const button = show();

    expect(button.querySelector('.number')?.textContent?.trim()).toBe('5');
    expect(button.getAttribute('aria-haspopup')).toBe('menu');
    expect(button.getAttribute('aria-label')).toBe('Repost on Bluesky, 5 reposts');
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });

  it('shows no number before the counts load', () => {
    expect(show({ countText: null }).querySelector('.number')).toBeNull();
  });

  it('offers repost and quote in the menu and emits the one chosen', () => {
    show().click();
    fixture.detectChanges();

    expect(menuItems().map(labelOf)).toEqual(['Repost on Bluesky', 'Quote on Bluesky']);
    menuItems()[0].click();
    fixture.detectChanges();

    expect(toggled).toHaveBeenCalledTimes(1);
    expect(menuItems()).toHaveLength(0);

    show().click();
    fixture.detectChanges();
    menuItems()[1].click();
    fixture.detectChanges();

    expect(quoteRequested).toHaveBeenCalledTimes(1);
    expect(menuItems()).toHaveLength(0);
  });

  it('offers to undo a boost on Mastodon', () => {
    show({ network: 'mastodon', reposted: true }).click();
    fixture.detectChanges();

    expect(menuItems().map(labelOf)).toEqual(['Undo boost on Mastodon', 'Quote on Mastodon']);
  });

  it('opens the action sheet on a touch screen', () => {
    isCoarse.set(true);
    const button = show();
    expect(button.getAttribute('aria-haspopup')).toBe('dialog');
    button.click();

    expect(sheet.open).toHaveBeenCalledWith({
      title: 'Repost or quote',
      actions: [
        { id: 'toggle', label: 'Repost on Bluesky', symbol: 'repeat', disabled: false },
        { id: 'quote', label: 'Quote on Bluesky', symbol: 'format_quote', disabled: false },
      ],
    });
    expect(toggled).toHaveBeenCalledTimes(1);
    expect(menuItems()).toHaveLength(0);
  });

  it('quotes from the action sheet', () => {
    isCoarse.set(true);
    sheet.open.mockReturnValue(of('quote'));
    show().click();

    expect(quoteRequested).toHaveBeenCalledTimes(1);
    expect(toggled).not.toHaveBeenCalled();
  });

  it('keeps a refused repost in the menu, dimmed with its reason, and still quotes', () => {
    show({ network: 'mastodon', canRepost: false }).click();
    fixture.detectChanges();
    const [repost, quote] = menuItems();

    expect(repost.getAttribute('aria-disabled')).toBe('true');
    expect(repost.title).toBe("The post's visibility keeps it from boosts");
    expect(quote.getAttribute('aria-disabled')).toBeNull();
    repost.click();
    expect(toggled).not.toHaveBeenCalled();
    quote.click();
    expect(quoteRequested).toHaveBeenCalledTimes(1);
  });

  it('keeps a refused quote in the menu, dimmed with its reason', () => {
    show({ canQuote: false }).click();
    fixture.detectChanges();
    const quote = menuItems()[1];

    expect(quote.getAttribute('aria-disabled')).toBe('true');
    expect(quote.title).toBe('The author doesn’t allow quotes of this post');
    quote.click();
    expect(quoteRequested).not.toHaveBeenCalled();
  });

  it('dims both refused actions on the action sheet', () => {
    isCoarse.set(true);
    sheet.open.mockReturnValue(of(undefined));
    show({ canRepost: false }).click();

    expect(
      sheet.open.mock.calls[0][0].actions.map((action: { disabled: boolean }) => action.disabled),
    ).toEqual([true, false]);
  });

  it('emits nothing when the sheet is dismissed', () => {
    isCoarse.set(true);
    sheet.open.mockReturnValue(of(undefined));
    show().click();

    expect(toggled).not.toHaveBeenCalled();
  });

  it('stays visible but inert on a post the network keeps from reposts and quotes, saying why', () => {
    const button = show({ network: 'mastodon', canRepost: false, canQuote: false });

    expect(button.getAttribute('aria-disabled')).toBe('true');
    expect(button.getAttribute('title')).toBe("The post's visibility keeps it from boosts");
    button.click();
    fixture.detectChanges();

    expect(menuItems()).toHaveLength(0);
    expect(toggled).not.toHaveBeenCalled();
  });

  it('marks a reposted post', () => {
    const button = show({ reposted: true });

    expect(button.classList).toContain('on');
    expect(button.getAttribute('aria-label')).toBe('Undo repost on Bluesky, 5 reposts');
  });
});
