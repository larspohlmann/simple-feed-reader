import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryDto } from '../../models';
import { anEntry } from '../../../../testing/entry-testing';
import { authorlessHomePost, holdUnrenamedHome } from '../../../../testing/feed-names-testing';

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    publishedAt: new Date(Date.now() - 5 * 60_000).toISOString(),
    source: 'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten',
    ...over,
  });

function mount(testEntry: EntryDto, inputs: Record<string, unknown> = {}, arrange?: () => void) {
  // Several cases mount twice to compare two states, and a configured TestBed
  // cannot be reconfigured once instantiated.
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryKickerLineComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  arrange?.();
  const fixture = TestBed.createComponent(EntryKickerLineComponent);
  fixture.componentRef.setInput('entry', testEntry);
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value);
  }
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('EntryKickerLineComponent', () => {
  it("names an authorless Home post by the Home's label", () => {
    const element = mount(authorlessHomePost(7), {}, () => holdUnrenamedHome(7));

    expect(element.querySelector('.source')?.textContent?.trim()).toBe('Home@bsky');
  });

  it('renders the source in full and the relative time beside it', () => {
    const element = mount(entry());

    expect(element.querySelector('.source')!.textContent).toBe(
      'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten',
    );
    expect(element.querySelector('.when')!.textContent).toContain('5');
  });

  it('links an author source out to their profile, and keeps the in-app filter otherwise', () => {
    const external = mount(
      entry({ source: 'Bob', sourceUrl: 'https://bsky.app/profile/bob', subscriptionId: 4 }),
    ).querySelector<HTMLAnchorElement>('.source')!;
    const internal = mount(entry({ subscriptionId: 4 })).querySelector<HTMLAnchorElement>(
      '.source',
    )!;

    expect(external.getAttribute('href')).toBe('https://bsky.app/profile/bob');
    expect(external.target).toBe('_blank');
    expect(external.rel).toBe('noopener noreferrer');
    expect(internal.getAttribute('href')).toContain('subscription=4');
    expect(internal.target).toBe('');
  });

  it('shows the author avatar in place of the favicon', () => {
    const element = mount(entry({ sourceImageUrl: 'https://cdn.example/bob.png' }));

    expect(element.querySelector('img.avatar')?.getAttribute('src')).toBe(
      'https://cdn.example/bob.png',
    );
  });

  it('fills the dot while the entry is unread, empties it once read', () => {
    expect(mount(entry()).querySelector('.dot')!.classList).toContain('on');
    expect(mount(entry({ isHidden: true })).querySelector('.dot')!.classList).not.toContain('on');
  });

  it('times an entry by its published date, falling back to when we ingested it', () => {
    const element = mount(entry({ publishedAt: null, createdAt: new Date().toISOString() }));

    expect(element.querySelector('.when')!.textContent).not.toBe('');
  });

  it('drops the source, its favicon and the separator when the caller suppresses it', () => {
    const element = mount(entry(), { showSource: false });

    expect(element.querySelector('.source')).toBeNull();
    expect(element.querySelector('app-favicon')).toBeNull();
    expect(element.querySelector('.separator')).toBeNull();
    expect(element.querySelector('.when')!.textContent).not.toBe('');
  });

  it('renders the read-indicator dot after the time (#486)', () => {
    const element = mount(entry());
    const when = element.querySelector('.when')!;
    const dot = element.querySelector('.dot');
    expect(dot).not.toBeNull();
    expect(when.compareDocumentPosition(dot!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('sizes the favicon at 12px by default and honours the hero override', () => {
    const withIcon = (inputs: Record<string, unknown>) =>
      mount(entry({ faviconUrl: 'https://i/f.png' }), inputs).querySelector('img.favicon')!;

    expect(withIcon({}).getAttribute('width')).toBe('12');
    expect(withIcon({ faviconSize: 14 }).getAttribute('width')).toBe('14');
  });

  // #769: a container query, not JSDOM-visible here, picks between these two
  // at render time — both are always in the DOM so the swap never refetches.
  it('renders both the wide and the narrow relative-time forms', () => {
    const element = mount(entry());
    const wide = element.querySelector('.when-wide')!;
    const narrow = element.querySelector('.when-narrow')!;
    expect(wide.textContent).toContain('5');
    expect(narrow.textContent).toContain('5');
    expect(narrow.textContent!.length).toBeLessThan(wide.textContent!.length);
  });
});
