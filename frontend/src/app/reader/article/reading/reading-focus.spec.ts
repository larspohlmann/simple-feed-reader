import { articleHost } from '../../../../testing/article-host-testing';
import {
  type FocusCurve,
  LIST_FOCUS_CURVE,
  focusOpacityForSpan,
  needsReadingTail,
  readingBlocks,
} from './reading-focus';

describe('needsReadingTail', () => {
  it('gives an article taller than the viewport room to scroll on', () => {
    expect(needsReadingTail(1400, 800)).toBe(true);
  });

  it('leaves an article that fits alone, so it gains no dead scroll', () => {
    expect(needsReadingTail(500, 800)).toBe(false);
    expect(needsReadingTail(800, 800)).toBe(false); // exactly a screenful
  });

  it('is false before the pane has been measured', () => {
    expect(needsReadingTail(500, 0)).toBe(false);
  });
});

describe('focusOpacityForSpan', () => {
  // Viewport 1000 => the band holds 300–500; the fade runs over 300px above it
  // and 500px below it, reaching the floor at each viewport edge.

  describe('a short block (top and bottom coincide)', () => {
    it('is fully opaque anywhere in the band, to its very edges', () => {
      expect(focusOpacityForSpan({ top: 300, bottom: 300 }, 1000)).toBe(1);
      expect(focusOpacityForSpan({ top: 400, bottom: 400 }, 1000)).toBe(1);
      expect(focusOpacityForSpan({ top: 500, bottom: 500 }, 1000)).toBe(1);
    });

    it('fades to the minimum at each viewport edge', () => {
      expect(focusOpacityForSpan({ top: 0, bottom: 0 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
      expect(focusOpacityForSpan({ top: 1000, bottom: 1000 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
    });

    it('fades over the space on its own side of the band', () => {
      expect(focusOpacityForSpan({ top: 150, bottom: 150 }, 1000)).toBe(0.6); // halfway up: 1 - 0.5 * 0.8
      expect(focusOpacityForSpan({ top: 750, bottom: 750 }, 1000)).toBe(0.6); // halfway down
    });

    it('fades monotonically with distance from the band', () => {
      const near = focusOpacityForSpan({ top: 600, bottom: 600 }, 1000);
      const far = focusOpacityForSpan({ top: 800, bottom: 800 }, 1000);
      expect(near).toBeGreaterThan(far);
      expect(near).toBeLessThan(1);
    });

    it('clamps blocks past the viewport edges to the minimum, never below', () => {
      expect(focusOpacityForSpan({ top: -500, bottom: -500 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
      expect(focusOpacityForSpan({ top: 5000, bottom: 5000 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
    });
  });

  it('keeps a block fully opaque while its span reaches into the band', () => {
    expect(focusOpacityForSpan({ top: -400, bottom: 350 }, 1000)).toBe(1);
    expect(focusOpacityForSpan({ top: 450, bottom: 2000 }, 1000)).toBe(1);
    expect(focusOpacityForSpan({ top: 0, bottom: 5000 }, 1000)).toBe(1);
  });

  it('fades a block clear of the band by its nearest edge, not its centre', () => {
    expect(focusOpacityForSpan({ top: 600, bottom: 1600 }, 1000)).toBe(0.84); // 1 - (100/500)*(1 - 0.2)
    expect(focusOpacityForSpan({ top: -1000, bottom: 240 }, 1000)).toBe(0.84); // 1 - (60/300)*(1 - 0.2)
  });

  it('fades to the minimum once the nearest edge reaches the viewport edge', () => {
    expect(focusOpacityForSpan({ top: 1000, bottom: 2000 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
    expect(focusOpacityForSpan({ top: -2000, bottom: 0 }, 1000)).toBe(LIST_FOCUS_CURVE.min);
  });

  it('degrades to fully opaque when the viewport has no measured height', () => {
    expect(focusOpacityForSpan({ top: 0, bottom: 400 }, 0)).toBe(1);
  });
});

describe('readingBlocks', () => {
  it('returns direct children when the content is a flat block list', () => {
    const blocks = readingBlocks(articleHost('<p>a</p><h2>b</h2><p>c</p>'));
    expect(blocks.map((block) => block.tagName)).toEqual(['P', 'H2', 'P']);
  });

  it('descends through a single wrapper chain to the real block level', () => {
    // Mirrors the extracted-article shape: div > article > p, h2, p …
    const blocks = readingBlocks(
      articleHost('<div><article><p>a</p><h2>b</h2><p>c</p></article></div>'),
    );
    expect(blocks.map((block) => block.tagName)).toEqual(['P', 'H2', 'P']);
  });

  it('does not descend into a leaf paragraph that only wraps inline content', () => {
    const blocks = readingBlocks(articleHost('<p>hello <a href="#">link</a></p>'));
    expect(blocks.map((block) => block.tagName)).toEqual(['P']);
  });

  it('stops at the first level holding multiple blocks', () => {
    const blocks = readingBlocks(articleHost('<div><p>only</p></div>'));
    expect(blocks.map((block) => block.tagName)).toEqual(['P']);
  });

  // #109: the real shape readability produced for the reported wired.com
  // article. The paragraphs sit two levels below the first branching level, so
  // taking that level's children made the whole article two blocks — nothing
  // ever highlighted, then seventeen paragraphs at once.
  it('reaches paragraphs that sit below several branching wrappers', () => {
    const blocks = readingBlocks(
      articleHost(`<div class="page"><div>
              <div><figure><img></figure><div><p>a</p><p>b</p><h2>c</h2><p>d</p></div></div>
              <div><p>e</p><h2>f</h2><p>g</p></div>
            </div></div>`),
    );
    expect(blocks.map((block) => block.tagName)).toEqual([
      'FIGURE',
      'P',
      'P',
      'H2',
      'P',
      'P',
      'H2',
      'P',
    ]);
  });

  it('keeps a list, a quote and a figure as one block each', () => {
    const blocks = readingBlocks(
      articleHost(
        '<div><ul><li>a</li><li>b</li></ul><blockquote><p>q</p></blockquote>' +
          '<figure><img><figcaption>cap</figcaption></figure></div>',
      ),
    );
    expect(blocks.map((block) => block.tagName)).toEqual(['UL', 'BLOCKQUOTE', 'FIGURE']);
  });

  it('treats a wrapper holding only inline content as the paragraph itself', () => {
    const blocks = readingBlocks(
      articleHost('<div><div>text with <a href="#">a link</a></div></div>'),
    );
    expect(blocks.map((block) => block.tagName)).toEqual(['DIV']);
    expect(blocks[0].textContent).toContain('text with');
  });

  it('stops descending at a bounded depth', () => {
    const deep = '<div>'.repeat(40) + '<p>bottom</p>' + '</div>'.repeat(40);
    const blocks = readingBlocks(articleHost(deep));
    expect(blocks.length).toBeGreaterThan(0); // terminates, and returns something to fade
  });
});

describe('a curve with a step', () => {
  // Viewport 1000 => a band from 300 to 500, a step down to 0.6 at its edges, and
  // the remaining 300px above and 500px below fading on to 0.2.
  const stepped: FocusCurve = {
    band: { top: 0.3, bottom: 0.5 },
    falloff: 0.4,
    curvature: 1,
    min: 0.2,
  };

  it('holds full opacity across the band, to its very edges', () => {
    expect(focusOpacityForSpan({ top: 300, bottom: 300 }, 1000, stepped)).toBe(1);
    expect(focusOpacityForSpan({ top: 400, bottom: 400 }, 1000, stepped)).toBe(1);
    expect(focusOpacityForSpan({ top: 500, bottom: 500 }, 1000, stepped)).toBe(1);
  });

  it('drops by the falloff the moment a block leaves the band', () => {
    expect(focusOpacityForSpan({ top: 501, bottom: 501 }, 1000, stepped)).toBe(0.599);
    expect(focusOpacityForSpan({ top: 299, bottom: 299 }, 1000, stepped)).toBe(0.599);
  });

  it('runs the curve from the stepped value down to the floor', () => {
    expect(focusOpacityForSpan({ top: 750, bottom: 750 }, 1000, stepped)).toBe(0.4); // halfway: 0.6 - 0.5 * 0.4
    expect(focusOpacityForSpan({ top: 150, bottom: 150 }, 1000, stepped)).toBe(0.4);
  });

  it('bends the curve after the step by its curvature', () => {
    const early = { ...stepped, curvature: 0.5 };
    const late = { ...stepped, curvature: 2 };
    expect(focusOpacityForSpan({ top: 625, bottom: 625 }, 1000, early)).toBe(0.4); // 0.6 - sqrt(0.25) * 0.4
    expect(focusOpacityForSpan({ top: 750, bottom: 750 }, 1000, late)).toBe(0.5); // 0.6 - 0.5^2 * 0.4
  });

  it('reaches its floor at the viewport edges, and never goes below it', () => {
    expect(focusOpacityForSpan({ top: 0, bottom: 0 }, 1000, stepped)).toBe(0.2);
    expect(focusOpacityForSpan({ top: 1000, bottom: 1000 }, 1000, stepped)).toBe(0.2);
    expect(focusOpacityForSpan({ top: 9000, bottom: 9000 }, 1000, stepped)).toBe(0.2);
  });

  it('fades straight from full opacity when the falloff is zero', () => {
    const smooth = { ...stepped, falloff: 0 };
    expect(focusOpacityForSpan({ top: 501, bottom: 501 }, 1000, smooth)).toBe(0.998);
    expect(focusOpacityForSpan({ top: 750, bottom: 750 }, 1000, smooth)).toBe(0.6); // 1 - 0.5 * 0.8
  });

  it('never steps below the floor, however large the falloff', () => {
    const harsh = { ...stepped, falloff: 0.9 };
    expect(focusOpacityForSpan({ top: 501, bottom: 501 }, 1000, harsh)).toBe(0.2);
    expect(focusOpacityForSpan({ top: 750, bottom: 750 }, 1000, harsh)).toBe(0.2);
  });

  it('leaves everything opaque when the band spans the whole viewport', () => {
    const whole = { ...stepped, band: { top: 0, bottom: 1 } };
    expect(focusOpacityForSpan({ top: 0, bottom: 0 }, 1000, whole)).toBe(1);
    expect(focusOpacityForSpan({ top: 1000, bottom: 1000 }, 1000, whole)).toBe(1);
  });
});
