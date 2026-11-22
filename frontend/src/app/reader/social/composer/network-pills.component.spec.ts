import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SocialNetworkName } from '../../../core/social-network';
import { SentPost } from './composed-post';
import { NetworkPillsComponent, PillLane } from './network-pills.component';

describe('NetworkPillsComponent', () => {
  const lane = (
    network: SocialNetworkName,
    state: { active?: boolean; sent?: boolean; loadError?: string; sendError?: string } = {},
  ): PillLane => ({
    network,
    active: signal(state.active ?? false),
    sent: signal(state.sent === true ? ({ network } as SentPost) : null).asReadonly(),
    loadError: signal(state.loadError ?? null).asReadonly(),
    sendError: signal(state.sendError ?? null).asReadonly(),
  });

  function mount(lanes: PillLane[]) {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(NetworkPillsComponent);
    fixture.componentRef.setInput('lanes', lanes);
    const toggled = jest.fn();
    fixture.componentInstance.toggleNetwork.subscribe(toggled);
    fixture.detectChanges();
    const buttons = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ) as HTMLButtonElement[];
    return { buttons, toggled };
  }

  it('shows a pressed or unpressed pill with the logo and name of each lane, in order', () => {
    const { buttons } = mount([lane('bluesky', { active: true }), lane('mastodon')]);

    expect(buttons.map((button) => button.type)).toEqual(['button', 'button']);
    expect(buttons.map((button) => button.getAttribute('aria-pressed'))).toEqual(['true', 'false']);
    expect(buttons.map((button) => button.textContent?.trim())).toEqual(['Bluesky', 'Mastodon']);
    expect(buttons[0].classList).toContain('on');
    expect(buttons[1].classList).not.toContain('on');
    expect(buttons[1].querySelector('app-network-logo')).not.toBeNull();
  });

  it('asks to toggle the network of a pill clicked', () => {
    const { buttons, toggled } = mount([lane('bluesky', { active: true }), lane('mastodon')]);

    buttons[1].click();

    expect(toggled).toHaveBeenCalledWith('mastodon');
  });

  it('locks a sent pill as posted', () => {
    const { buttons } = mount([
      lane('bluesky', { active: true, sent: true }),
      lane('mastodon', { active: true }),
    ]);

    expect(buttons[0].classList).toContain('sent');
    expect(buttons[0].getAttribute('aria-disabled')).toBe('true');
    expect(buttons[0].textContent).toContain('✓ Posted');
    expect(buttons[1].getAttribute('aria-disabled')).toBeNull();
  });

  it('marks a pill whose load or send failed', () => {
    const { buttons } = mount([
      lane('bluesky', { active: true, sendError: 'No' }),
      lane('mastodon', { loadError: 'No' }),
    ]);

    expect(buttons.map((button) => button.classList.contains('failed'))).toEqual([true, true]);
    expect(buttons[1].querySelector('.warning-mark')).not.toBeNull();
  });
});
