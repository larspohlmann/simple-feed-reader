import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ComposerContextDto, PublishedPostDto, QuotedPostDto } from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import {
  ComposerDialogComponent,
  ComposeTarget,
  LinkComposeTarget,
  ResponseComposeTarget,
} from './composer-dialog.component';

const REPLY = 'https://hachyderm.io/users/ann/statuses/200';
const QUOTED: QuotedPostDto = {
  authorName: 'Ann',
  authorHandle: '@ann@hachyderm.io',
  excerpt: 'What do you think?',
};

describe('ComposerDialogComponent', () => {
  const close = jest.fn();
  let api: { composer: jest.Mock; send: jest.Mock };

  const context = (overrides: Partial<ComposerContextDto> = {}): ComposerContextDto => ({
    accounts: [{ id: 4, handle: '@alice@mastodon.example' }],
    accountId: 4,
    canSend: true,
    maxCharacters: 500,
    visibility: 'unlisted',
    mentions: ['@ann@hachyderm.io'],
    quote: null,
    ...overrides,
  });

  const target = (overrides: Partial<ResponseComposeTarget> = {}): ResponseComposeTarget => ({
    kind: 'reply',
    entryId: 7,
    network: 'mastodon',
    address: REPLY,
    authorName: 'Ann',
    authorHandle: '@ann@hachyderm.io',
    excerpt: 'What do you think?',
    ...overrides,
  });

  const article = (overrides: Partial<LinkComposeTarget> = {}): LinkComposeTarget => ({
    kind: 'link',
    network: 'mastodon',
    url: 'https://example.com/story',
    title: 'A long read',
    site: 'example.com',
    imageUrl: 'https://example.com/lead.jpg',
    prefill: 'A long read\n\nhttps://example.com/story',
    ...overrides,
  });

  function mount(replyTarget: ComposeTarget = target()) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: replyTarget },
        { provide: SocialPostApi, useValue: api },
      ],
    });
    const fixture = TestBed.createComponent(ComposerDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ReturnType<typeof mount>) => fixture.nativeElement as HTMLElement;
  const textArea = (fixture: ReturnType<typeof mount>) =>
    element(fixture).querySelector('#reply-text') as HTMLTextAreaElement;
  const sendButton = (fixture: ReturnType<typeof mount>) =>
    element(fixture).querySelector('button[type="submit"]') as HTMLButtonElement;

  function type(fixture: ReturnType<typeof mount>, text: string): void {
    textArea(fixture).value = text;
    textArea(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function submit(fixture: ReturnType<typeof mount>): void {
    element(fixture).querySelector('form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    close.mockReset();
    api = {
      composer: jest.fn(() => of(context())),
      send: jest.fn(() => of<PublishedPostDto>({ address: 'https://x/1', url: null })),
    };
  });

  it('shows the post it answers and starts the text with the mentions', () => {
    const fixture = mount();

    expect(api.composer).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'reply', entryId: 7, address: REPLY }),
      undefined,
    );
    expect(element(fixture).querySelector('.target')!.textContent).toContain('What do you think?');
    expect(textArea(fixture).value).toBe('@ann@hachyderm.io ');
  });

  it('puts the cursor in the text once the composer is ready', () => {
    const fixture = mount();

    expect(document.activeElement).toBe(textArea(fixture));
  });

  it('clears the text and puts the cursor back, and cannot clear an empty text', () => {
    const fixture = mount();
    const clear = () => element(fixture).querySelector('.clear button') as HTMLButtonElement;
    textArea(fixture).blur();

    clear().click();
    fixture.detectChanges();

    expect(textArea(fixture).value).toBe('');
    expect(document.activeElement).toBe(textArea(fixture));
    expect(clear().disabled).toBe(true);
  });

  it('offers the account choice only with more than one account, keeping the text on a change', () => {
    const single = mount();
    expect(element(single).querySelector('#reply-account')).toBeNull();
    TestBed.resetTestingModule();

    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];
    api.composer.mockReturnValue(of(context({ accounts })));
    const fixture = mount();
    type(fixture, 'Kept');
    const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
    select.value = '5';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(api.composer).toHaveBeenLastCalledWith(
      expect.objectContaining({ entryId: 7, address: REPLY }),
      5,
    );
    expect(textArea(fixture).value).toBe('Kept');
  });

  describe('on a chosen account', () => {
    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];

    function chooseSecond(fixture: ReturnType<typeof mount>): void {
      const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();
    }

    it('retries there after a failed load', () => {
      api.composer.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      api.composer.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 502 })));
      chooseSecond(fixture);

      (element(fixture).querySelector('app-error-banner button') as HTMLButtonElement).click();

      expect(api.composer).toHaveBeenLastCalledWith(
        expect.objectContaining({ entryId: 7, address: REPLY }),
        5,
      );
    });

    it('keeps a visibility the user chose over the next account default', () => {
      api.composer.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      const visibility = element(fixture).querySelector('#reply-visibility') as HTMLSelectElement;
      visibility.value = 'private';
      visibility.dispatchEvent(new Event('change'));
      api.composer.mockReturnValue(of(context({ accounts, accountId: 5, visibility: 'public' })));
      chooseSecond(fixture);
      type(fixture, 'Hi');

      submit(fixture);

      expect(api.send.mock.calls[0][1]).toMatchObject({ accountId: 5, visibility: 'private' });
    });

    it('drops a context still on the wire when the composer closes', () => {
      api.composer.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      const slow = new Subject<ComposerContextDto>();
      api.composer.mockReturnValueOnce(slow);
      chooseSecond(fixture);
      expect(slow.observed).toBe(true);

      fixture.destroy();

      expect(slow.observed).toBe(false);
    });
  });

  it('sends only a text that is there and fits the limit', () => {
    api.composer.mockReturnValue(of(context({ mentions: [], maxCharacters: 5 })));
    const fixture = mount();

    expect(sendButton(fixture).disabled).toBe(true);
    type(fixture, 'Hello');
    expect(sendButton(fixture).disabled).toBe(false);
    type(fixture, 'Hello!');
    expect(sendButton(fixture).disabled).toBe(true);
    expect(element(fixture).querySelector('.counter')!.classList).toContain('over');
  });

  it('closes with the published reply, sending the account, visibility and composer key', () => {
    const fixture = mount();
    type(fixture, '@ann@hachyderm.io Agreed');

    submit(fixture);

    expect(api.send).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'reply', entryId: 7, address: REPLY }),
      expect.objectContaining({
        text: '@ann@hachyderm.io Agreed',
        accountId: 4,
        visibility: 'unlisted',
        idempotencyKey: expect.any(String),
      }),
    );
    expect(close).toHaveBeenCalledWith({
      address: 'https://x/1',
      url: null,
      text: '@ann@hachyderm.io Agreed',
      authorHandle: '@alice@mastodon.example',
      shown: target(),
    });
  });

  it('keeps the text after a failed send, says why, and resends with the same key', () => {
    api.send.mockReturnValueOnce(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: { type: 'social_post_refused', title: 'Refused', detail: 'Mastodon said no' },
          }),
      ),
    );
    const fixture = mount();
    type(fixture, 'Try');

    submit(fixture);

    expect(close).not.toHaveBeenCalled();
    expect(textArea(fixture).value).toBe('Try');
    expect(element(fixture).textContent).toContain('Mastodon said no');
    submit(fixture);
    const keys = api.send.mock.calls.map((call) => call[1].idempotencyKey);
    expect(keys[0]).toBe(keys[1]);
    expect(close).toHaveBeenCalled();
  });

  it('cannot send twice while a reply is on the wire', () => {
    const pending = new Subject<PublishedPostDto>();
    api.send.mockReturnValue(pending);
    const fixture = mount();
    type(fixture, 'Once');

    submit(fixture);
    submit(fixture);

    expect(api.send).toHaveBeenCalledTimes(1);
  });

  it('stays open while a reply is on the wire, and closable again once it fails', () => {
    const pending = new Subject<PublishedPostDto>();
    api.send.mockReturnValue(pending);
    const fixture = mount();
    const ref = TestBed.inject(DialogRef);
    const cancel = () =>
      Array.from(element(fixture).querySelectorAll('button')).find(
        (button) => button.textContent?.trim() === 'Cancel',
      )!;
    type(fixture, 'Once');

    submit(fixture);

    expect(ref.disableClose).toBe(true);
    expect(cancel().disabled).toBe(true);
    pending.error(new HttpErrorResponse({ status: 502 }));
    fixture.detectChanges();
    expect(ref.disableClose).toBe(false);
    expect(cancel().disabled).toBe(false);
  });

  it('says so and sends nothing when the author takes no replies', () => {
    api.composer.mockReturnValue(of(context({ canSend: false })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).textContent).toContain('The author limits who can reply');
    expect(sendButton(fixture).disabled).toBe(true);
  });

  it('writes a quote under its own heading and sends it as a quote', () => {
    api.composer.mockReturnValue(of(context({ mentions: [] })));
    const fixture = mount(target({ kind: 'quote' }));
    type(fixture, 'Worth reading');

    expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Quote on Mastodon');
    expect(sendButton(fixture).textContent?.trim()).toBe('Quote');
    expect(api.composer).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'quote', entryId: 7, address: REPLY }),
      undefined,
    );
    submit(fixture);

    expect(api.send).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'quote', entryId: 7, address: REPLY }),
      expect.objectContaining({ text: 'Worth reading' }),
    );
    expect(close).toHaveBeenCalledWith(expect.objectContaining({ address: 'https://x/1' }));
  });

  it('says so and sends nothing when the author keeps the post from quotes', () => {
    api.composer.mockReturnValue(of(context({ canSend: false })));
    const fixture = mount(target({ kind: 'quote' }));

    expect(element(fixture).textContent).toContain('You can’t quote this post');
    expect(sendButton(fixture).disabled).toBe(true);
  });

  it('adds a content warning only when asked to', () => {
    const fixture = mount();
    type(fixture, 'Spoiler');
    const box = element(fixture).querySelector('.warns input') as HTMLInputElement;
    box.checked = true;
    box.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const warning = element(fixture).querySelector('#reply-warning') as HTMLInputElement;
    warning.value = 'Ending';
    warning.dispatchEvent(new Event('input'));

    submit(fixture);

    expect(api.send.mock.calls[0][1]).toMatchObject({ contentWarning: 'Ending' });
  });

  it('shows Bluesky no visibility choice', () => {
    api.composer.mockReturnValue(of(context({ visibility: null, mentions: [] })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).querySelector('#reply-visibility')).toBeNull();
    expect(element(fixture).querySelector('.warns')).toBeNull();
  });

  describe('for a link post', () => {
    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];

    beforeEach(() => api.composer.mockReturnValue(of(context({ mentions: [] }))));

    it('shows the article as a card and starts the text with the prefill', () => {
      const fixture = mount(article());

      const card = element(fixture).querySelector('.article')!;
      expect(card.querySelector('.title')!.textContent).toContain('A long read');
      expect(card.querySelector('.site')!.textContent).toContain('example.com');
      expect(card.querySelector('img')!.getAttribute('src')).toBe('https://example.com/lead.jpg');
      expect(element(fixture).querySelector('.target')).toBeNull();
      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Post on Mastodon');
      expect(textArea(fixture).value).toBe('A long read\n\nhttps://example.com/story');
      expect(api.composer).toHaveBeenCalledWith(
        expect.objectContaining({
          kind: 'link',
          network: 'mastodon',
          url: 'https://example.com/story',
        }),
        undefined,
      );
    });

    it('shows a post on its own network as the quote it becomes, with an empty text', () => {
      api.composer.mockReturnValue(of(context({ mentions: [], quote: QUOTED })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));

      const quoted = element(fixture).querySelector('.target')!;
      expect(quoted.querySelector('.name')!.textContent).toContain('Ann');
      expect(quoted.querySelector('.handle')!.textContent).toContain('@ann@hachyderm.io');
      expect(quoted.querySelector('.excerpt')!.textContent).toContain('What do you think?');
      expect(element(fixture).querySelector('.article')).toBeNull();
      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Quote on Mastodon');
      expect(sendButton(fixture).textContent?.trim()).toBe('Quote');
      expect(textArea(fixture).value).toBe('');
    });

    it('sends the quote of a post on its own network to the link endpoint, closing with it shown', () => {
      api.composer.mockReturnValue(of(context({ mentions: [], quote: QUOTED })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));
      type(fixture, 'Look');

      submit(fixture);

      expect(api.send).toHaveBeenCalledWith(
        expect.objectContaining({ kind: 'link', url: 'https://mastodon.social/@ann/110' }),
        expect.objectContaining({ text: 'Look' }),
      );
      expect(close).toHaveBeenCalledWith(
        expect.objectContaining({ shown: { ...QUOTED, kind: 'quote', network: 'mastodon' } }),
      );
    });

    it('shows a card without an image or site when the article has none', () => {
      const fixture = mount(article({ imageUrl: null, site: '' }));

      expect(element(fixture).querySelector('.article img')).toBeNull();
      expect(element(fixture).querySelector('.article .site')).toBeNull();
    });

    it('uses the prefill once, keeping what the reader wrote over an account switch', () => {
      api.composer.mockReturnValue(of(context({ accounts, mentions: [] })));
      const fixture = mount(article());
      type(fixture, '');
      const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(api.composer).toHaveBeenLastCalledWith(
        expect.objectContaining({ kind: 'link', network: 'mastodon' }),
        5,
      );
      expect(textArea(fixture).value).toBe('');
    });

    it('starts the text with the prefill once the context arrives', () => {
      const slow = new Subject<ComposerContextDto>();
      api.composer.mockReturnValue(slow);
      const fixture = mount(article());

      slow.next(context({ mentions: [] }));
      fixture.detectChanges();

      expect(textArea(fixture).value).toBe('A long read\n\nhttps://example.com/story');
    });

    it('keeps what the reader wrote when a later context quotes', () => {
      api.composer.mockReturnValue(of(context({ accounts, mentions: [] })));
      const fixture = mount(article());
      type(fixture, 'Mine');
      api.composer.mockReturnValue(of(context({ accounts, mentions: [], quote: QUOTED })));
      const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(textArea(fixture).value).toBe('Mine');
    });

    it('sends the post as a link post', () => {
      const fixture = mount(article({ network: 'bluesky', prefill: 'A long read' }));

      expect(sendButton(fixture).textContent?.trim()).toBe('Post');
      submit(fixture);

      expect(api.send).toHaveBeenCalledWith(
        expect.objectContaining({
          kind: 'link',
          network: 'bluesky',
          url: 'https://example.com/story',
        }),
        expect.objectContaining({ text: 'A long read', accountId: 4 }),
      );
      expect(close).toHaveBeenCalledWith(expect.objectContaining({ address: 'https://x/1' }));
    });
  });
});
