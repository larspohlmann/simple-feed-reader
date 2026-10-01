import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
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
  const ladder: ImageRenditionDto[] = [
    { url: 'https://i/a-424.jpg', width: 424 },
    { url: 'https://i/a-848.jpg', width: 848 },
  ];

  function mountFixture(
    renditions: ImageRenditionDto[] | undefined,
  ): ComponentFixture<HostComponent> {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.renditions.set(renditions);
    fixture.detectChanges();
    return fixture;
  }

  function mount(renditions: ImageRenditionDto[] | undefined): HTMLImageElement {
    return mountFixture(renditions).nativeElement.querySelector('img') as HTMLImageElement;
  }

  function directiveOf(fixture: ComponentFixture<HostComponent>): RenditionsDirective {
    return fixture.debugElement
      .query(By.directive(RenditionsDirective))
      .injector.get(RenditionsDirective);
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

  it('drops the srcset and sizes on a first failure, so the browser retries the plain src', () => {
    const fixture = mountFixture(ladder);

    expect(directiveOf(fixture).fallBackToSrc()).toBe(true);
    fixture.detectChanges();

    const img = fixture.nativeElement.querySelector('img') as HTMLImageElement;
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
    expect(img.getAttribute('src')).toBe('https://i/a.jpg');
  });

  it('has nothing left to fall back to on a second failure', () => {
    const fixture = mountFixture(ladder);
    directiveOf(fixture).fallBackToSrc();
    fixture.detectChanges();

    expect(directiveOf(fixture).fallBackToSrc()).toBe(false);
  });

  it('has nothing to fall back to without renditions', () => {
    expect(directiveOf(mountFixture([])).fallBackToSrc()).toBe(false);
  });

  it('offers the renditions again once the image is given another ladder', () => {
    const fixture = mountFixture(ladder);
    directiveOf(fixture).fallBackToSrc();
    fixture.detectChanges();

    fixture.componentInstance.renditions.set([{ url: 'https://i/b-640.jpg', width: 640 }]);
    fixture.detectChanges();

    const img = fixture.nativeElement.querySelector('img') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe('https://i/b-640.jpg 640w');
  });
});
