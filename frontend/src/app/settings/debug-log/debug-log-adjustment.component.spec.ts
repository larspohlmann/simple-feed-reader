import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DebugLogAdjustment } from '../settings.models';
import { DebugLogAdjustmentComponent } from './debug-log-adjustment.component';

describe('DebugLogAdjustmentComponent', () => {
  function textOf(adjustment: DebugLogAdjustment): string {
    TestBed.configureTestingModule({
      imports: [DebugLogAdjustmentComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(DebugLogAdjustmentComponent);
    fixture.componentRef.setInput('adjustment', adjustment);
    fixture.detectChanges();
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  it('names the token parameter the next attempt sends', () => {
    expect(textOf({ setting: 'token-parameter', value: 'max_completion_tokens' })).toContain(
      'Adjusted for the next attempt: token limit sent as max_completion_tokens',
    );
  });

  it('names the lowered output cap', () => {
    expect(textOf({ setting: 'output-cap', value: '32768' })).toContain(
      'Adjusted for the next attempt: output limit lowered to 32768 tokens',
    );
  });

  it('says reasoning is no longer suppressed', () => {
    expect(textOf({ setting: 'reasoning-suppression', value: null })).toContain(
      'Adjusted for the next attempt: reasoning no longer suppressed',
    );
  });
});
