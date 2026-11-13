import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ExclusionSurface } from '../../reader/feed-exclusions';
import { VisibilityCell, VisibilityRowComponent } from './visibility-row.component';

const CELLS: readonly VisibilityCell[] = [
  { surface: 'allItems', checked: true, inheritedFrom: ['Bluesky', 'Tag A'] },
  { surface: 'forYou', checked: false, inheritedFrom: [] },
  { surface: 'savedSearches', checked: true, inheritedFrom: [] },
];

function render(expanded?: boolean) {
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(VisibilityRowComponent);
  fixture.componentRef.setInput('name', 'heise');
  fixture.componentRef.setInput('cells', CELLS);
  fixture.componentRef.setInput('expanded', expanded);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const boxes = [...element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')];
  return { fixture, element, boxes };
}

describe('VisibilityRowComponent', () => {
  it('names each box by its row and its column', () => {
    const { boxes } = render();

    expect(boxes.map((box) => box.getAttribute('aria-label'))).toEqual([
      'heise: All items',
      'heise: For you',
      'heise: Saved searches',
    ]);
  });

  it('checks each box from its own switch', () => {
    const { boxes } = render();

    expect(boxes.map((box) => box.checked)).toEqual([true, false, true]);
  });

  it('dims an inherited cell and names its causes', () => {
    const { element } = render();
    const cells = [...element.querySelectorAll<HTMLElement>('.cell')];

    expect(cells[0].classList).toContain('inherited');
    expect(cells[0].getAttribute('title')).toBe('Excluded by Bluesky, Tag A');
    expect(cells[0].querySelector('app-icon')).not.toBeNull();
    expect(cells[1].classList).not.toContain('inherited');
    expect(cells[1].getAttribute('title')).toBeNull();
  });

  it('never disables a box, an inherited one included', () => {
    const { boxes } = render();

    expect(boxes.some((box) => box.disabled)).toBe(false);
  });

  it('reports the surface of the box ticked', () => {
    const { fixture, boxes } = render();
    const toggled: ExclusionSurface[] = [];
    fixture.componentInstance.toggled.subscribe((surface) => toggled.push(surface));

    boxes[0].click();

    expect(toggled).toEqual(['allItems']);
  });

  it('shows no chevron on a row with nothing beneath it', () => {
    const { element } = render();

    expect(element.querySelector('[data-test="expand"]')).toBeNull();
  });

  it('reports a tap on the chevron of a parent row', () => {
    const { fixture, element } = render(true);
    let toggles = 0;
    fixture.componentInstance.expandToggled.subscribe(() => toggles++);
    const chevron = element.querySelector<HTMLButtonElement>('[data-test="expand"]')!;

    chevron.click();

    expect(chevron.getAttribute('aria-expanded')).toBe('true');
    expect(toggles).toBe(1);
  });
});
