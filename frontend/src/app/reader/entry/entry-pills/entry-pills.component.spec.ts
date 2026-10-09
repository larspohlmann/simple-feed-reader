import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryPillsComponent } from './entry-pills.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../../models';

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

const savedSearch = (id: number, term: string): SavedSearchMembershipDto => ({
  id,
  slug: `${id}-${term}`,
  term,
});

function mount(
  tags: SubscriptionTagDto[],
  savedSearches: SavedSearchMembershipDto[] = [],
  short = false,
) {
  TestBed.configureTestingModule({
    imports: [EntryPillsComponent, provideTranslocoTesting()],
    providers: [provideRouter([{ path: '**', children: [] }])],
  });
  const fixture = TestBed.createComponent(EntryPillsComponent);
  fixture.componentRef.setInput('tags', tags);
  fixture.componentRef.setInput('savedSearches', savedSearches);
  fixture.componentRef.setInput('short', short);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const pillNames = (element: HTMLElement) =>
  [...element.querySelectorAll('.pill .name')].map((name) => name.textContent);

describe('EntryPillsComponent', () => {
  it('lists the tag pills first, then the saved-search pills', () => {
    const element = mount([tag(1, 'Tech'), tag(2, 'News')], [savedSearch(5, 'climate')]);
    expect(pillNames(element)).toEqual(['Tech', 'News', 'climate']);
  });

  it('leads with a Short pill, as plain text rather than a link, when asked to', () => {
    const element = mount([tag(1, 'Tech')], [], true);
    const first = element.querySelector('.pill')!;

    expect(first.classList).toContain('short');
    expect(first.tagName).toBe('SPAN');
    expect(first.textContent?.trim()).toBe('Short');
  });

  it('shows no Short pill by default', () => {
    expect(mount([tag(1, 'Tech')]).querySelector('.pill.short')).toBeNull();
  });

  it('renders a lone Short pill without being given tags', () => {
    TestBed.configureTestingModule({
      imports: [EntryPillsComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const fixture = TestBed.createComponent(EntryPillsComponent);
    fixture.componentRef.setInput('short', true);
    fixture.detectChanges();

    const pills = (fixture.nativeElement as HTMLElement).querySelectorAll('.pill');
    expect([...pills].map((pill) => pill.className)).toEqual(['pill short']);
  });

  it('renders no pill when there are neither tags nor saved searches', () => {
    expect(mount([], []).querySelector('.pill')).toBeNull();
  });

  it('links each tag pill to its tag filter', () => {
    const href = mount([tag(5, 'News')])
      .querySelector('a.pill')!
      .getAttribute('href');
    expect(href).toContain('tag=5');
  });

  it('links each saved-search pill to its slug path', () => {
    const pill = mount([], [savedSearch(2, 'climate')]).querySelector('a.pill.saved-search')!;
    expect(pill.getAttribute('href')).toContain('/searches/saved/2-climate');
  });

  it('stops a pill click from bubbling so the parent entry does not open', () => {
    const element = mount([tag(1, 'News')], [savedSearch(2, 'climate')]);
    const parentClick = jest.fn();
    element.addEventListener('click', parentClick);
    for (const pill of element.querySelectorAll('a.pill')) {
      pill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }
    expect(parentClick).not.toHaveBeenCalled();
  });
});
