import { ComponentFixture } from '@angular/core/testing';
import { EntryDto } from '../app/reader/models';

type RenderEntry = (over: Partial<EntryDto>) => ComponentFixture<unknown>;

const portraitImage = (fixture: ComponentFixture<unknown>) =>
  (fixture.nativeElement as HTMLElement).querySelector<HTMLImageElement>(
    '.img-frame.portrait > img',
  );

/** The rule for a layout with a side image: a portrait cover shows at its own shape. */
export function describePortraitCover(render: RenderEntry): void {
  describe('portrait cover', () => {
    it('crops a letterboxed cover to the picture it shows', () => {
      const image = portraitImage(
        render({ imageWidth: 480, imageHeight: 360, imageAspectRatio: 9 / 16 }),
      );

      expect(image?.style.aspectRatio).toBe('0.5625');
    });

    it('frames a portrait image in portrait, whatever the entry links', () => {
      expect(portraitImage(render({ imageWidth: 900, imageHeight: 1600 }))).not.toBeNull();
    });

    it('never crops a portrait narrower than 9:16', () => {
      const image = portraitImage(render({ imageWidth: 900, imageHeight: 3000 }));

      expect(image?.style.aspectRatio).toBe('0.5625');
    });

    it('keeps the image box of a landscape image', () => {
      const element = render({ imageWidth: 700, imageHeight: 400 }).nativeElement as HTMLElement;

      expect(element.querySelector('.img-frame > img')).not.toBeNull();
      expect(element.querySelector('.img-frame.portrait')).toBeNull();
    });
  });
}
