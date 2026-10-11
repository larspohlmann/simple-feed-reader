import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { MagazineBlockComponent } from './magazine-block.component';
import { ENTRY_KINDS, EntryKind, MagazineBlock } from './magazine-block';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: '',
  excerpt: '',
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
  imageRenditions: [],
  media: [{ url: 'https://i/a.jpg', kind: 'image', width: 700, height: 400 }],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  shortPlayerUrl: null,
  titleDerived: false,
  share: null,
  linkCards: [],
  pending: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

function blockOf(kind: EntryKind, blockEntry: EntryDto): MagazineBlock {
  return kind === 'split'
    ? { kind, entry: blockEntry, imageSide: 'left' }
    : ({ kind, entry: blockEntry } as MagazineBlock);
}

function mount(block: MagazineBlock): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MagazineBlockComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: { open: jest.fn() } },
      { provide: ImageProxyService, useValue: neverRecoveringImageProxy() },
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

  it.each(ENTRY_KINDS.filter((kind) => kind !== 'media'))(
    'renders the headline of a %s block',
    (kind) => {
      expect(mount(blockOf(kind, entry())).querySelector('.title')).not.toBeNull();
    },
  );

  it.each(ENTRY_KINDS)('renders no empty headline in a %s block', (kind) => {
    expect(mount(blockOf(kind, entry({ title: '' }))).querySelector('.title')).toBeNull();
  });
});
