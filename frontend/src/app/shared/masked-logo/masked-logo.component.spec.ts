import { TestBed } from '@angular/core/testing';
import { MaskedLogoComponent } from './masked-logo.component';

function render(color?: string): HTMLElement {
  const fixture = TestBed.createComponent(MaskedLogoComponent);
  fixture.componentRef.setInput('src', 'share-targets/bluesky.svg');
  fixture.componentRef.setInput('color', color);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('MaskedLogoComponent', () => {
  it('masks the host with the file, hidden from assistive technology', () => {
    const host = render();

    expect(host.style.maskImage).toBe('url(share-targets/bluesky.svg)');
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.style.backgroundColor).toBe('');
  });

  it('fills the mask with the given colour', () => {
    expect(render('rgb(17, 133, 254)').style.backgroundColor).toBe('rgb(17, 133, 254)');
  });
});
