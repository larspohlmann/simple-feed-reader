import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { MagazineBlockComponent } from './magazine-block.component';
import { ENTRY_KINDS, EntryKind, MagazineBlock } from './magazine-block';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { anEntry } from '../../../../testing/entry-testing';

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

  it.each(ENTRY_KINDS)('renders the headline of a %s block', (kind) => {
    expect(mount(blockOf(kind, entry())).querySelector('.title')).not.toBeNull();
  });

  it.each(ENTRY_KINDS)('renders no empty headline in a %s block', (kind) => {
    expect(mount(blockOf(kind, entry({ title: '' }))).querySelector('.title')).toBeNull();
  });
});
