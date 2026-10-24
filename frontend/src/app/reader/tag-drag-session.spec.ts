import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { TagsStore } from './state/tags.store';
import { TagDto } from './models';
import { TagDragSession, measureTagLayout } from './tag-drag-session';

const tag = (id: number, position: number): TagDto => ({
  id,
  name: `t${id}`,
  color: null,
  icon: null,
  position,
  parentId: null,
});
const tagA = tag(1, 0);
const tagB = tag(2, 1);
const LAYOUT = {
  headers: [
    { tagId: 1, top: 0, bottom: 40 },
    { tagId: 2, top: 50, bottom: 90 },
  ],
  listBottom: 90,
};

describe('TagDragSession', () => {
  let session: TagDragSession;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [TagDragSession, { provide: TagsStore, useValue: { tags: signal([tagA, tagB]) } }],
    });
    session = TestBed.inject(TagDragSession);
  });

  it('resolves nothing before the pointer moves', () => {
    session.start(tagB, LAYOUT);
    expect(session.drop()).toBeNull();
  });

  it('resolves the drop under the pointer', () => {
    session.start(tagB, LAYOUT);
    session.move(2);
    expect(session.drop()).toEqual({
      parentId: null,
      order: [2, 1],
      mark: { kind: 'before', tagId: 1 },
    });
  });

  it('hands the tag and its drop over on end and clears itself', () => {
    session.start(tagB, LAYOUT);
    session.move(2);
    expect(session.end()).toEqual({
      tag: tagB,
      drop: expect.objectContaining({ order: [2, 1] }),
    });
    expect(session.drop()).toBeNull();
    expect(session.end()).toBeNull();
  });

  it('ends with null when the drop changes nothing', () => {
    session.start(tagB, LAYOUT);
    session.move(70);
    expect(session.end()).toBeNull();
  });
});

describe('measureTagLayout', () => {
  const box = (top: number, height: number): Element =>
    ({
      getBoundingClientRect: () => ({ top, bottom: top + height, height }) as DOMRect,
    }) as Element;

  it('measures headers and the list foot relative to the origin', () => {
    expect(measureTagLayout([{ tagId: 1, element: box(110, 40) }], box(100, 300), 100)).toEqual({
      headers: [{ tagId: 1, top: 10, bottom: 50 }],
      listBottom: 300,
    });
  });

  it('falls back to the last header when there is no list foot', () => {
    expect(measureTagLayout([{ tagId: 1, element: box(110, 40) }], undefined, 100).listBottom).toBe(
      50,
    );
  });
});
