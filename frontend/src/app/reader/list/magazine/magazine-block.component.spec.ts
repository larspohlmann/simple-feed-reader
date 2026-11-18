import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { MagazineBlockComponent } from './magazine-block.component';
import { ENTRY_KINDS, EntryKind, MagazineBlock } from './magazine-block';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { aQuotedPost, anEntry, aStory, aStoryArticle } from '../../../../testing/entry-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { provideSocialAccounts } from '../../../../testing/social-accounts-testing';

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    summary: '',
    imageUrl: 'https://i/a.jpg',
    imageWidth: 700,
    imageHeight: 400,
    media: [{ url: 'https://i/a.jpg', kind: 'image', width: 700, height: 400 }],
    ...over,
  });

function blockOf(kind: EntryKind, blockEntry: EntryDto): MagazineBlock {
  return kind === 'split'
    ? { kind, entry: blockEntry, story: null, imageSide: 'left' }
    : ({ kind, entry: blockEntry, story: null } as MagazineBlock);
}

function mount(block: MagazineBlock): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MagazineBlockComponent, provideTranslocoTesting()],
    providers: [
      provideSocialAccounts(),
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: { open: jest.fn() } },
      { provide: ImageProxyService, useValue: neverRecoveringImageProxy() },
      { provide: LinkReaderNavigation, useValue: { openAlone: jest.fn() } },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(MagazineBlockComponent);
  fixture.componentRef.setInput('block', block);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('MagazineBlockComponent', () => {
  afterEach(() => TestBed.resetTestingModule());

  it.each(ENTRY_KINDS)('renders the headline of a %s block', (kind) => {
    expect(mount(blockOf(kind, entry())).querySelector('.title')).not.toBeNull();
  });

  it.each(ENTRY_KINDS)("shows the poster's avatar in a %s block", (kind) => {
    const avatarEntry = entry({
      poster: { name: 'Amy', url: null, imageUrl: 'https://cdn.example/amy.jpg' },
    });

    const avatar = mount(blockOf(kind, avatarEntry)).querySelector('img.avatar');

    expect(avatar?.getAttribute('src')).toBe('https://cdn.example/amy.jpg');
  });

  it.each(ENTRY_KINDS)('renders no empty headline in a %s block', (kind) => {
    expect(mount(blockOf(kind, entry({ title: '' }))).querySelector('.title')).toBeNull();
  });

  it("hands a story block's story to its widgets", () => {
    const lead = entry();
    const story = aStory({
      outletCount: 7,
      viewed: false,
      shareCount: 0,
      articles: [aStoryArticle(lead)],
    });

    const element = mount({ kind: 'hero', entry: lead, story });

    expect(element.querySelector('app-entry-hero app-story-source-count')).not.toBeNull();
  });

  it('renders a quoting block as app-entry-quoting', () => {
    const element = mount(blockOf('quoting', entry({ share: 'quote', quote: aQuotedPost() })));

    expect(element.querySelector('app-entry-quoting app-quoted-post')).not.toBeNull();
  });
});
