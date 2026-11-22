import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { Observable, Subject, config, of, throwError } from 'rxjs';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ComposeRequest, LinkRequest, QuoteRequest, ReplyRequest } from './compose-request';
import {
  ComposerCardDto,
  ComposerContextDto,
  LinkComposerSubjectDto,
  PostComposerSubjectDto,
  PublishedPostDto,
} from './composer-dto';
import { ComposerApi } from './composer-api';
import { PostNetwork, PostNetworks } from '../post-networks.service';
import { ComposerDialogComponent } from './composer-dialog.component';

const REPLY = 'https://hachyderm.io/users/ann/statuses/200';
const QUOTED: PostComposerSubjectDto = {
  kind: 'post',
  authorName: 'Ann',
  authorHandle: '@ann@hachyderm.io',
  excerpt: 'What do you think?',
  card: null,
};
const STORY_CARD: ComposerCardDto = {
  url: 'https://example.com/story',
  title: 'A long read',
  description: null,
  imageUrl: 'https://example.com/lead.jpg',
  siteName: 'example.com',
};
const STORY: LinkComposerSubjectDto = { kind: 'link', card: STORY_CARD };

describe('ComposerDialogComponent', () => {
  const close = jest.fn();
  let backdropClick: Subject<MouseEvent>;
  let keydownEvents: Subject<KeyboardEvent>;
  const connected = signal<PostNetwork[]>([]);
  let api: { context: jest.Mock; send: jest.Mock };

  const context = (overrides: Partial<ComposerContextDto> = {}): ComposerContextDto => ({
    kind: 'reply',
    accounts: [{ id: 4, handle: '@alice@mastodon.example' }],
    accountId: 4,
    canSend: true,
    maxCharacters: 500,
    visibility: 'unlisted',
    text: '@ann@hachyderm.io ',
    subject: QUOTED,
    lemmy: null,
    ...overrides,
  });

  const target = (
    overrides: Partial<Omit<ReplyRequest, 'kind'>> & { kind?: 'reply' | 'quote' } = {},
  ): ReplyRequest | QuoteRequest => ({
    kind: 'reply',
    entryId: 7,
    network: 'mastodon',
    address: REPLY,
    ...overrides,
  });

  const article = (overrides: Partial<LinkRequest> = {}): LinkRequest => ({
    kind: 'link',
    network: 'mastodon',
    url: 'https://example.com/story',
    title: 'A long read',
    quote: null,
    ...overrides,
  });

  function mount(replyTarget: ComposeRequest = target()) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close, backdropClick, keydownEvents } },
        { provide: DIALOG_DATA, useValue: replyTarget },
        { provide: ComposerApi, useValue: api },
        { provide: PostNetworks, useValue: { connected } },
        provideAccountIdentity(),
      ],
    });
    const fixture = TestBed.createComponent(ComposerDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ReturnType<typeof mount>) => fixture.nativeElement as HTMLElement;
  const textArea = (fixture: ReturnType<typeof mount>) =>
    element(fixture).querySelector('#compose-text') as HTMLTextAreaElement;
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
    backdropClick = new Subject<MouseEvent>();
    keydownEvents = new Subject<KeyboardEvent>();
    connected.set([]);
    api = {
      context: jest.fn(() => of(context())),
      send: jest.fn(() => of<PublishedPostDto>({ address: 'https://x/1', url: null })),
    };
  });

  it('shows the post it answers and starts the text where the server says', () => {
    const fixture = mount();

    expect(api.context).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'reply', entryId: 7, address: REPLY }),
      undefined,
    );
    const answered = element(fixture).querySelector('.target')!;
    expect(answered.querySelector('.handle')!.textContent).toContain('@ann@hachyderm.io');
    expect(answered.querySelector('.excerpt')!.textContent).toContain('What do you think?');
    expect(textArea(fixture).value).toBe('@ann@hachyderm.io ');
  });

  it('renders no subject when the context has none', () => {
    api.context.mockReturnValue(of(context({ subject: null })));

    const fixture = mount();

    expect(element(fixture).querySelector('app-composer-subject')).toBeNull();
    expect(textArea(fixture)).not.toBeNull();
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
    expect(element(single).querySelector('#compose-account')).toBeNull();
    TestBed.resetTestingModule();

    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];
    api.context.mockReturnValue(of(context({ accounts })));
    const fixture = mount();
    type(fixture, 'Kept');
    const select = element(fixture).querySelector('#compose-account') as HTMLSelectElement;
    select.value = '5';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(api.context).toHaveBeenLastCalledWith(
      expect.objectContaining({ entryId: 7, address: REPLY }),
      5,
    );
    expect(textArea(fixture).value).toBe('Kept');
  });

  it('puts the account choice above the text in a reply, as before crossposting', () => {
    api.context.mockReturnValue(
      of(
        context({
          accounts: [
            { id: 4, handle: '@alice@mastodon.example' },
            { id: 5, handle: '@bea@mastodon.example' },
          ],
        }),
      ),
    );
    const fixture = mount();

    const select = element(fixture).querySelector('#compose-account')!;
    expect(
      select.compareDocumentPosition(textArea(fixture)) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  describe('on a chosen account', () => {
    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];

    function chooseSecond(fixture: ReturnType<typeof mount>): void {
      const select = element(fixture).querySelector('#compose-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();
    }

    it('retries there after a failed load', () => {
      api.context.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      api.context.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 502 })));
      chooseSecond(fixture);

      (element(fixture).querySelector('app-error-banner button') as HTMLButtonElement).click();

      expect(api.context).toHaveBeenLastCalledWith(
        expect.objectContaining({ entryId: 7, address: REPLY }),
        5,
      );
    });

    it('keeps a visibility the user chose over the next account default', () => {
      api.context.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      const visibility = element(fixture).querySelector('#compose-visibility') as HTMLSelectElement;
      visibility.value = 'private';
      visibility.dispatchEvent(new Event('change'));
      api.context.mockReturnValue(of(context({ accounts, accountId: 5, visibility: 'public' })));
      chooseSecond(fixture);
      type(fixture, 'Hi');

      submit(fixture);

      expect(api.send.mock.calls[0][1]).toMatchObject({ accountId: 5, visibility: 'private' });
    });

    it('drops a context still on the wire when the composer closes', () => {
      api.context.mockReturnValue(of(context({ accounts })));
      const fixture = mount();
      const slow = new Subject<ComposerContextDto>();
      api.context.mockReturnValueOnce(slow);
      chooseSecond(fixture);
      expect(slow.observed).toBe(true);

      fixture.destroy();

      expect(slow.observed).toBe(false);
    });
  });

  it('sends only a text that is there and fits the limit', () => {
    api.context.mockReturnValue(of(context({ text: '', maxCharacters: 5 })));
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
    expect(close).toHaveBeenCalledWith([
      {
        address: 'https://x/1',
        url: null,
        text: '@ann@hachyderm.io Agreed',
        authorHandle: '@alice@mastodon.example',
        kind: 'reply',
        network: 'mastodon',
        subject: QUOTED,
      },
    ]);
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
    api.context.mockReturnValue(of(context({ canSend: false })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).textContent).toContain('The author limits who can reply');
    expect(sendButton(fixture).disabled).toBe(true);
  });

  describe('replying on Lemmy', () => {
    const lemmy = () => target({ network: 'lemmy', address: 'https://lemmy.example/comment/12' });

    it('heads the reply for Lemmy and shows no visibility row', () => {
      api.context.mockReturnValue(
        of(context({ accounts: [{ id: 9, handle: '@lars@lemmy.example' }], visibility: null })),
      );
      const fixture = mount(lemmy());

      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Reply on Lemmy');
      expect(element(fixture).querySelector('#compose-visibility')).toBeNull();
    });

    it('says a locked post cannot be replied to', () => {
      api.context.mockReturnValue(of(context({ canSend: false, visibility: null })));
      const fixture = mount(lemmy());

      expect(element(fixture).querySelector('[role="alert"]')?.textContent).toContain(
        'This post is locked',
      );
      expect(textArea(fixture).disabled).toBe(true);
    });
  });

  it('writes a quote under its own heading and sends it as a quote', () => {
    api.context.mockReturnValue(of(context({ kind: 'quote', text: '' })));
    const fixture = mount(target({ kind: 'quote' }));
    type(fixture, 'Worth reading');

    expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Quote on Mastodon');
    expect(sendButton(fixture).textContent?.trim()).toBe('Quote');
    expect(api.context).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'quote', entryId: 7, address: REPLY }),
      undefined,
    );
    submit(fixture);

    expect(api.send).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'quote', entryId: 7, address: REPLY }),
      expect.objectContaining({ text: 'Worth reading' }),
    );
    expect(close).toHaveBeenCalledWith([expect.objectContaining({ address: 'https://x/1' })]);
  });

  it('says so and sends nothing when the author keeps the post from quotes', () => {
    api.context.mockReturnValue(of(context({ kind: 'quote', canSend: false, text: '' })));
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
    const warning = element(fixture).querySelector('#compose-warning') as HTMLInputElement;
    warning.value = 'Ending';
    warning.dispatchEvent(new Event('input'));

    submit(fixture);

    expect(api.send.mock.calls[0][1]).toMatchObject({ contentWarning: 'Ending' });
  });

  it("counts Mastodon's content warning towards the limit", () => {
    api.context.mockReturnValue(of(context({ text: '', maxCharacters: 500 })));
    const fixture = mount();
    type(fixture, 'a'.repeat(491));
    expect(sendButton(fixture).disabled).toBe(false);
    const box = element(fixture).querySelector('.warns input') as HTMLInputElement;
    box.checked = true;
    box.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const warning = element(fixture).querySelector('#compose-warning') as HTMLInputElement;
    warning.value = 'spoilers!!';
    warning.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(sendButton(fixture).disabled).toBe(true);
    expect(element(fixture).querySelector('.counter')!.classList).toContain('over');
  });

  it('shows Bluesky no visibility choice', () => {
    api.context.mockReturnValue(of(context({ visibility: null, text: '' })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).querySelector('#compose-visibility')).toBeNull();
    expect(element(fixture).querySelector('.warns')).toBeNull();
  });

  describe('for a link post', () => {
    const accounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];

    const linkContext = (overrides: Partial<ComposerContextDto> = {}) =>
      context({
        kind: 'link',
        text: 'A long read\n\nhttps://example.com/story',
        subject: STORY,
        ...overrides,
      });
    const quoteContext = (overrides: Partial<ComposerContextDto> = {}) =>
      linkContext({ kind: 'quote', text: '', subject: QUOTED, ...overrides });

    beforeEach(() => api.context.mockReturnValue(of(linkContext())));

    it('puts the caret before the quote so a comment can go first', () => {
      api.context.mockReturnValue(
        of(linkContext({ text: '“A line”\n\nhttps://example.com/story' })),
      );

      const fixture = mount(article({ quote: 'A line' }));

      expect(document.activeElement).toBe(textArea(fixture));
      expect([textArea(fixture).selectionStart, textArea(fixture).selectionEnd]).toEqual([0, 0]);
    });

    it('leaves the caret after the text of a link post without a quote', () => {
      const fixture = mount(article());

      expect(textArea(fixture).selectionStart).toBe(textArea(fixture).value.length);
    });

    it('shows the page card and the starting text once the context arrives', () => {
      const slow = new Subject<ComposerContextDto>();
      api.context.mockReturnValue(slow);
      const fixture = mount(article());
      expect(element(fixture).querySelector('.article')).toBeNull();
      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Post on Mastodon');

      slow.next(linkContext());
      fixture.detectChanges();

      const card = element(fixture).querySelector('.article')!;
      expect(card.querySelector('.title')!.textContent).toContain('A long read');
      expect(card.querySelector('.site')!.textContent).toContain('example.com');
      expect(card.querySelector('img')!.getAttribute('src')).toBe('https://example.com/lead.jpg');
      expect(element(fixture).querySelector('.target')).toBeNull();
      expect(textArea(fixture).value).toBe('A long read\n\nhttps://example.com/story');
      expect(api.context).toHaveBeenCalledWith(
        expect.objectContaining({
          kind: 'link',
          network: 'mastodon',
          url: 'https://example.com/story',
          title: 'A long read',
        }),
        undefined,
      );
    });

    it('shows a post on its own network as the quote it becomes, with an empty text', () => {
      api.context.mockReturnValue(of(quoteContext()));
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

    it('shows the link card the quoted post carries below its text', () => {
      const card = {
        url: 'https://www.politico.com/news/bill',
        title: 'Senate passes the bill',
        description: null,
        imageUrl: 'https://cdn.bsky.app/img/thumb.jpg',
        siteName: 'politico.com',
      };
      api.context.mockReturnValue(of(quoteContext({ subject: { ...QUOTED, card } })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));

      const quotedCard = element(fixture).querySelector('.target .article')!;
      expect(quotedCard.querySelector('.title')!.textContent).toContain('Senate passes the bill');
      expect(quotedCard.querySelector('.site')!.textContent).toContain('politico.com');
      expect(quotedCard.querySelector('img')!.getAttribute('src')).toContain('thumb.jpg');
    });

    it('sends the quote of a post on its own network to the link endpoint, closing with it shown', () => {
      api.context.mockReturnValue(of(quoteContext()));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));
      type(fixture, 'Look');

      submit(fixture);

      expect(api.send).toHaveBeenCalledWith(
        expect.objectContaining({ kind: 'link', url: 'https://mastodon.social/@ann/110' }),
        expect.objectContaining({ text: 'Look' }),
      );
      expect(close).toHaveBeenCalledWith([
        expect.objectContaining({ kind: 'quote', network: 'mastodon', subject: QUOTED }),
      ]);
    });

    it('shows a card without an image or site when the page has none', () => {
      api.context.mockReturnValue(
        of(
          linkContext({
            subject: { kind: 'link', card: { ...STORY_CARD, imageUrl: null, siteName: '' } },
          }),
        ),
      );
      const fixture = mount(article());

      expect(element(fixture).querySelector('.article img')).toBeNull();
      expect(element(fixture).querySelector('.article .site')).toBeNull();
    });

    it('says so when the post it becomes a quote of takes no quotes', () => {
      api.context.mockReturnValue(of(quoteContext({ canSend: false })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));

      expect(element(fixture).textContent).toContain('You can’t quote this post');
      expect(sendButton(fixture).disabled).toBe(true);
    });

    it('uses the starting text once, keeping what the reader wrote over an account switch', () => {
      api.context.mockReturnValue(of(linkContext({ accounts })));
      const fixture = mount(article());
      type(fixture, '');
      const select = element(fixture).querySelector('#compose-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(api.context).toHaveBeenLastCalledWith(
        expect.objectContaining({ kind: 'link', network: 'mastodon' }),
        5,
      );
      expect(textArea(fixture).value).toBe('');
    });

    it('keeps the quote heading while an account switch reloads the context', () => {
      api.context.mockReturnValue(of(quoteContext({ accounts })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));
      api.context.mockReturnValue(new Subject<ComposerContextDto>());
      const select = element(fixture).querySelector('#compose-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Quote on Mastodon');
    });

    it('keeps what the reader wrote when a later context quotes', () => {
      api.context.mockReturnValue(of(linkContext({ accounts })));
      const fixture = mount(article());
      type(fixture, 'Mine');
      api.context.mockReturnValue(of(quoteContext({ accounts })));
      const select = element(fixture).querySelector('#compose-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(textArea(fixture).value).toBe('Mine');
    });

    it('sends the post as a link post', () => {
      api.context.mockReturnValue(of(linkContext({ visibility: null, text: 'A long read' })));
      const fixture = mount(article({ network: 'bluesky' }));

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
      expect(close).toHaveBeenCalledWith([
        expect.objectContaining({ address: 'https://x/1', kind: 'link', subject: STORY }),
      ]);
    });
  });

  describe('crosspost', () => {
    const TITLE = 'The quiet return of the RSS reader';
    const blueskyAccounts = [
      { id: 1, handle: '@alice.bsky.social' },
      { id: 2, handle: '@work.bsky.social' },
    ];
    const mastodonAccounts = [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ];
    let blueskyContext: () => Observable<ComposerContextDto>;
    let mastodonContext: () => Observable<ComposerContextDto>;

    const linkContext = (overrides: Partial<ComposerContextDto> = {}) =>
      context({ kind: 'link', text: TITLE, subject: STORY, ...overrides });
    const onBluesky = (overrides: Partial<ComposerContextDto> = {}) =>
      linkContext({
        accounts: [blueskyAccounts[0]],
        accountId: 1,
        maxCharacters: 300,
        visibility: null,
        ...overrides,
      });
    const onMastodon = (overrides: Partial<ComposerContextDto> = {}) =>
      linkContext({
        maxCharacters: 475,
        visibility: 'public',
        text: 'Mastodon text',
        ...overrides,
      });
    const failure = () =>
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: { type: 'social_post_refused', title: 'Refused', detail: 'Reconnect first' },
          }),
      );

    beforeEach(() => {
      connected.set([
        { network: 'bluesky', handles: ['@alice.bsky.social'] },
        { network: 'mastodon', handles: ['@alice@mastodon.example'] },
      ]);
      blueskyContext = () => of(onBluesky());
      mastodonContext = () => of(onMastodon());
      api.context.mockImplementation((posted: ComposeRequest) =>
        posted.network === 'bluesky' ? blueskyContext() : mastodonContext(),
      );
    });

    const fromBluesky = () => mount(article({ network: 'bluesky' }));
    const pills = (fixture: ReturnType<typeof mount>) =>
      Array.from(
        element(fixture).querySelectorAll('app-network-pills button'),
      ) as HTMLButtonElement[];
    const pill = (fixture: ReturnType<typeof mount>, name: string) =>
      pills(fixture).find((button) => button.textContent?.includes(name))!;
    const pressed = (fixture: ReturnType<typeof mount>) =>
      pills(fixture).map((button) => button.getAttribute('aria-pressed'));
    function toggle(fixture: ReturnType<typeof mount>, name: string): void {
      pill(fixture, name).click();
      fixture.detectChanges();
    }
    const heading = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('h1, h2')?.textContent?.trim();
    const counters = (fixture: ReturnType<typeof mount>) =>
      Array.from(element(fixture).querySelectorAll('.counter')).map((counter) =>
        counter.textContent?.trim(),
      );
    const banners = (fixture: ReturnType<typeof mount>) =>
      Array.from(element(fixture).querySelectorAll('app-error-banner'));
    const loadsOf = (network: string) =>
      api.context.mock.calls.filter(([posted]) => posted.network === network);
    const sendsOf = (network: string) =>
      api.send.mock.calls.filter(([posted]) => posted.network === network);
    function choose(fixture: ReturnType<typeof mount>, selector: string, value: string): void {
      const select = element(fixture).querySelector(selector) as HTMLSelectElement;
      select.value = value;
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();
    }

    it('opened from Bluesky, Bluesky is on and Mastodon off', () => {
      const fixture = fromBluesky();

      expect(pills(fixture).map((button) => button.textContent?.trim())).toEqual([
        'Bluesky',
        'Mastodon',
      ]);
      expect(pressed(fixture)).toEqual(['true', 'false']);
      expect(loadsOf('mastodon')).toHaveLength(0);
    });

    it('opened from Mastodon, Mastodon is on and Bluesky off', () => {
      const fixture = mount(article());

      expect(pressed(fixture)).toEqual(['false', 'true']);
      expect(loadsOf('bluesky')).toHaveLength(0);
    });

    it('one connected network shows no pills', () => {
      connected.set([{ network: 'bluesky', handles: ['@alice.bsky.social'] }]);
      const fixture = fromBluesky();

      expect(element(fixture).querySelector('app-network-pills')).toBeNull();
      expect(heading(fixture)).toBe('Post on Bluesky');
    });

    it('activating Mastodon loads its context once', () => {
      const fixture = fromBluesky();

      toggle(fixture, 'Mastodon');
      toggle(fixture, 'Mastodon');
      toggle(fixture, 'Mastodon');

      expect(pressed(fixture)).toEqual(['true', 'true']);
      expect(loadsOf('mastodon')).toEqual([[{ ...article(), network: 'mastodon' }, undefined]]);
    });

    it('the text is not replaced', () => {
      const fixture = fromBluesky();

      toggle(fixture, 'Mastodon');
      expect(textArea(fixture).value).toBe(TITLE);
      type(fixture, 'Mine');
      toggle(fixture, 'Bluesky');

      expect(textArea(fixture).value).toBe('Mine');
    });

    it('the last active pill cannot be switched off', () => {
      const fixture = fromBluesky();

      toggle(fixture, 'Bluesky');

      expect(pressed(fixture)).toEqual(['true', 'false']);
    });

    it('the picked pill can be switched off once the other is on, and the preview follows', () => {
      mastodonContext = () => of(onMastodon({ kind: 'quote', subject: QUOTED }));
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');
      expect(element(fixture).querySelector('.article')).not.toBeNull();

      toggle(fixture, 'Bluesky');

      expect(pressed(fixture)).toEqual(['false', 'true']);
      expect(element(fixture).querySelector('.target .handle')!.textContent).toContain(
        '@ann@hachyderm.io',
      );
      expect(element(fixture).querySelector('.article')).toBeNull();
      expect(heading(fixture)).toBe('Quote on Mastodon');
    });

    it('a failed Mastodon load turns its pill off with an error and a retry; Bluesky stays sendable', () => {
      mastodonContext = failure;
      const fixture = fromBluesky();

      toggle(fixture, 'Mastodon');

      expect(pressed(fixture)).toEqual(['true', 'false']);
      expect(pill(fixture, 'Mastodon').classList).toContain('failed');
      const [banner] = banners(fixture);
      expect(banner.textContent).toContain('Mastodon: Reconnect first');
      expect(banner.previousElementSibling?.tagName).toBe('APP-NETWORK-PILLS');
      expect(sendButton(fixture).disabled).toBe(false);
      mastodonContext = () => of(onMastodon());
      (banner.querySelector('button') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(pressed(fixture)).toEqual(['true', 'true']);
      expect(banners(fixture)).toHaveLength(0);
    });

    it('a failed load of the picked lane keeps it on with the error under the pills; the other can be activated', () => {
      blueskyContext = failure;
      const fixture = fromBluesky();

      expect(pressed(fixture)).toEqual(['true', 'false']);
      expect(pill(fixture, 'Bluesky').classList).toContain('failed');
      expect(banners(fixture)[0].textContent).toContain('Bluesky: Reconnect first');
      expect(banners(fixture)[0].previousElementSibling?.tagName).toBe('APP-NETWORK-PILLS');
      expect(textArea(fixture)).toBeNull();

      toggle(fixture, 'Mastodon');
      toggle(fixture, 'Bluesky');

      expect(pressed(fixture)).toEqual(['false', 'true']);
      expect(textArea(fixture)).not.toBeNull();
    });

    it('heading reads "Post to Bluesky and Mastodon" and Send "Post to both"', () => {
      const fixture = fromBluesky();

      toggle(fixture, 'Mastodon');

      expect(heading(fixture)).toBe('Post to Bluesky and Mastodon');
      expect(sendButton(fixture).textContent?.trim()).toBe('Post to both');
      toggle(fixture, 'Mastodon');
      expect(heading(fixture)).toBe('Post on Bluesky');
      expect(sendButton(fixture).textContent?.trim()).toBe('Post');
    });

    it('counters are named when both are on ("Bluesky 34 / 300", "Mastodon 34 / 475") and unnamed with one', () => {
      const fixture = fromBluesky();
      expect(counters(fixture)).toEqual(['34 / 300']);

      toggle(fixture, 'Mastodon');

      expect(counters(fixture)).toEqual(['Bluesky 34 / 300', 'Mastodon 34 / 475']);
      expect(element(fixture).querySelector('.counters')?.textContent?.replace(/\s+/g, ' ')).toBe(
        ' Bluesky 34 / 300 · Mastodon 34 / 475 ',
      );
    });

    it('a single counter has no separator', () => {
      const fixture = fromBluesky();

      expect(element(fixture).querySelector('.counter-separator')).toBeNull();
    });

    it('puts each account choice under the text beside pills', () => {
      blueskyContext = () => of(onBluesky({ accounts: blueskyAccounts }));
      const fixture = fromBluesky();

      const select = element(fixture).querySelector('#compose-account-bluesky')!;
      expect(
        textArea(fixture).compareDocumentPosition(select) & Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy();
    });

    it('an over-limit active lane turns Send off', () => {
      mastodonContext = () => of(onMastodon({ maxCharacters: 10 }));
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');
      type(fixture, 'Twelve chars');

      expect(sendButton(fixture).disabled).toBe(true);
      expect(element(fixture).querySelectorAll('.counter')[1].classList).toContain('over');
      expect(element(fixture).querySelectorAll('.counter')[0].classList).not.toContain('over');
      toggle(fixture, 'Mastodon');
      expect(sendButton(fixture).disabled).toBe(false);
    });

    it('Send is off while an active lane is loading', () => {
      const slow = new Subject<ComposerContextDto>();
      mastodonContext = () => slow;
      const fixture = fromBluesky();

      toggle(fixture, 'Mastodon');

      expect(sendButton(fixture).disabled).toBe(true);
      slow.next(onMastodon());
      fixture.detectChanges();
      expect(sendButton(fixture).disabled).toBe(false);
    });

    it('account select per active lane with more than one account, labelled by network when both are on', () => {
      mastodonContext = () => of(onMastodon({ accounts: mastodonAccounts }));
      const fixture = fromBluesky();
      expect(element(fixture).querySelector('select')).toBeNull();

      toggle(fixture, 'Mastodon');

      const selects = element(fixture).querySelectorAll(
        'app-field select[id$="-account-mastodon"]',
      );
      expect(selects).toHaveLength(1);
      expect(element(fixture).textContent).toContain('Mastodon account');
      toggle(fixture, 'Bluesky');
      expect(element(fixture).textContent).not.toContain('Mastodon account');
      expect(element(fixture).textContent).toContain('Post as');
    });

    it("switching account on Mastodon leaves Bluesky's load alone", () => {
      blueskyContext = () => of(onBluesky({ accounts: blueskyAccounts }));
      mastodonContext = () => of(onMastodon({ accounts: mastodonAccounts }));
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');
      const slow = new Subject<ComposerContextDto>();
      blueskyContext = () => slow;
      choose(fixture, '#compose-account-bluesky', '2');

      choose(fixture, '#compose-account-mastodon', '5');

      expect(slow.observed).toBe(true);
      expect(loadsOf('mastodon').at(-1)).toEqual([{ ...article(), network: 'mastodon' }, 5]);
      slow.next(onBluesky({ accounts: blueskyAccounts, accountId: 2 }));
      fixture.detectChanges();
      expect(sendButton(fixture).disabled).toBe(false);
    });

    it('switching Mastodon off mid-load ignores the late answer', () => {
      const slow = new Subject<ComposerContextDto>();
      mastodonContext = () => slow;
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');

      toggle(fixture, 'Mastodon');
      slow.next(onMastodon());
      fixture.detectChanges();

      expect(slow.observed).toBe(false);
      expect(pressed(fixture)).toEqual(['true', 'false']);
      expect(counters(fixture)).toEqual(['34 / 300']);
      expect(element(fixture).querySelector('[id$="-visibility-mastodon"]')).toBeNull();
    });

    it("Mastodon's warning and visibility hide when it is off and come back with their values", () => {
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');
      choose(fixture, '#compose-visibility-mastodon', 'private');
      const box = element(fixture).querySelector('.warns input') as HTMLInputElement;
      box.checked = true;
      box.dispatchEvent(new Event('change'));
      fixture.detectChanges();
      const warning = element(fixture).querySelector(
        '#compose-warning-mastodon',
      ) as HTMLInputElement;
      warning.value = 'Ending';
      warning.dispatchEvent(new Event('input'));

      toggle(fixture, 'Mastodon');

      expect(element(fixture).querySelector('#compose-visibility-mastodon')).toBeNull();
      expect(element(fixture).querySelector('.warns')).toBeNull();
      toggle(fixture, 'Mastodon');
      const visibility = element(fixture).querySelector(
        '#compose-visibility-mastodon',
      ) as HTMLSelectElement;
      expect(visibility.value).toBe('private');
      expect(
        (element(fixture).querySelector('#compose-warning-mastodon') as HTMLInputElement).value,
      ).toBe('Ending');
      submit(fixture);
      const blueskyDraft = sendsOf('bluesky')[0][1];
      const mastodonDraft = sendsOf('mastodon')[0][1];
      expect(blueskyDraft).not.toHaveProperty('contentWarning');
      expect(blueskyDraft).not.toHaveProperty('visibility');
      expect(mastodonDraft).toMatchObject({ visibility: 'private', contentWarning: 'Ending' });
    });

    describe('sending to both', () => {
      const published = (network: string): PublishedPostDto => ({
        address: `https://${network}.example/1`,
        url: null,
      });
      const sentPost = (network: 'bluesky' | 'mastodon', handle: string) => ({
        ...published(network),
        kind: 'link',
        network,
        text: TITLE,
        authorHandle: handle,
        subject: STORY,
      });
      const blueskySent = sentPost('bluesky', '@alice.bsky.social');
      const mastodonSent = sentPost('mastodon', '@alice@mastodon.example');
      let mastodonSend: () => Observable<PublishedPostDto>;

      beforeEach(() => {
        mastodonSend = () => of(published('mastodon'));
        api.send.mockImplementation((posted: ComposeRequest) =>
          posted.network === 'bluesky' ? of(published('bluesky')) : mastodonSend(),
        );
      });

      function bothOn(): ReturnType<typeof mount> {
        const fixture = fromBluesky();
        toggle(fixture, 'Mastodon');
        return fixture;
      }
      const cancel = (fixture: ReturnType<typeof mount>) =>
        Array.from(element(fixture).querySelectorAll('button')).find(
          (button) => button.textContent?.trim() === 'Cancel',
        )!;
      function failMastodonOnce(): void {
        mastodonSend = () => {
          mastodonSend = () => of(published('mastodon'));
          return throwError(
            () =>
              new HttpErrorResponse({
                status: 502,
                error: { type: 'social_post_refused', title: 'Refused', detail: 'No answer' },
              }),
          );
        };
      }

      it('sends one request per active network, each with its own key', () => {
        const fixture = bothOn();

        submit(fixture);

        expect(sendsOf('bluesky')).toHaveLength(1);
        expect(sendsOf('mastodon')).toHaveLength(1);
        expect(sendsOf('bluesky')[0][1].idempotencyKey).not.toBe(
          sendsOf('mastodon')[0][1].idempotencyKey,
        );
      });

      it('closes with both posts when both succeed', () => {
        const fixture = bothOn();

        submit(fixture);

        expect(close).toHaveBeenCalledTimes(1);
        expect(close).toHaveBeenCalledWith([blueskySent, mastodonSent]);
      });

      it('when Mastodon fails, Bluesky locks as Posted, the error names Mastodon, the text is kept, and Send reads "Retry Mastodon"', () => {
        failMastodonOnce();
        const fixture = bothOn();
        type(fixture, TITLE);

        submit(fixture);

        expect(close).not.toHaveBeenCalled();
        expect(pill(fixture, 'Bluesky').textContent).toContain('Posted');
        expect(pill(fixture, 'Bluesky').getAttribute('aria-disabled')).toBe('true');
        expect(pill(fixture, 'Mastodon').classList).toContain('failed');
        const [banner] = banners(fixture);
        expect(banner.textContent).toContain('Mastodon: No answer');
        expect(banner.previousElementSibling?.tagName).toBe('APP-NETWORK-PILLS');
        expect(textArea(fixture).value).toBe(TITLE);
        expect(sendButton(fixture).textContent?.trim()).toBe('Retry Mastodon');
        expect(sendButton(fixture).disabled).toBe(false);
      });

      it('a failed lane switched off drops its send error', () => {
        failMastodonOnce();
        const fixture = bothOn();
        submit(fixture);
        expect(banners(fixture)).toHaveLength(1);

        toggle(fixture, 'Mastodon');

        expect(banners(fixture)).toHaveLength(0);
      });

      it('retry sends only Mastodon, under its first key', () => {
        failMastodonOnce();
        const fixture = bothOn();
        submit(fixture);

        submit(fixture);

        expect(sendsOf('bluesky')).toHaveLength(1);
        const keys = sendsOf('mastodon').map(([, draft]) => draft.idempotencyKey);
        expect(keys).toHaveLength(2);
        expect(keys[1]).toBe(keys[0]);
        expect(close).toHaveBeenCalledWith([blueskySent, mastodonSent]);
      });

      it('Cancel after a partial failure closes with the Bluesky post', () => {
        failMastodonOnce();
        const fixture = bothOn();
        submit(fixture);

        cancel(fixture).click();

        expect(close).toHaveBeenCalledWith([blueskySent]);
      });

      it('Cancel with nothing sent closes empty', () => {
        const fixture = bothOn();

        cancel(fixture).click();

        expect(close).toHaveBeenCalledWith();
      });

      it('while sending, pills, account selects and Clear are disabled', () => {
        blueskyContext = () => of(onBluesky({ accounts: blueskyAccounts }));
        mastodonContext = () => of(onMastodon({ accounts: mastodonAccounts }));
        const pending = new Subject<PublishedPostDto>();
        mastodonSend = () => pending;
        const fixture = bothOn();
        const ref = TestBed.inject(DialogRef);

        submit(fixture);

        expect(ref.disableClose).toBe(true);
        expect(pills(fixture).every((button) => button.disabled)).toBe(true);
        const selects = Array.from(
          element(fixture).querySelectorAll('select[id^="compose-account-"]'),
        ) as HTMLSelectElement[];
        expect(selects).toHaveLength(2);
        expect(selects.every((select) => select.disabled)).toBe(true);
        expect(
          (element(fixture).querySelector('.clear button') as HTMLButtonElement).disabled,
        ).toBe(true);
        pending.error(new HttpErrorResponse({ status: 502 }));
        fixture.detectChanges();
        expect(ref.disableClose).toBe(true);
        expect(pill(fixture, 'Mastodon').disabled).toBe(false);
        expect(cancel(fixture).disabled).toBe(false);
      });

      it('a send that throws leaves the composer closable', async () => {
        const fixture = bothOn();
        const ref = TestBed.inject(DialogRef);
        const unhandled = jest.fn();
        config.onUnhandledError = unhandled;
        api.send.mockImplementation(() => {
          throw new Error('broken');
        });

        try {
          submit(fixture);
          await new Promise((resolve) => setTimeout(resolve));
        } finally {
          config.onUnhandledError = null;
        }

        expect(unhandled).toHaveBeenCalledWith(new Error('broken'));
        expect(ref.disableClose).toBe(false);
        expect(cancel(fixture).disabled).toBe(false);
      });

      it('Escape or a backdrop click after a partial failure closes with the Bluesky post', () => {
        failMastodonOnce();
        const fixture = bothOn();
        const ref = TestBed.inject(DialogRef);
        submit(fixture);

        expect(ref.disableClose).toBe(true);
        keydownEvents.next(new KeyboardEvent('keydown', { key: 'Enter' }));
        expect(close).not.toHaveBeenCalled();
        keydownEvents.next(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(close).toHaveBeenLastCalledWith([blueskySent]);
        close.mockReset();
        backdropClick.next(new MouseEvent('click'));
        expect(close).toHaveBeenLastCalledWith([blueskySent]);
      });

      it('Escape or a backdrop click before anything was sent leaves the closing to the dialog', () => {
        const fixture = bothOn();
        const ref = TestBed.inject(DialogRef);

        keydownEvents.next(new KeyboardEvent('keydown', { key: 'Escape' }));
        backdropClick.next(new MouseEvent('click'));

        expect(ref.disableClose).toBeFalsy();
        expect(close).not.toHaveBeenCalled();
        fixture.destroy();
      });

      it('Escape while a send is on the wire does nothing', () => {
        const pending = new Subject<PublishedPostDto>();
        mastodonSend = () => pending;
        const fixture = bothOn();
        submit(fixture);

        keydownEvents.next(new KeyboardEvent('keydown', { key: 'Escape' }));
        backdropClick.next(new MouseEvent('click'));

        expect(close).not.toHaveBeenCalled();
        fixture.destroy();
      });

      it('a sent pill cannot be toggled', () => {
        failMastodonOnce();
        const fixture = bothOn();
        submit(fixture);

        toggle(fixture, 'Bluesky');
        toggle(fixture, 'Mastodon');

        expect(pressed(fixture)).toEqual(['true', 'false']);
        expect(sendButton(fixture).disabled).toBe(true);
      });
    });
  });

  describe('the link outside the text and the kind note', () => {
    const URL_OF_STORY = 'https://example.com/story';
    const forKind = (kind: ComposerContextDto['kind'], overrides = {}) =>
      context({
        kind,
        text: kind === 'quote' ? '' : 'A long read',
        subject: kind === 'quote' ? QUOTED : STORY,
        ...overrides,
      });
    const urlLine = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('.fixed-url');
    const note = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('.kind-note');
    const toggleMastodon = (fixture: ReturnType<typeof mount>) => {
      const pill = Array.from(element(fixture).querySelectorAll('app-network-pills button')).find(
        (button) => button.textContent?.includes('Mastodon'),
      ) as HTMLButtonElement;
      pill.click();
      fixture.detectChanges();
    };

    beforeEach(() =>
      connected.set([
        { network: 'bluesky', handles: ['@alice.bsky.social'] },
        { network: 'mastodon', handles: ['@alice@mastodon.example'] },
      ]),
    );

    it('a Mastodon link lane shows the fixed line "Mastodon adds <url>" while active', () => {
      api.context.mockReturnValue(of(forKind('link')));

      const fixture = mount(article());

      expect(urlLine(fixture)?.textContent).toContain(`Mastodon adds ${URL_OF_STORY}`);
      expect(urlLine(fixture)?.querySelector('a, button, input, [tabindex]')).toBeNull();
    });

    it('names the URL the server appends, which the card carries without tracking', () => {
      api.context.mockReturnValue(of(forKind('link')));

      const fixture = mount(article({ url: `${URL_OF_STORY}?utm_source=feed` }));

      expect(urlLine(fixture)?.textContent).toContain(`Mastodon adds ${URL_OF_STORY}`);
      expect(urlLine(fixture)?.textContent).not.toContain('utm_source');
    });

    it('a Mastodon quote lane shows no URL line', () => {
      api.context.mockReturnValue(of(forKind('quote')));

      expect(urlLine(mount(article()))).toBeNull();
    });

    it('Bluesky alone shows no URL line', () => {
      api.context.mockReturnValue(of(forKind('link', { visibility: null })));

      expect(urlLine(mount(article({ network: 'bluesky' })))).toBeNull();
    });

    it('a link post with blank text can be sent', () => {
      api.context.mockReturnValue(of(forKind('link', { text: '' })));

      expect(sendButton(mount(article())).disabled).toBe(false);
    });

    it('a blank quote cannot', () => {
      api.context.mockReturnValue(of(forKind('quote')));

      expect(sendButton(mount(article())).disabled).toBe(true);
    });

    describe('with both networks on', () => {
      const bothOn = (
        kinds: { bluesky: ComposerContextDto['kind']; mastodon: ComposerContextDto['kind'] },
        from: 'bluesky' | 'mastodon',
        text = 'A long read',
      ) => {
        api.context.mockImplementation((posted: ComposeRequest) =>
          of(
            posted.network === 'bluesky'
              ? forKind(kinds.bluesky, { visibility: null, text })
              : forKind(kinds.mastodon, { text }),
          ),
        );
        const fixture = mount(article({ network: from }));
        toggleMastodon(fixture);
        if (from === 'mastodon') {
          (
            Array.from(element(fixture).querySelectorAll('app-network-pills button')).find(
              (button) => button.textContent?.includes('Bluesky'),
            ) as HTMLButtonElement
          ).click();
          fixture.detectChanges();
        }
        return fixture;
      };

      it('names Mastodon under a Bluesky quote preview', () => {
        const fixture = bothOn({ bluesky: 'quote', mastodon: 'link' }, 'bluesky');

        expect(note(fixture)?.textContent).toContain('Mastodon shares this post as a link');
      });

      it('names Bluesky when Mastodon quotes and Bluesky links', () => {
        const fixture = bothOn({ bluesky: 'link', mastodon: 'quote' }, 'mastodon');

        expect(note(fixture)?.textContent).toContain('Bluesky shares this post as a link');
      });

      it('shows no note when the kinds match', () => {
        expect(note(bothOn({ bluesky: 'link', mastodon: 'link' }, 'bluesky'))).toBeNull();
      });

      it('shows no note with one lane', () => {
        api.context.mockReturnValue(of(forKind('link')));

        expect(note(mount(article()))).toBeNull();
      });

      it('sends blank text when every pending lane is a link', () => {
        expect(
          sendButton(bothOn({ bluesky: 'link', mastodon: 'link' }, 'bluesky', '')).disabled,
        ).toBe(false);
      });

      it('refuses blank text when a pending lane quotes', () => {
        expect(
          sendButton(bothOn({ bluesky: 'quote', mastodon: 'link' }, 'bluesky', '')).disabled,
        ).toBe(true);
      });

      it('names Bluesky when it quotes under a link preview', () => {
        const fixture = bothOn({ bluesky: 'quote', mastodon: 'link' }, 'mastodon');

        expect(note(fixture)?.textContent).toContain('Bluesky quotes this post');
        expect(note(fixture)?.textContent).not.toContain('shares this post as a link');
      });
    });
  });

  describe('the Lemmy lane', () => {
    const TITLE = 'The quiet return of the RSS reader';
    const COMMUNITIES = [
      { id: 41, handle: '!php@programming.dev' },
      { id: 42, handle: '!rss@lemmy.world' },
    ];
    let lemmyCommunities: { id: number; handle: string }[];

    const onLemmy = () =>
      context({
        kind: 'link',
        accounts: [{ id: 8, handle: '@alice@lemmy.world' }],
        accountId: 8,
        maxCharacters: 10000,
        visibility: null,
        text: '',
        subject: STORY,
        lemmy: {
          communities: lemmyCommunities,
          title: TITLE,
          minTitleCharacters: 3,
          maxTitleCharacters: 200,
        },
      });
    const onMastodon = () =>
      context({ kind: 'link', text: TITLE, subject: STORY, visibility: 'public' });

    beforeEach(() => {
      localStorage.clear();
      lemmyCommunities = COMMUNITIES;
      api.context.mockImplementation((posted: ComposeRequest) =>
        of(posted.network === 'lemmy' ? onLemmy() : onMastodon()),
      );
    });

    const lemmyOnly = () => mount(article({ network: 'lemmy' }));
    const communitySelect = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('[id^="compose-community"]') as HTMLSelectElement | null;
    const titleField = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('textarea[id^="compose-title"]') as HTMLTextAreaElement;
    const titleCounter = (fixture: ReturnType<typeof mount>) =>
      element(fixture).querySelector('.title-counter')!;
    function chooseCommunity(fixture: ReturnType<typeof mount>, id: string): void {
      communitySelect(fixture)!.value = id;
      communitySelect(fixture)!.dispatchEvent(new Event('change'));
      fixture.detectChanges();
    }
    function writeTitle(fixture: ReturnType<typeof mount>, title: string): void {
      titleField(fixture).value = title;
      titleField(fixture).dispatchEvent(new Event('input'));
      fixture.detectChanges();
    }

    it('offers the communities, first asking for one, and the title with its counter', () => {
      const fixture = lemmyOnly();

      const options = Array.from(communitySelect(fixture)!.options).map((option) =>
        option.textContent?.trim(),
      );
      expect(options).toEqual(['Choose a community', '!php@programming.dev', '!rss@lemmy.world']);
      expect(communitySelect(fixture)!.value).toBe('');
      expect(titleField(fixture).value).toBe(TITLE);
      expect(titleCounter(fixture).textContent?.trim()).toBe('34 / 200');
      expect(
        textArea(fixture).compareDocumentPosition(communitySelect(fixture)!) &
          Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy();
    });

    it('keeps Send off until a community is chosen and the title has three characters', () => {
      const fixture = lemmyOnly();
      expect(sendButton(fixture).disabled).toBe(true);

      chooseCommunity(fixture, '42');
      expect(sendButton(fixture).disabled).toBe(false);

      writeTitle(fixture, '   ');
      expect(sendButton(fixture).disabled).toBe(true);

      writeTitle(fixture, ' ab ');
      expect(sendButton(fixture).disabled).toBe(true);

      writeTitle(fixture, 'abc');
      expect(sendButton(fixture).disabled).toBe(false);
    });

    it('wraps a long title over lines but keeps Enter from breaking one', () => {
      const fixture = lemmyOnly();
      const enter = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });

      titleField(fixture).dispatchEvent(enter);

      expect(titleField(fixture).rows).toBe(2);
      expect(enter.defaultPrevented).toBe(true);
    });

    it('describes the title field by its counter', () => {
      const fixture = lemmyOnly();

      expect(titleCounter(fixture).id).toBe('compose-title-counter');
      expect(titleField(fixture).getAttribute('aria-describedby')).toBe('compose-title-counter');
    });

    it('turns the title counter red over the limit and Send off', () => {
      const fixture = lemmyOnly();
      chooseCommunity(fixture, '42');

      writeTitle(fixture, 'x'.repeat(201));

      expect(titleCounter(fixture).textContent?.trim()).toBe('201 / 200');
      expect(titleCounter(fixture).classList).toContain('over');
      expect(sendButton(fixture).disabled).toBe(true);
    });

    it('sends the chosen community and title, and preselects it next time', () => {
      const fixture = lemmyOnly();
      chooseCommunity(fixture, '42');
      writeTitle(fixture, 'My title');

      submit(fixture);

      expect(api.send).toHaveBeenCalledWith(
        expect.objectContaining({ network: 'lemmy' }),
        expect.objectContaining({ accountId: 8, title: 'My title', communityId: 42 }),
      );
      TestBed.resetTestingModule();
      const reopened = lemmyOnly();
      expect(communitySelect(reopened)!.value).toBe('42');
      expect(sendButton(reopened).disabled).toBe(false);
    });

    it('says to subscribe first instead of an empty select, and keeps Send off', () => {
      lemmyCommunities = [];
      const fixture = lemmyOnly();

      expect(communitySelect(fixture)).toBeNull();
      expect(element(fixture).textContent).toContain('Subscribe to a community on Lemmy first');
      expect(sendButton(fixture).disabled).toBe(true);
    });

    it("sits after Mastodon's block and leaves with its pill", () => {
      connected.set([
        { network: 'mastodon', handles: ['@alice@mastodon.example'] },
        { network: 'lemmy', handles: ['@alice@lemmy.world'] },
      ]);
      const fixture = mount(article());
      expect(communitySelect(fixture)).toBeNull();

      const lemmyPill = Array.from(
        element(fixture).querySelectorAll('app-network-pills button'),
      ).find((button) => button.textContent?.includes('Lemmy')) as HTMLButtonElement;
      lemmyPill.click();
      fixture.detectChanges();

      const visibility = element(fixture).querySelector('#compose-visibility-mastodon')!;
      expect(communitySelect(fixture)!.id).toBe('compose-community-lemmy');
      expect(titleField(fixture).id).toBe('compose-title-lemmy');
      expect(titleField(fixture).getAttribute('aria-describedby')).toBe(
        'compose-title-counter-lemmy',
      );
      expect(
        visibility.compareDocumentPosition(communitySelect(fixture)!) &
          Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy();
      expect(sendButton(fixture).disabled).toBe(true);
    });
  });
});
