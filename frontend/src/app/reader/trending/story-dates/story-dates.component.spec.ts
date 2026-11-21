import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { formatLongDateTime } from '../../format';
import { StoryDatesComponent } from './story-dates.component';

const now = new Date('2026-11-21T12:00:00Z');
const twoDaysAgo = '2026-11-19T12:00:00+00:00';
const threeHoursAgo = '2026-11-21T09:00:00+00:00';

function mount(createdAt: string, lastAddedAt: string): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StoryDatesComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(StoryDatesComponent);
  fixture.componentRef.setInput('story', { createdAt, lastAddedAt });
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

beforeEach(() => {
  jest.useFakeTimers();
  jest.setSystemTime(now);
});

afterEach(() => jest.useRealTimers());

it('shows created and last article', () => {
  const element = mount(twoDaysAgo, threeHoursAgo);

  expect(element.textContent!.replace(/\s+/g, ' ')).toMatch(/created .+ ago · last article .+ ago/);
  expect(element.querySelector('.created')!.getAttribute('title')).toBe(
    formatLongDateTime(twoDaysAgo),
  );
  expect(element.querySelector('.last-added')!.getAttribute('title')).toBe(
    formatLongDateTime(threeHoursAgo),
  );
});

it('shows only created when the story never grew', () => {
  const element = mount(twoDaysAgo, twoDaysAgo);

  expect(element.textContent).toContain('created');
  expect(element.textContent).not.toContain('last article');
  expect(element.querySelector('.separator')).toBeNull();
});
