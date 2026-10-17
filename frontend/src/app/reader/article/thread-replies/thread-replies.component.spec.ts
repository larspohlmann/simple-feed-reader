import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aLinkCard, anEntry } from '../../../../testing/entry-testing';
import { aThreadPost } from '../../../../testing/thread-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { EntryDto } from '../../models';
import { ThreadRepliesComponent } from './thread-replies.component';

const navigation = { layer: jest.fn() };

beforeEach(() => navigation.layer.mockReset());

function mount(testEntry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [ThreadRepliesComponent, provideTranslocoTesting()],
    providers: [
      { provide: LinkReaderNavigation, useValue: navigation },
      { provide: ImageProxyService, useValue: neverRecoveringImageProxy() },
    ],
  });
  const fixture = TestBed.createComponent(ThreadRepliesComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('ThreadRepliesComponent', () => {
  it('renders each reply’s html on the thread line, counted from 2', () => {
    const element = mount(
      anEntry({
        thread: [
          aThreadPost({ html: '<p>First reply</p>' }),
          aThreadPost({ html: '<p>Second reply</p>' }),
        ],
      }),
    );

    const bodies = [...element.querySelectorAll('app-thread-line-post .body')];
    expect(bodies.map((body) => body.textContent!.trim())).toEqual(['First reply', 'Second reply']);
    const counts = [...element.querySelectorAll('app-thread-count')].map((count) =>
      count.textContent!.trim(),
    );
    expect(counts).toEqual(['2/3', '3/3']);
  });

  it('opens a reply’s card in the reader layer by that card’s stored position', () => {
    const element = mount(
      anEntry({
        id: 77,
        thread: [
          aThreadPost({}),
          aThreadPost({ linkCard: aLinkCard({ url: 'https://reply.test/', position: 4 }) }),
        ],
      }),
    );

    element.querySelector<HTMLButtonElement>('button.open-in-reader')!.click();

    expect(navigation.layer).toHaveBeenCalledWith({ entryId: 77, position: 4 });
  });

  it('renders a reply’s media', () => {
    const element = mount(
      anEntry({
        thread: [
          aThreadPost({
            media: [{ url: 'https://cdn.test/p.jpg', kind: 'image', width: 800, height: 600 }],
          }),
          aThreadPost({}),
        ],
      }),
    );

    expect(element.querySelectorAll('app-post-media')).toHaveLength(1);
  });
});
