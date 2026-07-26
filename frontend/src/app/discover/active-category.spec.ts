import { ActiveCategory } from './active-category';

describe('ActiveCategory', () => {
  it('starts with nothing active and follows observed sections', () => {
    const active = new ActiveCategory();
    expect(active.activeId()).toBeNull();

    active.observed(7);
    expect(active.activeId()).toBe(7);
  });

  it('ignores observations while a jump is settling, then resumes', () => {
    const active = new ActiveCategory();

    active.jumpTo(3);
    expect(active.activeId()).toBe(3);

    // Sections flying past during the smooth scroll must not steal the highlight.
    active.observed(4);
    active.observed(5);
    expect(active.activeId()).toBe(3);

    active.settled();
    active.observed(5);
    expect(active.activeId()).toBe(5);
  });
});
