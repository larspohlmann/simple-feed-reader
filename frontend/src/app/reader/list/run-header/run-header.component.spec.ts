import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { RunHeaderComponent } from './run-header.component';

function mount(generatedAt: string): HTMLElement {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [RunHeaderComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(RunHeaderComponent);
  fixture.componentRef.setInput('generatedAt', generatedAt);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('RunHeaderComponent', () => {
  it('renders the localised "Generated" label with a relative time', () => {
    // Three days before now, so relativeTime is deterministic ("3 days ago").
    const threeDaysAgo = new Date(Date.now() - 3 * 86_400_000).toISOString();
    const element = mount(threeDaysAgo);

    const header = element.querySelector('.run-header');
    expect(header).not.toBeNull();
    expect(header!.textContent).toContain('Generated');
    expect(header!.textContent).toContain('day');
  });
});
