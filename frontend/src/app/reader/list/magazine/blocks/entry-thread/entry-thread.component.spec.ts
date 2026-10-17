import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryThreadComponent } from './entry-thread.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { LinkReaderNavigation } from '../../../../shell/link-reader-navigation.service';
import { aLinkCard, anEntry } from '../../../../../../testing/entry-testing';
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
      aThreadPost({ text: '', linkCard: aLinkCard({ url: 'https://reply.test/', position: 0 }) }),
    ],
    ...over,
  });

function mount(testEntry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [EntryThreadComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: LinkReaderNavigation, useValue: navigation },
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

    expect(navigation.openAlone).toHaveBeenCalledWith({ entryId: 41, position: 0 });
    expect(entryActions.open).not.toHaveBeenCalled();
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
