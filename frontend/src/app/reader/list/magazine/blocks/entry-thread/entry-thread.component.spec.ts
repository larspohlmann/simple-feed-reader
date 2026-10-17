import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryThreadComponent } from './entry-thread.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { LinkReaderNavigation } from '../../../../shell/link-reader-navigation.service';
import { PreviewProgress } from '../../../../link-card/preview-progress';
import { ImageProxyService } from '../../../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../../../testing/image-proxy-testing';
import { aLinkCard, aQuotedPost, anEntry } from '../../../../../../testing/entry-testing';
import { aThreadPost } from '../../../../../../testing/thread-testing';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};
const navigation = { layer: jest.fn(), openAlone: jest.fn() };

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  Object.values(navigation).forEach((spy) => spy.mockReset());
});

const thread = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    id: 41,
    excerpt: 'The root post.',
    titleDerived: true,
    share: 'link',
    linkCards: [aLinkCard({ position: 0 })],
    thread: [
      aThreadPost({ text: 'The first reply.' }),
      aThreadPost({ text: '', linkCard: aLinkCard({ url: 'https://reply.test/', position: 1 }) }),
    ],
    ...over,
  });

function mount(testEntry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [EntryThreadComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: LinkReaderNavigation, useValue: navigation },
      { provide: PreviewProgress, useValue: { isLoading: () => true } },
      { provide: ImageProxyService, useValue: neverRecoveringImageProxy() },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntryThreadComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('EntryThreadComponent', () => {
  it('draws the root and each reply on the thread line, counted n/N', () => {
    const element = mount(thread());

    expect(element.querySelectorAll('app-thread-line-post')).toHaveLength(3);
    const counts = [...element.querySelectorAll('app-thread-count')].map((count) =>
      count.textContent!.trim(),
    );
    expect(counts).toEqual(['1/3', '2/3', '3/3']);
  });

  it('puts a reply’s text and its counter in one paragraph', () => {
    const reply = mount(thread()).querySelectorAll('app-thread-line-post')[1];

    expect(reply.querySelector('p.reply')!.textContent).toContain('The first reply.');
    expect(reply.querySelector('p.reply app-thread-count')).not.toBeNull();
  });

  it('shows a reply’s card under that reply', () => {
    const reply = mount(thread()).querySelectorAll('app-thread-line-post')[2];

    expect(reply.querySelector<HTMLAnchorElement>('.link-card a')!.href).toBe(
      'https://reply.test/',
    );
  });

  it('opens a reply’s card alone in the reader by that card’s own position', () => {
    const reply = mount(thread()).querySelectorAll('app-thread-line-post')[2];

    reply.querySelector<HTMLButtonElement>('button.open-in-reader')!.click();

    expect(navigation.openAlone).toHaveBeenCalledWith({ entryId: 41, position: 1 });
    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('opens the root’s card alone in the reader by its own position', () => {
    const root = mount(thread()).querySelector('app-thread-line-post')!;

    root.querySelector<HTMLButtonElement>('button.open-in-reader')!.click();

    expect(navigation.openAlone).toHaveBeenCalledWith({ entryId: 41, position: 0 });
  });

  it('counts a root that carries only its card', () => {
    const root = mount(thread({ excerpt: '', title: '' })).querySelector('app-thread-line-post')!;

    expect(root.querySelector('.title')).toBeNull();
    expect(root.querySelector('app-thread-count')!.textContent!.trim()).toBe('1/3');
  });

  it('holds the root card’s place while the post is still being completed', () => {
    const root = mount(thread({ linkCards: [], pending: true })).querySelector(
      'app-thread-line-post',
    )!;

    expect(root.querySelector('app-link-card-placeholder')).not.toBeNull();
  });

  it('shows a media root’s own media in the root post', () => {
    const root = mount(
      thread({
        share: 'media',
        linkCards: [],
        media: [{ url: 'https://i.test/a.jpg', kind: 'image', width: 800, height: 600 }],
      }),
    ).querySelector('app-thread-line-post')!;

    expect(root.querySelector('app-post-media img')).not.toBeNull();
  });

  it('shows no lead image in a link root, whose card already carries the picture', () => {
    const root = mount(thread({ imageUrl: 'https://i.test/lead.jpg' })).querySelector(
      'app-thread-line-post',
    )!;

    expect(root.querySelector('app-post-media')).toBeNull();
  });

  it('shows a quote root’s quoted post in the root post', () => {
    const root = mount(
      thread({ share: 'quote', linkCards: [], quote: aQuotedPost() }),
    ).querySelector('app-thread-line-post')!;

    expect(root.querySelector('app-quoted-post')).not.toBeNull();
  });

  it('shows a reply’s media under that reply', () => {
    const reply = mount(
      thread({
        thread: [
          aThreadPost({
            media: [{ url: 'https://i.test/r.jpg', kind: 'image', width: 800, height: 600 }],
          }),
        ],
      }),
    ).querySelectorAll('app-thread-line-post')[1];

    expect(reply.querySelector('app-post-media img')).not.toBeNull();
  });

  it('leaves the root card out when the root has none', () => {
    const root = mount(thread({ linkCards: [] })).querySelector('app-thread-line-post')!;

    expect(root.querySelector('app-link-card')).toBeNull();
  });

  it('carries the kicker once at the top and the meta row once at the bottom', () => {
    const element = mount(thread());

    expect(element.querySelectorAll('app-entry-kicker-line')).toHaveLength(1);
    expect(element.querySelectorAll('app-entry-meta')).toHaveLength(1);
    expect(element.querySelector('app-thread-line + app-entry-meta')).not.toBeNull();
  });

  it('opens the entry from the rest of the block', () => {
    mount(thread()).querySelector<HTMLElement>('.reply')!.click();

    expect(entryActions.open).toHaveBeenCalled();
  });
});
