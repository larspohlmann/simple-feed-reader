import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryQuotingComponent } from './entry-quoting.component';
import { EntryDto } from '../../../../models';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { aQuotedPost, anEntry } from '../../../../../../testing/entry-testing';

const entryActions = { open: jest.fn() };

beforeEach(() => entryActions.open.mockReset());

const quoting = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    url: 'https://bsky.app/profile/someone/post/1',
    excerpt: 'My take on this',
    titleDerived: true,
    share: 'quote',
    quote: aQuotedPost({ text: 'What they said' }),
    ...over,
  });

function mount(entry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [EntryQuotingComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryQuotingComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

afterEach(() => TestBed.resetTestingModule());

describe('EntryQuotingComponent', () => {
  // Red if: the headline stops rendering as a post.
  it('renders the post text as the headline', () => {
    const title = mount(quoting()).querySelector('.title');

    expect(title?.textContent).toContain('My take on this');
    expect(title?.classList).toContain('post');
  });

  // Red if: the quoted post is not rendered, or not in block layout.
  it('nests the quoted post in its block layout', () => {
    const element = mount(quoting());

    expect(element.querySelector('app-quoted-post.block')?.textContent).toContain('What they said');
  });

  // Red if: the media guard is dropped.
  it('renders no media for a post without any of its own', () => {
    expect(mount(quoting()).querySelector(':scope > article > app-post-media')).toBeNull();
  });

  // Red if: the post's own media is not passed to app-post-media.
  it('renders the post’s own media above the quoted post', () => {
    const element = mount(
      quoting({
        media: [{ url: 'https://cdn.test/a.jpg', kind: 'image', width: 800, height: 600 }],
      }),
    );

    expect(element.querySelector(':scope > article > app-post-media')).not.toBeNull();
  });

  // Red if: the card stops opening the entry.
  it('opens the entry from a click on the card', () => {
    mount(quoting()).querySelector<HTMLElement>('.title')!.click();

    expect(entryActions.open).toHaveBeenCalledTimes(1);
  });

  // Red if: the quoted box stops swallowing the click.
  it('does not open the entry from a click on the quoted box', () => {
    mount(quoting()).querySelector<HTMLElement>('app-quoted-post .text')!.click();

    expect(entryActions.open).not.toHaveBeenCalled();
  });
});
