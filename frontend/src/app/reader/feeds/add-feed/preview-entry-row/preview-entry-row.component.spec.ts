import { ComponentRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../../testing/transloco-testing';
import { PreviewEntryRowComponent } from './preview-entry-row.component';
import { FeedPreviewItem } from '../../../models';
import { ImageProxyService } from '../../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../../testing/image-proxy-testing';

function item(over: Partial<FeedPreviewItem> = {}): FeedPreviewItem {
  return {
    title: 'A sample headline',
    url: 'https://example.com/a',
    author: null,
    summary: 'A short snippet of the article body.',
    imageUrl: 'https://img.example/a.jpg',
    imageWidth: 800,
    imageHeight: 600,
    publishedAt: '2026-08-20T10:00:00+00:00',
    ...over,
  };
}

describe('PreviewEntryRowComponent', () => {
  let fixture: ComponentFixture<PreviewEntryRowComponent>;
  let ref: ComponentRef<PreviewEntryRowComponent>;
  let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;

  beforeEach(() => {
    imageProxy = neverRecoveringImageProxy();
    TestBed.configureTestingModule({
      imports: [PreviewEntryRowComponent, provideTranslocoTesting()],
      providers: [{ provide: ImageProxyService, useValue: imageProxy }],
    });
    fixture = TestBed.createComponent(PreviewEntryRowComponent);
    ref = fixture.componentRef;
    ref.setInput('item', item());
    ref.setInput('source', 'The Verge');
  });

  it('renders title, source, snippet and the https thumbnail', () => {
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.title')!.textContent).toContain('A sample headline');
    expect(element.querySelector('.meta')!.textContent).toContain('The Verge');
    expect(element.querySelector('.snippet')!.textContent).toContain('A short snippet');
    expect(element.querySelector('img.thumb')!.getAttribute('src')).toBe(
      'https://img.example/a.jpg',
    );
  });

  it('omits the thumbnail when there is no image', () => {
    ref.setInput('item', item({ imageUrl: null, imageWidth: null, imageHeight: null }));
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('img.thumb')).toBeNull();
  });

  it('hides a thumbnail that fails to load, without a proxy retry', () => {
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector('img.thumb')!.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(element.querySelector('img.thumb')).toBeNull();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('is inert: no button role and no action buttons', () => {
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('[role="button"]')).toBeNull();
    expect(element.querySelector('app-entry-actions')).toBeNull();
    expect(element.querySelector('button')).toBeNull();
  });
});
