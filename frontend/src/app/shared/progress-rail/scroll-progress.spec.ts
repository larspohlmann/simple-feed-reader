import { overflowsViewport, scrollProgress } from './scroll-progress';

describe('overflowsViewport', () => {
  it('is true for content taller than its pane', () => {
    expect(overflowsViewport(1400, 800)).toBe(true);
  });

  it('is false for content that fits', () => {
    expect(overflowsViewport(500, 800)).toBe(false);
    expect(overflowsViewport(800, 800)).toBe(false); // exactly a screenful
  });

  it('is false before the pane has been measured', () => {
    expect(overflowsViewport(500, 0)).toBe(false);
  });
});

describe('scrollProgress', () => {
  it('is empty at the top of an article', () => {
    expect(scrollProgress(0, 800, 2400)).toBe(0);
  });

  it('fills as the reader scrolls', () => {
    // 2400 of content in an 800-tall pane leaves 1600 to scroll through.
    expect(scrollProgress(400, 800, 2400)).toBe(0.25);
    expect(scrollProgress(800, 800, 2400)).toBe(0.5);
  });

  it('is full once the last line of text reaches the bottom edge', () => {
    expect(scrollProgress(1600, 800, 2400)).toBe(1);
  });

  // The reading tail adds half a viewport of padding below a long article, so the
  // scroller accepts a scrollTop well past the end of the text. The bar must stay
  // full there rather than run off its own scale.
  it('stays full while the reader scrolls on into the reading tail', () => {
    expect(scrollProgress(2000, 800, 2400)).toBe(1);
  });

  // A rubber-banded overscroll at the top reports a negative scrollTop on iOS.
  it('clamps a negative overscroll to empty', () => {
    expect(scrollProgress(-120, 800, 2400)).toBe(0);
  });

  it('reports an article that fits its pane as fully read', () => {
    expect(scrollProgress(0, 800, 500)).toBe(1);
  });

  it('reports full before the pane has been measured, rather than dividing by zero', () => {
    expect(scrollProgress(0, 0, 0)).toBe(1);
  });
});
