import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { NarrationToggleComponent } from './narration-toggle.component';

function render(player = document.createElement('audio')) {
  TestBed.configureTestingModule({
    imports: [NarrationToggleComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(NarrationToggleComponent);
  fixture.componentRef.setInput('player', player);
  fixture.detectChanges();
  const button = (fixture.nativeElement as HTMLElement).querySelector('button')!;
  return { fixture, player, button };
}

describe('NarrationToggleComponent', () => {
  it('is a collapsed line labelled as machine narration', () => {
    const { player, button } = render();

    expect(button.type).toBe('button');
    expect(button.textContent).toContain('Listen — machine-generated narration');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(player.classList).not.toContain('reader-narration--open');
  });

  it('shows the player when opened and hides it again', () => {
    const { fixture, player, button } = render();

    button.click();
    fixture.detectChanges();
    expect(player.classList).toContain('reader-narration--open');
    expect(button.getAttribute('aria-expanded')).toBe('true');

    button.click();
    fixture.detectChanges();
    expect(player.classList).not.toContain('reader-narration--open');
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });

  it('starts open on a player already shown', () => {
    const player = document.createElement('audio');
    player.classList.add('reader-narration--open');
    const { button } = render(player);

    expect(button.getAttribute('aria-expanded')).toBe('true');
  });
});
