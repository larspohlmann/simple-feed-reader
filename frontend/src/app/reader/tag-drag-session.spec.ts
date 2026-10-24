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
const box = (top: number, height: number): Element =>
  ({
    getBoundingClientRect: () => ({ top, bottom: top + height, height }) as DOMRect,
  }) as Element;
/** An element whose top the test moves, as a scroll would. */
const scrollable = (top: number) => {
  const element = { top, getBoundingClientRect: () => ({ top: element.top }) as DOMRect };
  return element;
};
// Origin at 100; headers 40 tall at offsets 0 and 50.
const ORIGIN_TOP = 100;
const source = (origin: Element = box(ORIGIN_TOP, 0)) => ({
  headers: [
    { tagId: 1, element: box(ORIGIN_TOP, 40) },
    { tagId: 2, element: box(ORIGIN_TOP + 50, 40) },
  ],
  listEnd: undefined,
  origin,
});

describe('TagDragSession', () => {
  let session: TagDragSession;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [TagDragSession, { provide: TagsStore, useValue: { tags: signal([tagA, tagB]) } }],
    });
    session = TestBed.inject(TagDragSession);
  });

  it('resolves nothing before the pointer moves', () => {
    session.start(tagB, source());
    expect(session.drop()).toBeNull();
  });

  it('resolves the drop under the pointer', () => {
    session.start(tagB, source());
    session.move(ORIGIN_TOP + 2);
    expect(session.drop()).toEqual({
      parentId: null,
      order: [2, 1],
      mark: { kind: 'before', tagId: 1 },
    });
  });

  it('names the cue only for the tag the drop marks', () => {
    session.start(tagB, source());
    expect(session.cueFor(1)).toBeNull();

    session.move(ORIGIN_TOP + 2);

    expect(session.cueFor(1)).toBe('before');
    expect(session.cueFor(2)).toBeNull();
  });

  it('hands the tag and its drop over on end and clears itself', () => {
    session.start(tagB, source());
    session.move(ORIGIN_TOP + 2);
    expect(session.end()).toEqual({
      tag: tagB,
      drop: expect.objectContaining({ order: [2, 1] }),
    });
    expect(session.drop()).toBeNull();
    expect(session.end()).toBeNull();
  });

  it('counts the pointer from the origin as it is now, so a scroll mid-drag keeps the drop', () => {
    const origin = scrollable(ORIGIN_TOP);
    session.start(tagB, source(origin as unknown as Element));
    origin.top = ORIGIN_TOP - 300;

    session.move(ORIGIN_TOP - 300 + 20);

    expect(session.drop()?.mark).toEqual({ kind: 'nest', tagId: 1 });
  });

  it('ignores a move before any drag started', () => {
    session.move(ORIGIN_TOP + 2);
    expect(session.drop()).toBeNull();
  });

  it('ends with null when the drop changes nothing', () => {
    session.start(tagB, source());
    session.move(ORIGIN_TOP + 70);
    expect(session.end()).toBeNull();
  });
});

describe('measureTagLayout', () => {
  it('measures headers and the list foot relative to the origin', () => {
    expect(
      measureTagLayout({
        headers: [{ tagId: 1, element: box(110, 40) }],
        listEnd: box(100, 300),
        origin: box(100, 0),
      }),
    ).toEqual({
      headers: [{ tagId: 1, top: 10, bottom: 50 }],
      listBottom: 300,
    });
  });

  it('returns the headers in screen order whatever order they were passed in', () => {
    const layout = measureTagLayout({
      headers: [
        { tagId: 3, element: box(200, 40) },
        { tagId: 1, element: box(100, 40) },
        { tagId: 2, element: box(150, 40) },
      ],
      listEnd: undefined,
      origin: box(100, 0),
    });
    expect(layout.headers.map((header) => header.tagId)).toEqual([1, 2, 3]);
    expect(layout.listBottom).toBe(140);
  });

  it('falls back to the last header when there is no list foot', () => {
    const layout = measureTagLayout({
      headers: [{ tagId: 1, element: box(110, 40) }],
      listEnd: undefined,
      origin: box(100, 0),
    });
    expect(layout.listBottom).toBe(50);
  });
});
