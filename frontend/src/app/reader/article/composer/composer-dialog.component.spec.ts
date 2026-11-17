import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  ComposerCardDto,
  ComposerContextDto,
  LinkComposerSubjectDto,
  LinkTargetDto,
  PostComposerSubjectDto,
  PostTargetDto,
  PublishedPostDto,
  ResponseTargetDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { PostNetwork, PostNetworks } from '../share/post-networks.service';
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
  const connected = signal<PostNetwork[]>([]);
  let api: { composer: jest.Mock; send: jest.Mock };

  const context = (overrides: Partial<ComposerContextDto> = {}): ComposerContextDto => ({
    kind: 'reply',
    accounts: [{ id: 4, handle: '@alice@mastodon.example' }],
    accountId: 4,
    canSend: true,
    maxCharacters: 500,
    visibility: 'unlisted',
    text: '@ann@hachyderm.io ',
    target: QUOTED,
    ...overrides,
  });

  const target = (overrides: Partial<ResponseTargetDto> = {}): ResponseTargetDto => ({
    kind: 'reply',
    entryId: 7,
    network: 'mastodon',
    address: REPLY,
    ...overrides,
  });

  const article = (overrides: Partial<LinkTargetDto> = {}): LinkTargetDto => ({
    kind: 'link',
    network: 'mastodon',
    url: 'https://example.com/story',
    title: 'A long read',
    quote: null,
    ...overrides,
  });

  function mount(replyTarget: PostTargetDto = target()) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: replyTarget },
        { provide: SocialPostApi, useValue: api },
        { provide: PostNetworks, useValue: { connected } },
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
    connected.set([]);
    api = {
      composer: jest.fn(() => of(context())),
      send: jest.fn(() => of<PublishedPostDto>({ address: 'https://x/1', url: null })),
    };
  });

  it('shows the post it answers and starts the text where the server says', () => {
    const fixture = mount();

    expect(api.composer).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'reply', entryId: 7, address: REPLY }),
      undefined,
    );
    const answered = element(fixture).querySelector('.target')!;
    expect(answered.querySelector('.handle')!.textContent).toContain('@ann@hachyderm.io');
    expect(answered.querySelector('.excerpt')!.textContent).toContain('What do you think?');
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
    api.composer.mockReturnValue(of(context({ text: '', maxCharacters: 5 })));
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
        target: QUOTED,
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
    api.composer.mockReturnValue(of(context({ canSend: false })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).textContent).toContain('The author limits who can reply');
    expect(sendButton(fixture).disabled).toBe(true);
  });

  describe('replying on Lemmy', () => {
    const lemmy = () => target({ network: 'lemmy', address: 'https://lemmy.example/comment/12' });

    it('heads the reply for Lemmy and shows no visibility row', () => {
      api.composer.mockReturnValue(
        of(context({ accounts: [{ id: 9, handle: '@lars@lemmy.example' }], visibility: null })),
      );
      const fixture = mount(lemmy());

      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Reply on Lemmy');
      expect(element(fixture).querySelector('#reply-visibility')).toBeNull();
    });

    it('says a locked post cannot be replied to', () => {
      api.composer.mockReturnValue(of(context({ canSend: false, visibility: null })));
      const fixture = mount(lemmy());

      expect(element(fixture).querySelector('[role="alert"]')?.textContent).toContain(
        'This post is locked',
      );
      expect(textArea(fixture).disabled).toBe(true);
    });
  });

  it('writes a quote under its own heading and sends it as a quote', () => {
    api.composer.mockReturnValue(of(context({ kind: 'quote', text: '' })));
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
    expect(close).toHaveBeenCalledWith([expect.objectContaining({ address: 'https://x/1' })]);
  });

  it('says so and sends nothing when the author keeps the post from quotes', () => {
    api.composer.mockReturnValue(of(context({ kind: 'quote', canSend: false, text: '' })));
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
    api.composer.mockReturnValue(of(context({ visibility: null, text: '' })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).querySelector('#reply-visibility')).toBeNull();
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
        target: STORY,
        ...overrides,
      });
    const quoteContext = (overrides: Partial<ComposerContextDto> = {}) =>
      linkContext({ kind: 'quote', text: '', target: QUOTED, ...overrides });

    beforeEach(() => api.composer.mockReturnValue(of(linkContext())));

    it('puts the caret before the quote so a comment can go first', () => {
      api.composer.mockReturnValue(
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
      api.composer.mockReturnValue(slow);
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
      expect(api.composer).toHaveBeenCalledWith(
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
      api.composer.mockReturnValue(of(quoteContext()));
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
      api.composer.mockReturnValue(of(quoteContext({ target: { ...QUOTED, card } })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));

      const quotedCard = element(fixture).querySelector('.target .article')!;
      expect(quotedCard.querySelector('.title')!.textContent).toContain('Senate passes the bill');
      expect(quotedCard.querySelector('.site')!.textContent).toContain('politico.com');
      expect(quotedCard.querySelector('img')!.getAttribute('src')).toContain('thumb.jpg');
    });

    it('sends the quote of a post on its own network to the link endpoint, closing with it shown', () => {
      api.composer.mockReturnValue(of(quoteContext()));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));
      type(fixture, 'Look');

      submit(fixture);

      expect(api.send).toHaveBeenCalledWith(
        expect.objectContaining({ kind: 'link', url: 'https://mastodon.social/@ann/110' }),
        expect.objectContaining({ text: 'Look' }),
      );
      expect(close).toHaveBeenCalledWith([
        expect.objectContaining({ kind: 'quote', network: 'mastodon', target: QUOTED }),
      ]);
    });

    it('shows a card without an image or site when the page has none', () => {
      api.composer.mockReturnValue(
        of(
          linkContext({
            target: { kind: 'link', card: { ...STORY_CARD, imageUrl: null, siteName: '' } },
          }),
        ),
      );
      const fixture = mount(article());

      expect(element(fixture).querySelector('.article img')).toBeNull();
      expect(element(fixture).querySelector('.article .site')).toBeNull();
    });

    it('says so when the post it becomes a quote of takes no quotes', () => {
      api.composer.mockReturnValue(of(quoteContext({ canSend: false })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));

      expect(element(fixture).textContent).toContain('You can’t quote this post');
      expect(sendButton(fixture).disabled).toBe(true);
    });

    it('uses the starting text once, keeping what the reader wrote over an account switch', () => {
      api.composer.mockReturnValue(of(linkContext({ accounts })));
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

    it('keeps the quote heading while an account switch reloads the context', () => {
      api.composer.mockReturnValue(of(quoteContext({ accounts })));
      const fixture = mount(article({ url: 'https://mastodon.social/@ann/110' }));
      api.composer.mockReturnValue(new Subject<ComposerContextDto>());
      const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(element(fixture).querySelector('h1, h2')?.textContent).toContain('Quote on Mastodon');
    });

    it('keeps what the reader wrote when a later context quotes', () => {
      api.composer.mockReturnValue(of(linkContext({ accounts })));
      const fixture = mount(article());
      type(fixture, 'Mine');
      api.composer.mockReturnValue(of(quoteContext({ accounts })));
      const select = element(fixture).querySelector('#reply-account') as HTMLSelectElement;
      select.value = '5';
      select.dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(textArea(fixture).value).toBe('Mine');
    });

    it('sends the post as a link post', () => {
      api.composer.mockReturnValue(of(linkContext({ visibility: null, text: 'A long read' })));
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
        expect.objectContaining({ address: 'https://x/1', kind: 'link', target: STORY }),
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
      context({ kind: 'link', text: TITLE, target: STORY, ...overrides });
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
      api.composer.mockImplementation((posted: PostTargetDto) =>
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
      api.composer.mock.calls.filter(([posted]) => posted.network === network);
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
      mastodonContext = () => of(onMastodon({ kind: 'quote', target: QUOTED }));
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
      expect(element(fixture).textContent).toContain('Post as on Mastodon');
      toggle(fixture, 'Bluesky');
      expect(element(fixture).textContent).not.toContain('Post as on Mastodon');
      expect(element(fixture).textContent).toContain('Post as');
    });

    it("switching account on Mastodon leaves Bluesky's load alone", () => {
      blueskyContext = () => of(onBluesky({ accounts: blueskyAccounts }));
      mastodonContext = () => of(onMastodon({ accounts: mastodonAccounts }));
      const fixture = fromBluesky();
      toggle(fixture, 'Mastodon');
      const slow = new Subject<ComposerContextDto>();
      blueskyContext = () => slow;
      choose(fixture, '#reply-account-bluesky', '2');

      choose(fixture, '#reply-account-mastodon', '5');

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
      choose(fixture, '#reply-visibility-mastodon', 'private');
      const box = element(fixture).querySelector('.warns input') as HTMLInputElement;
      box.checked = true;
      box.dispatchEvent(new Event('change'));
      fixture.detectChanges();
      const warning = element(fixture).querySelector('#reply-warning-mastodon') as HTMLInputElement;
      warning.value = 'Ending';
      warning.dispatchEvent(new Event('input'));

      toggle(fixture, 'Mastodon');

      expect(element(fixture).querySelector('#reply-visibility-mastodon')).toBeNull();
      expect(element(fixture).querySelector('.warns')).toBeNull();
      submit(fixture);
      expect(api.send.mock.calls[0][1]).not.toHaveProperty('contentWarning');
      expect(api.send.mock.calls[0][1]).not.toHaveProperty('visibility');
      close.mockReset();
      toggle(fixture, 'Mastodon');
      const visibility = element(fixture).querySelector(
        '#reply-visibility-mastodon',
      ) as HTMLSelectElement;
      expect(visibility.value).toBe('private');
      expect(
        (element(fixture).querySelector('#reply-warning-mastodon') as HTMLInputElement).value,
      ).toBe('Ending');
    });
  });
});
