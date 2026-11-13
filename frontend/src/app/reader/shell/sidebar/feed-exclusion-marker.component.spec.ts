import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { FeedExclusionMarkerComponent } from './feed-exclusion-marker.component';

describe('FeedExclusionMarkerComponent', () => {
  it('is an eye image titled with every surface it is given, in their order', () => {
    TestBed.configureTestingModule({
      imports: [FeedExclusionMarkerComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(FeedExclusionMarkerComponent);
    fixture.componentRef.setInput('surfaces', ['allItems', 'trending']);
    fixture.detectChanges();
    const marker = fixture.nativeElement as HTMLElement;

    expect(marker.classList).toContain('feed-exclusion-marker');
    expect(marker.getAttribute('role')).toBe('img');
    expect(marker.textContent).toContain('visibility_off');
    expect(marker.getAttribute('title')).toBe('Hidden from All items, Trending');
    expect(marker.getAttribute('aria-label')).toBe('Hidden from All items, Trending');
  });
});
