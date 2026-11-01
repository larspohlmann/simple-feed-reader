import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ComposerContextDto, PublishedReplyDto } from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { ReplyComposerDialogComponent, ReplyTarget } from './reply-composer-dialog.component';

const REPLY = 'https://hachyderm.io/users/ann/statuses/200';

describe('ReplyComposerDialogComponent', () => {
  const close = jest.fn();
  let api: { composer: jest.Mock; reply: jest.Mock };

  const context = (overrides: Partial<ComposerContextDto> = {}): ComposerContextDto => ({
    accounts: [{ id: 4, handle: '@alice@mastodon.example' }],
    accountId: 4,
    canReply: true,
    maxCharacters: 500,
    visibility: 'unlisted',
    mentions: ['@ann@hachyderm.io'],
    ...overrides,
  });

  const target = (overrides: Partial<ReplyTarget> = {}): ReplyTarget => ({
    entryId: 7,
    network: 'mastodon',
    address: REPLY,
    authorName: 'Ann',
    authorHandle: '@ann@hachyderm.io',
    excerpt: 'What do you think?',
    ...overrides,
  });

  function mount(replyTarget = target()) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: replyTarget },
        { provide: SocialPostApi, useValue: api },
      ],
    });
    const fixture = TestBed.createComponent(ReplyComposerDialogComponent);
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
      reply: jest.fn(() => of<PublishedReplyDto>({ address: 'https://x/1', url: null })),
    };
  });

  it('shows the post it answers and starts the text with the mentions', () => {
    const fixture = mount();

    expect(api.composer).toHaveBeenCalledWith(7, { address: REPLY, accountId: undefined });
    expect(element(fixture).querySelector('.target')!.textContent).toContain('What do you think?');
    expect(textArea(fixture).value).toBe('@ann@hachyderm.io ');
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

    expect(api.composer).toHaveBeenLastCalledWith(7, { address: REPLY, accountId: 5 });
    expect(textArea(fixture).value).toBe('Kept');
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

    expect(api.reply).toHaveBeenCalledWith(
      7,
      REPLY,
      expect.objectContaining({
        text: '@ann@hachyderm.io Agreed',
        accountId: 4,
        visibility: 'unlisted',
        idempotencyKey: expect.any(String),
      }),
    );
    expect(close).toHaveBeenCalledWith({ address: 'https://x/1', url: null });
  });

  it('keeps the text after a failed send, says why, and resends with the same key', () => {
    api.reply.mockReturnValueOnce(
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
    const keys = api.reply.mock.calls.map((call) => call[2].idempotencyKey);
    expect(keys[0]).toBe(keys[1]);
    expect(close).toHaveBeenCalled();
  });

  it('cannot send twice while a reply is on the wire', () => {
    const pending = new Subject<PublishedReplyDto>();
    api.reply.mockReturnValue(pending);
    const fixture = mount();
    type(fixture, 'Once');

    submit(fixture);
    submit(fixture);

    expect(api.reply).toHaveBeenCalledTimes(1);
  });

  it('says so and sends nothing when the author takes no replies', () => {
    api.composer.mockReturnValue(of(context({ canReply: false })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).textContent).toContain('The author limits who can reply');
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

    expect(api.reply.mock.calls[0][2]).toMatchObject({ contentWarning: 'Ending' });
  });

  it('shows Bluesky no visibility choice', () => {
    api.composer.mockReturnValue(of(context({ visibility: null, mentions: [] })));
    const fixture = mount(target({ network: 'bluesky' }));

    expect(element(fixture).querySelector('#reply-visibility')).toBeNull();
    expect(element(fixture).querySelector('.warns')).toBeNull();
  });
});
