import { nearestScroller } from './nearest-scroller';

describe('nearestScroller', () => {
  function nest(...overflows: string[]): HTMLElement[] {
    const chain = overflows.map((overflowY) => {
      const element = document.createElement('div');
      element.style.overflowY = overflowY;
      return element;
    });
    chain.reduce((parent, child) => (parent.appendChild(child), child));
    document.body.appendChild(chain[0]);
    return chain;
  }

  afterEach(() => document.body.replaceChildren());

  it('finds the closest scrolling ancestor', () => {
    const [outer, scroller, , target] = nest('scroll', 'auto', 'visible', 'visible');
    expect(nearestScroller(target)).toBe(scroller);
    expect(nearestScroller(scroller)).toBe(outer);
  });

  it('is null when only the document scrolls', () => {
    const [, target] = nest('visible', 'hidden');
    expect(nearestScroller(target)).toBeNull();
  });
});
