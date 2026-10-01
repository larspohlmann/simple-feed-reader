import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ImageRenditionDto } from '../models';
import { RenditionsDirective } from './renditions.directive';

@Component({
  imports: [RenditionsDirective],
  template: `<img
    alt=""
    src="https://i/a.jpg"
    [appRenditions]="renditions()"
    [renditionSizes]="'88px'"
  />`,
})
class HostComponent {
  readonly renditions = signal<ImageRenditionDto[] | undefined>([]);
}

describe('RenditionsDirective', () => {
  function mount(renditions: ImageRenditionDto[] | undefined): HTMLImageElement {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.renditions.set(renditions);
    fixture.detectChanges();
    return fixture.nativeElement.querySelector('img') as HTMLImageElement;
  }

  it('offers the renditions and the rendered width to the browser', () => {
    const img = mount([
      { url: 'https://i/a-424.jpg', width: 424 },
      { url: 'https://i/a-848.jpg', width: 848 },
    ]);
    expect(img.getAttribute('srcset')).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
    expect(img.getAttribute('sizes')).toBe('88px');
    expect(img.hasAttribute('renditionsizes')).toBe(false);
  });

  it('leaves the plain src alone when there are no renditions', () => {
    const img = mount([]);
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });

  it('leaves the plain src alone when the entry omits the field', () => {
    const img = mount(undefined);
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });
});
