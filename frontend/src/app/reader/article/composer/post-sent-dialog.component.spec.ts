import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LinkComposeTarget, ResponseComposeTarget, SentPost } from './composer-dialog.component';
import { PostSent, PostSentDialogComponent } from './post-sent-dialog.component';

describe('PostSentDialogComponent', () => {
  const close = jest.fn();

  const link: LinkComposeTarget = {
    kind: 'link',
    network: 'bluesky',
    url: 'https://example.com/story',
    title: 'A long read',
    site: 'example.com',
    imageUrl: 'https://example.com/lead.jpg',
    prefill: '',
  };
  const quote: ResponseComposeTarget = {
    kind: 'quote',
    entryId: 7,
    network: 'mastodon',
    address: null,
    authorName: 'Ann',
    authorHandle: '@ann@hachyderm.io',
    excerpt: 'What do you think?',
  };
  const sent = (overrides: Partial<SentPost> = {}): SentPost => ({
    address: 'at://did:plc:a/app.bsky.feed.post/9',
    url: 'https://bsky.app/profile/alice.test/post/9',
    text: 'Worth a read\nhttps://example.com/story',
    authorHandle: '@alice.test',
    ...overrides,
  });

  function mount(posted: PostSent): HTMLElement {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: posted },
      ],
    });
    const fixture = TestBed.createComponent(PostSentDialogComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const buttonLabelled = (element: HTMLElement, label: string) =>
    Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === label,
    );

  afterEach(() => close.mockReset());

  it('shows a link post as sent, the account it went out as, and the article card', () => {
    const element = mount({ target: link, sent: sent() });

    expect(element.querySelector('h1, h2')?.textContent).toContain('Posted on Bluesky');
    expect(element.querySelector('.account')?.textContent).toContain('As @alice.test');
    expect(element.querySelector('.text')?.textContent).toBe(
      'Worth a read\nhttps://example.com/story',
    );
    const card = element.querySelector('.article')!;
    expect(card.querySelector('.title')?.textContent).toContain('A long read');
    expect(card.querySelector('.site')?.textContent).toContain('example.com');
    expect(card.querySelector('img')?.getAttribute('src')).toBe('https://example.com/lead.jpg');
    expect(buttonLabelled(element, 'Open post')).toBeDefined();
  });

  it('offers no Open button when the network named no link', () => {
    const element = mount({ target: link, sent: sent({ url: null }) });

    expect(buttonLabelled(element, 'Open post')).toBeUndefined();
    expect(buttonLabelled(element, 'Close')).toBeDefined();
  });

  it('shows a quote with the post it quoted', () => {
    const element = mount({ target: quote, sent: sent() });

    expect(element.querySelector('h1, h2')?.textContent).toContain('Quote posted on Mastodon');
    const quoted = element.querySelector('.target')!;
    expect(quoted.textContent).toContain('Ann');
    expect(quoted.textContent).toContain('What do you think?');
    expect(element.querySelector('.article')).toBeNull();
  });

  it('opens the post on the network in a new tab', () => {
    const open = jest.spyOn(window, 'open').mockImplementation(() => null);
    const element = mount({ target: link, sent: sent() });

    buttonLabelled(element, 'Open post')!.click();

    expect(open).toHaveBeenCalledWith(
      'https://bsky.app/profile/alice.test/post/9',
      '_blank',
      'noopener',
    );
    open.mockRestore();
  });

  it('closes on Close', () => {
    const element = mount({ target: link, sent: sent() });

    buttonLabelled(element, 'Close')!.click();

    expect(close).toHaveBeenCalled();
  });
});
