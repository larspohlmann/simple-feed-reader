import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SavedSearchPillsComponent } from './saved-search-pills.component';

describe('SavedSearchPillsComponent', () => {
  let fixture: ComponentFixture<SavedSearchPillsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SavedSearchPillsComponent],
      providers: [provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(SavedSearchPillsComponent);
  });

  it('renders one pill per membership, linked to the slug path', () => {
    fixture.componentRef.setInput('memberships', [
      { id: 2, slug: '2-climate', term: 'climate' },
      { id: 1, slug: '1-sport', term: 'sport' },
    ]);
    fixture.detectChanges();
    const links = fixture.nativeElement.querySelectorAll('a.pill');
    expect(links.length).toBe(2);
    expect(links[0].getAttribute('href')).toContain('/searches/saved/2-climate');
    expect(links[0].textContent).toContain('climate');
  });

  it('renders nothing when there are no memberships', () => {
    fixture.componentRef.setInput('memberships', []);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('a.pill')).toBeNull();
  });
});
