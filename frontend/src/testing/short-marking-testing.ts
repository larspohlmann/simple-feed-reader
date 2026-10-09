import { ComponentFixture } from '@angular/core/testing';
import { EntryDto } from '../app/reader/models';

type RenderEntry = (over: Partial<EntryDto>) => ComponentFixture<unknown>;

const shortMarks = (fixture: ComponentFixture<unknown>) => {
  const element = fixture.nativeElement as HTMLElement;
  return {
    badge: element.querySelector('.img-frame > app-short-badge') !== null,
    pill: element.querySelector('.pill.short') !== null,
  };
};

/** The rule every image-bearing list layout shares: a Short's image carries the badge, and a Short shown without one carries the pill. */
export function describeShortMarking(render: RenderEntry): void {
  describe('YouTube Short marking', () => {
    it('badges the image of a Short and shows no pill', () => {
      expect(shortMarks(render({ isShort: true }))).toEqual({ badge: true, pill: false });
    });

    it('marks a Short without an image with a pill', () => {
      expect(shortMarks(render({ isShort: true, imageUrl: null }))).toEqual({
        badge: false,
        pill: true,
      });
    });

    it('falls back to the pill when the image of a Short fails to load', () => {
      const fixture = render({ isShort: true });
      (fixture.nativeElement as HTMLElement)
        .querySelector('img')!
        .dispatchEvent(new Event('error'));
      fixture.detectChanges();

      expect(shortMarks(fixture)).toEqual({ badge: false, pill: true });
    });

    it('marks no other entry with an image', () => {
      expect(shortMarks(render({}))).toEqual({ badge: false, pill: false });
    });

    it('marks no other entry without an image', () => {
      expect(shortMarks(render({ imageUrl: null }))).toEqual({ badge: false, pill: false });
    });
  });
}

/** The rule for a text-only block: a Short carries the pill, any other entry none. */
export function describeShortPill(render: RenderEntry): void {
  describe('YouTube Short pill', () => {
    it('marks a Short with a pill', () => {
      expect(shortMarks(render({ isShort: true }))).toEqual({ badge: false, pill: true });
    });

    it('marks no other entry', () => {
      expect(shortMarks(render({}))).toEqual({ badge: false, pill: false });
    });
  });
}

/** The rule for a layout with a side image: a Short's cover shows in portrait. */
export function describeShortPortrait(render: RenderEntry): void {
  describe('YouTube Short portrait', () => {
    it('crops the image of a Short to a portrait box', () => {
      const element = render({ isShort: true }).nativeElement as HTMLElement;

      expect(element.querySelector('.img-frame.portrait > img')).not.toBeNull();
    });

    it('keeps the image box of any other entry', () => {
      const element = render({}).nativeElement as HTMLElement;

      expect(element.querySelector('.img-frame > img')).not.toBeNull();
      expect(element.querySelector('.img-frame.portrait')).toBeNull();
    });
  });
}
