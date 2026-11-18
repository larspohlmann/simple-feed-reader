import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { MoreSourcesComponent } from './more-sources.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry, aStory, aStoryArticle } from '../../../../testing/entry-testing';
import { provideStoryContext } from '../../../../testing/story-context-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { EntryDto } from '../../models';
import { TrendingStoryDto } from '../../trending/trending.models';
import { leadOf } from '../../state/list-item';

function mount(entry: EntryDto, story: TrendingStoryDto | null): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MoreSourcesComponent, provideTranslocoTesting()],
    providers: [
      provideStoryContext(story).provider,
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: { open: jest.fn() } },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(MoreSourcesComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const copies = [
  aStoryArticle({ id: 2, source: 'NDR', title: 'Storm hits the coast' }),
  aStoryArticle({ id: 3, source: 'taz', title: 'Coast braces for storm' }),
];

it('renders the story sources inside a story context', () => {
  const story = aStory({ articles: [aStoryArticle(), ...copies] });
  const element = mount(leadOf(story), story);

  expect(element.querySelector('app-story-sources .more-sources')).not.toBeNull();
  expect(element.querySelector('app-entry-duplicates')).toBeNull();
});

it('renders the duplicates footer without one', () => {
  const element = mount(anEntry({ duplicates: copies }), null);

  expect(element.querySelector('app-story-sources')).toBeNull();
  expect(element.querySelector('.more-sources')).toBeNull();
  expect(element.querySelector('app-entry-duplicates .lead')!.textContent).toContain('Also in');
  expect(element.querySelectorAll('app-entry-duplicates .also-entry').length).toBe(2);
});

it('renders nothing without other sources', () => {
  const element = mount(anEntry(), null);

  expect(element.querySelector('app-story-sources, app-entry-duplicates')).toBeNull();
});
