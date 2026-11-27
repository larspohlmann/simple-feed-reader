import { signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { NEVER, Observable, Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { anEntry } from '../../../testing/entry-testing';
import { EntryDto } from '../models';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryRowCache } from '../state/entry-row-cache';
import { ReaderApi } from '../reader-api';
import { SocialPostApi } from '../social-post-api';
import { ToastService } from '../../shared/toast/toast.service';
import { Problem } from '../../core/problem';
import { LabelsStore } from './labels.store';
import { LabelPill, LabelTally } from './labels.models';
import { LabelMenuPanelComponent } from './label-menu-panel.component';
import { LabelledRow } from './labelled-row';

const tally = (id: number, name: string): LabelTally => ({
  id,
  name,
  favoriteCount: 0,
  keptCount: 0,
  articleCount: 0,
});

const HOME = tally(1, 'Home');
const WORK = tally(2, 'Work');

describe('LabelMenuPanelComponent', () => {
  let labels: ReturnType<typeof signal<LabelTally[]>>;
  let actions: {
    attachLabel: jest.Mock;
    detachLabel: jest.Mock;
    createLabel: jest.Mock<Observable<void>, [EntryDto, string]>;
  };
  let showProblem: jest.Mock;

  beforeEach(() => {
    labels = signal([HOME, WORK]);
    actions = {
      attachLabel: jest.fn(),
      detachLabel: jest.fn(),
      createLabel: jest.fn<Observable<void>, [EntryDto, string]>(() => of(undefined)),
    };
    showProblem = jest.fn();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: LabelsStore, useValue: { labels } },
        { provide: ToastService, useValue: { showTranslatedProblem: showProblem } },
        { provide: ReaderApi, useValue: {} },
        { provide: SocialPostApi, useValue: {} },
      ],
    });
  });

  function mount(entry: EntryDto): ComponentFixture<LabelMenuPanelComponent> {
    const fixture = TestBed.createComponent(LabelMenuPanelComponent);
    const rows = TestBed.inject(EntryRowCache);
    fixture.componentRef.setInput(
      'row',
      new LabelledRow(rows, entry, actions as unknown as EntryActionHandler),
    );
    fixture.detectChanges();
    return fixture;
  }

  const boxes = (fixture: ComponentFixture<LabelMenuPanelComponent>): HTMLInputElement[] =>
    Array.from(fixture.nativeElement.querySelectorAll('[role="menuitemcheckbox"]'));

  const boxNames = (fixture: ComponentFixture<LabelMenuPanelComponent>): string[] =>
    boxes(fixture).map((box) => box.closest('label')!.textContent!.trim());

  const newLabelItem = (fixture: ComponentFixture<LabelMenuPanelComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('[role="menuitem"]');

  const nameField = (fixture: ComponentFixture<LabelMenuPanelComponent>): HTMLInputElement | null =>
    fixture.nativeElement.querySelector('input.new-label');

  function typeNewLabel(fixture: ComponentFixture<LabelMenuPanelComponent>, name: string): void {
    newLabelItem(fixture).click();
    fixture.detectChanges();
    const field = nameField(fixture)!;
    field.value = name;
    field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    fixture.detectChanges();
  }

  it('lists every label, checked where the entry carries it', () => {
    const fixture = mount(anEntry({ labels: [{ id: 2, name: 'Work' }] }));

    expect(boxNames(fixture)).toEqual(['Home', 'Work']);
    expect(boxes(fixture).map((box) => box.checked)).toEqual([false, true]);
  });

  it('attaches an unchecked label once and stays open', () => {
    const entry = anEntry({ labels: [] });
    const fixture = mount(entry);

    boxes(fixture)[0].dispatchEvent(new Event('change'));

    expect(actions.attachLabel).toHaveBeenCalledTimes(1);
    expect(actions.attachLabel).toHaveBeenCalledWith(entry, { id: 1, name: 'Home' });
    expect(actions.detachLabel).not.toHaveBeenCalled();
  });

  it('detaches a checked label once', () => {
    const entry = anEntry({ labels: [{ id: 2, name: 'Work' }] });
    const fixture = mount(entry);

    boxes(fixture)[1].dispatchEvent(new Event('change'));

    expect(actions.detachLabel).toHaveBeenCalledTimes(1);
    expect(actions.detachLabel).toHaveBeenCalledWith(entry, { id: 2, name: 'Work' });
    expect(actions.attachLabel).not.toHaveBeenCalled();
  });

  it('turns New label into a field whose Enter creates and attaches the label', () => {
    const entry = anEntry();
    const fixture = mount(entry);

    expect(newLabelItem(fixture).textContent).toContain('New label…');
    typeNewLabel(fixture, '  Work trips ');

    expect(actions.createLabel).toHaveBeenCalledWith(entry, 'Work trips');
    expect(nameField(fixture)).toBeNull();
    expect(newLabelItem(fixture)).not.toBeNull();
  });

  it('hands focus to New label once the label is made, so the keys keep reaching the menu', () => {
    const fixture = mount(anEntry());
    document.body.appendChild(fixture.nativeElement);

    typeNewLabel(fixture, 'Work trips');
    fixture.detectChanges();

    expect(document.activeElement).toBe(newLabelItem(fixture));
    fixture.nativeElement.remove();
  });

  it('creates nothing from a blank name', () => {
    const fixture = mount(anEntry());

    typeNewLabel(fixture, '   ');

    expect(actions.createLabel).not.toHaveBeenCalled();
    expect(nameField(fixture)).not.toBeNull();
  });

  it('keeps the field open with a toast when the name is taken', () => {
    const taken: Problem = { type: 'label_name_taken', title: 'Taken', status: 409 };
    const answer = new Subject<void>();
    actions.createLabel.mockReturnValue(answer);
    const fixture = mount(anEntry());

    typeNewLabel(fixture, 'Work');
    answer.error(taken);
    fixture.detectChanges();

    expect(showProblem).toHaveBeenCalledWith(taken, TestBed.inject(TranslocoService));
    expect(nameField(fixture)!.value).toBe('Work');
    expect(nameField(fixture)!.readOnly).toBe(false);
  });

  it('heads the list with a filter from nine labels on, not at eight', () => {
    const many = (count: number) =>
      Array.from({ length: count }, (_, index) => tally(index + 1, `Label ${index + 1}`));
    labels.set(many(8));
    const fixture = mount(anEntry());
    expect(fixture.nativeElement.querySelector('input.filter')).toBeNull();

    labels.set(many(9));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('input.filter')).not.toBeNull();
  });

  it('narrows the list to the labels the filter matches', () => {
    labels.set([
      ...Array.from({ length: 8 }, (_, index) => tally(index + 10, `Topic ${index}`)),
      WORK,
    ]);
    const fixture = mount(anEntry());
    const filter: HTMLInputElement = fixture.nativeElement.querySelector('input.filter');

    filter.value = 'wor';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(boxNames(fixture)).toEqual(['Work']);
  });

  describe('on a row that leaves its label’s list while the menu is up (#2581)', () => {
    it('keeps the last row seen, so the untick stays and a re-tick attaches again', () => {
      const rows = TestBed.inject(EntryRowCache);
      const row = anEntry({ id: 52, labels: [{ id: 1, name: 'Home' }] });
      rows.cached([row]);
      rows.hold(rows, new Set([row.id]));
      actions.detachLabel.mockImplementation((entry: EntryDto, label: LabelPill) =>
        rows.detachLabel(entry.id, label, NEVER).subscribe(),
      );
      const fixture = mount(row);

      boxes(fixture)[0].dispatchEvent(new Event('change'));
      rows.release(rows);
      fixture.detectChanges();
      expect(rows.entry(row.id)).toBeNull();
      expect(boxes(fixture)[0].checked).toBe(false);

      boxes(fixture)[0].dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(actions.attachLabel).toHaveBeenCalledWith(
        expect.objectContaining({ id: 52, labels: [] }),
        { id: 1, name: 'Home' },
      );
      expect(boxes(fixture)[0].checked).toBe(true);
    });
  });

  describe('on an article opened outside any list (#2581)', () => {
    it('follows each toggle at once, its row held as the unlisted entry', () => {
      const rows = TestBed.inject(EntryRowCache);
      const opened = anEntry({ id: 41, labels: [] });
      rows.cached([opened]);
      rows.hold(rows, new Set([opened.id]));
      const toRow = (entry: EntryDto, label: LabelPill) =>
        rows.attachLabel(entry.id, label, NEVER).subscribe();
      actions.attachLabel.mockImplementation(toRow);
      const fixture = mount(opened);

      boxes(fixture)[0].dispatchEvent(new Event('change'));
      fixture.detectChanges();
      boxes(fixture)[1].dispatchEvent(new Event('change'));
      fixture.detectChanges();

      expect(boxes(fixture).map((box) => box.checked)).toEqual([true, true]);
      expect(actions.attachLabel).toHaveBeenLastCalledWith(
        expect.objectContaining({ labels: [{ id: 1, name: 'Home' }] }),
        { id: 2, name: 'Work' },
      );
    });
  });
});
