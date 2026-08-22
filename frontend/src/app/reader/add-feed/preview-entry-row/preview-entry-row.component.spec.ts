import { ComponentRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { PreviewEntryRowComponent } from './preview-entry-row.component';
import { FeedPreviewItem } from '../../models';

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

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [PreviewEntryRowComponent, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(PreviewEntryRowComponent);
    ref = fixture.componentRef;
    ref.setInput('item', item());
    ref.setInput('source', 'The Verge');
  });

  it('renders title, source, snippet and the https thumbnail', () => {
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('A sample headline');
    expect(el.querySelector('.meta')!.textContent).toContain('The Verge');
    expect(el.querySelector('.snippet')!.textContent).toContain('A short snippet');
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img.example/a.jpg');
  });

  it('omits the thumbnail when there is no image', () => {
    ref.setInput('item', item({ imageUrl: null, imageWidth: null, imageHeight: null }));
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('img.thumb')).toBeNull();
  });

  it('is inert: no button role and no action buttons', () => {
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('[role="button"]')).toBeNull();
    expect(el.querySelector('app-entry-actions')).toBeNull();
    expect(el.querySelector('button')).toBeNull();
  });
});
