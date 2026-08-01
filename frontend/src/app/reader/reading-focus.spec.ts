import {
  FOCUS_MIN_OPACITY,
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
  // A short reading block — a list row or a paragraph — degenerates to a point:
  // its top and bottom coincide, so these cases are the plain centre fade.
  describe('a short block (top and bottom coincide)', () => {
    it('is fully opaque at the viewport centre', () => {
      expect(focusOpacityForSpan(500, 500, 1000)).toBe(1);
    });

    it('fades to the minimum a half-viewport away from the centre', () => {
      expect(focusOpacityForSpan(0, 0, 1000)).toBe(FOCUS_MIN_OPACITY);
      expect(focusOpacityForSpan(1000, 1000, 1000)).toBe(FOCUS_MIN_OPACITY);
    });

    it('fades symmetrically and monotonically with distance from the centre', () => {
      const near = focusOpacityForSpan(600, 600, 1000); // 100px from centre
      const far = focusOpacityForSpan(800, 800, 1000); // 300px from centre
      expect(near).toBeLessThan(1);
      expect(near).toBeGreaterThan(far);
      expect(focusOpacityForSpan(400, 400, 1000)).toBeCloseTo(near, 5); // mirror above centre
    });

    it('clamps blocks beyond a half-viewport to the minimum, never below', () => {
      expect(focusOpacityForSpan(-500, -500, 1000)).toBe(FOCUS_MIN_OPACITY);
      expect(focusOpacityForSpan(5000, 5000, 1000)).toBe(FOCUS_MIN_OPACITY);
    });
  });

  it('keeps a block fully opaque while its span covers the reading centre', () => {
    // A source group taller than the viewport whose centre is far off-screen: it
    // still straddles the centre line, so it is what the reader is reading (#213).
    expect(focusOpacityForSpan(-400, 600, 1000)).toBe(1);
    expect(focusOpacityForSpan(0, 5000, 1000)).toBe(1);
    expect(focusOpacityForSpan(500, 500, 1000)).toBe(1); // edge touching the centre
  });

  it('fades a block clear of the centre by its nearest edge, not its centre', () => {
    // A tall block sitting just below the centre: its geometric centre is a full
    // viewport away (the old curve would clamp it to the minimum), but its top
    // edge is only 100px past the centre, so it stays close to opaque.
    expect(focusOpacityForSpan(600, 1600, 1000)).toBe(0.91); // 1 - (100/500)*0.45
  });

  it('fades to the minimum once the nearest edge is a half-viewport away', () => {
    expect(focusOpacityForSpan(1000, 2000, 1000)).toBe(FOCUS_MIN_OPACITY); // top at centre+half
    expect(focusOpacityForSpan(-2000, 0, 1000)).toBe(FOCUS_MIN_OPACITY); // bottom at centre-half
  });

  it('degrades to fully opaque when the viewport has no measured height', () => {
    expect(focusOpacityForSpan(0, 400, 0)).toBe(1);
  });
});

describe('readingBlocks', () => {
  function root(html: string): Element {
    const el = document.createElement('div');
    el.innerHTML = html;
    return el;
  }

  it('returns direct children when the content is a flat block list', () => {
    const blocks = readingBlocks(root('<p>a</p><h2>b</h2><p>c</p>'));
    expect(blocks.map((b) => b.tagName)).toEqual(['P', 'H2', 'P']);
  });

  it('descends through a single wrapper chain to the real block level', () => {
    // Mirrors the extracted-article shape: div > article > p, h2, p …
    const blocks = readingBlocks(root('<div><article><p>a</p><h2>b</h2><p>c</p></article></div>'));
    expect(blocks.map((b) => b.tagName)).toEqual(['P', 'H2', 'P']);
  });

  it('does not descend into a leaf paragraph that only wraps inline content', () => {
    const blocks = readingBlocks(root('<p>hello <a href="#">link</a></p>'));
    expect(blocks.map((b) => b.tagName)).toEqual(['P']);
  });

  it('stops at the first level holding multiple blocks', () => {
    const blocks = readingBlocks(root('<div><p>only</p></div>'));
    expect(blocks.map((b) => b.tagName)).toEqual(['P']);
  });

  // #109: the real shape readability produced for the reported wired.com
  // article. The paragraphs sit two levels below the first branching level, so
  // taking that level's children made the whole article two blocks — nothing
  // ever highlighted, then seventeen paragraphs at once.
  it('reaches paragraphs that sit below several branching wrappers', () => {
    const blocks = readingBlocks(
      root(`<div class="page"><div>
              <div><figure><img></figure><div><p>a</p><p>b</p><h2>c</h2><p>d</p></div></div>
              <div><p>e</p><h2>f</h2><p>g</p></div>
            </div></div>`),
    );
    expect(blocks.map((b) => b.tagName)).toEqual(['FIGURE', 'P', 'P', 'H2', 'P', 'P', 'H2', 'P']);
  });

  it('keeps a list, a quote and a figure as one block each', () => {
    const blocks = readingBlocks(
      root(
        '<div><ul><li>a</li><li>b</li></ul><blockquote><p>q</p></blockquote>' +
          '<figure><img><figcaption>cap</figcaption></figure></div>',
      ),
    );
    expect(blocks.map((b) => b.tagName)).toEqual(['UL', 'BLOCKQUOTE', 'FIGURE']);
  });

  it('treats a wrapper holding only inline content as the paragraph itself', () => {
    const blocks = readingBlocks(root('<div><div>text with <a href="#">a link</a></div></div>'));
    expect(blocks.map((b) => b.tagName)).toEqual(['DIV']);
    expect(blocks[0].textContent).toContain('text with');
  });

  it('stops descending at a bounded depth', () => {
    const deep = '<div>'.repeat(40) + '<p>bottom</p>' + '</div>'.repeat(40);
    const blocks = readingBlocks(root(deep));
    expect(blocks.length).toBeGreaterThan(0); // terminates, and returns something to fade
  });
});
