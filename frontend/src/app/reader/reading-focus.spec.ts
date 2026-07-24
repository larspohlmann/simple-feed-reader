import { FOCUS_MIN_OPACITY, focusOpacity, readingBlocks } from './reading-focus';

describe('focusOpacity', () => {
  it('is fully opaque at the viewport center', () => {
    expect(focusOpacity(500, 1000)).toBe(1);
  });

  it('fades to the minimum a half-viewport away from center', () => {
    expect(focusOpacity(0, 1000)).toBe(FOCUS_MIN_OPACITY);
    expect(focusOpacity(1000, 1000)).toBe(FOCUS_MIN_OPACITY);
  });

  it('fades symmetrically and monotonically with distance from center', () => {
    const near = focusOpacity(600, 1000); // 100px from center
    const far = focusOpacity(800, 1000); // 300px from center
    expect(near).toBeLessThan(1);
    expect(near).toBeGreaterThan(far);
    expect(focusOpacity(400, 1000)).toBeCloseTo(near, 5); // mirror above center
  });

  it('clamps blocks beyond a half-viewport to the minimum, never below', () => {
    expect(focusOpacity(-500, 1000)).toBe(FOCUS_MIN_OPACITY);
    expect(focusOpacity(5000, 1000)).toBe(FOCUS_MIN_OPACITY);
  });

  it('degrades to fully opaque when the viewport has no measured height', () => {
    expect(focusOpacity(0, 0)).toBe(1);
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
});
