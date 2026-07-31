import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { ErrorBannerComponent } from './error-banner.component';

@Component({
  imports: [ErrorBannerComponent],
  template: `
    <app-error-banner [message]="message()" [actionLabel]="actionLabel()" (action)="onAction()" />
  `,
})
class Host {
  readonly message = signal('Something went wrong');
  readonly actionLabel = signal<string | null>(null);
  readonly onAction = jest.fn();
}

describe('ErrorBannerComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the message inside an alert region', async () => {
    const fixture = await mount();
    const el: HTMLElement = fixture.nativeElement;
    const banner = el.querySelector('[role="alert"]') as HTMLElement;
    expect(banner).not.toBeNull();
    expect(banner.textContent).toContain('Something went wrong');
  });

  it('renders no button when no action label is given', async () => {
    const fixture = await mount();
    expect(fixture.nativeElement.querySelector('button')).toBeNull();
  });

  it('renders the action button with the given label and emits on click', async () => {
    const fixture = await mount();
    fixture.componentInstance.actionLabel.set('Retry');
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    expect(button.textContent?.trim()).toBe('Retry');

    button.click();
    expect(fixture.componentInstance.onAction).toHaveBeenCalledTimes(1);
  });
});
