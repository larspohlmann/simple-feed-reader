import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryPillsComponent } from './entry-pills.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../models';

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

function mount(tags: SubscriptionTagDto[], savedSearches: SavedSearchMembershipDto[] = []) {
  TestBed.configureTestingModule({
    imports: [EntryPillsComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryPillsComponent);
  f.componentRef.setInput('tags', tags);
  f.componentRef.setInput('savedSearches', savedSearches);
  f.detectChanges();
  return f.nativeElement as HTMLElement;
}

const pillNames = (el: HTMLElement) =>
  [...el.querySelectorAll('.pill .name')].map((name) => name.textContent);

describe('EntryPillsComponent', () => {
  it('lists the tag pills first, then the saved-search pills', () => {
    const el = mount([tag(1, 'Tech'), tag(2, 'News')], [savedSearch(5, 'climate')]);
    expect(pillNames(el)).toEqual(['Tech', 'News', 'climate']);
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
    const el = mount([tag(1, 'News')], [savedSearch(2, 'climate')]);
    const parentClick = jest.fn();
    el.addEventListener('click', parentClick);
    for (const pill of el.querySelectorAll('a.pill')) {
      pill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }
    expect(parentClick).not.toHaveBeenCalled();
  });
});
