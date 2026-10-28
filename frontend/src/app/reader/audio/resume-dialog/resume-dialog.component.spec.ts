import { ComponentFixture, TestBed } from '@angular/core/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { hasTranslation } from '../../../../testing/translation-keys';
import { ResumeData, ResumeDialogComponent } from './resume-dialog.component';

describe('ResumeDialogComponent', () => {
  const close = jest.fn();
  const data: ResumeData = { title: 'The long episode', seconds: 1421, durationSeconds: 3490 };

  function render(): ComponentFixture<ResumeDialogComponent> {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const fixture = TestBed.createComponent(ResumeDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function button(element: HTMLElement, testId: string): HTMLButtonElement {
    return element.querySelector(`[data-testid="${testId}"] button`) as HTMLButtonElement;
  }

  beforeEach(() => close.mockReset());

  it('names the episode', () => {
    expect(render().nativeElement.textContent).toContain('The long episode');
  });

  it('has its copy in the dictionary', () => {
    for (const key of ['message', 'continue', 'fromStart']) {
      expect(hasTranslation(`reader.audioPlayer.resume.${key}`)).toBe(true);
    }
  });

  it('formats the place and the length as clock times', () => {
    const component = render().componentInstance;
    expect(component.position).toBe('23:41');
    expect(component.duration).toBe('58:10');
  });

  it('closes with continue, or with from start', () => {
    const element: HTMLElement = render().nativeElement;

    button(element, 'continue').click();
    expect(close).toHaveBeenLastCalledWith('continue');

    button(element, 'from-start').click();
    expect(close).toHaveBeenLastCalledWith('fromStart');
  });
});
