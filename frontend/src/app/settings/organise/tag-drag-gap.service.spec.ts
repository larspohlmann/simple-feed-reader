import { TestBed } from '@angular/core/testing';
import { TagDragGap } from './tag-drag-gap.service';

const MIDDLES = [50, 150, 250];

describe('TagDragGap', () => {
  let gap: TagDragGap;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [TagDragGap] });
    gap = TestBed.inject(TagDragGap);
  });

  it('shows no gap until the dragged tag moves', () => {
    gap.start(0, MIDDLES);

    expect(gap.gap()).toBeNull();
  });

  it('shows the gap the pointer is in', () => {
    gap.start(0, MIDDLES);
    gap.move(200);

    expect(gap.gap()).toBe(2);
  });

  it('shows no gap while the pointer is beside the dragged tag itself', () => {
    gap.start(1, MIDDLES);
    gap.move(120);

    expect(gap.gap()).toBeNull();
  });

  it('shows no gap while the pointer is outside the sibling range', () => {
    gap.start(0, MIDDLES, { top: 20, bottom: 280 });
    gap.move(10);
    expect(gap.gap()).toBeNull();
    gap.move(300);
    expect(gap.gap()).toBeNull();
    gap.move(200);
    expect(gap.gap()).toBe(2);
  });

  it('ends with the move to the shown gap and clears it', () => {
    gap.start(0, MIDDLES);
    gap.move(400);

    expect(gap.end()).toEqual({ from: 0, gap: 3 });
    expect(gap.gap()).toBeNull();
  });

  it('ends with no move when the tag is dropped where it was', () => {
    gap.start(1, MIDDLES);
    gap.move(160);

    expect(gap.end()).toBeNull();
  });

  it('ends with no move when no drag started', () => {
    gap.move(400);

    expect(gap.end()).toBeNull();
  });
});
