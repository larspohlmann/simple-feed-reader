import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { NEVER, Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { anEntry } from '../../../testing/entry-testing';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { LayoutService } from '../layout.service';
import { ReaderApi } from '../reader-api';
import { SocialPostApi } from '../social-post-api';
import { ToastService } from '../../shared/toast/toast.service';
import {
  ActionSheetCheck,
  ActionSheetData,
} from '../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { LabelsStore } from './labels.store';
import { LabelTally } from './labels.models';
import { LabelMenu } from './label-menu.service';
import { NewLabelDialogComponent } from './new-label-dialog.component';

const tally = (id: number, name: string): LabelTally => ({
  id,
  name,
  favoriteCount: 0,
  keptCount: 0,
  articleCount: 0,
});

describe('LabelMenu', () => {
  let coarse: ReturnType<typeof signal<boolean>>;
  let sheet: { open: jest.Mock };
  let dialog: { open: jest.Mock };
  let actions: { attachLabel: jest.Mock; detachLabel: jest.Mock; createLabel: jest.Mock };
  let anchor: HTMLButtonElement;

  beforeEach(() => {
    coarse = signal(false);
    sheet = { open: jest.fn(() => NEVER) };
    dialog = { open: jest.fn() };
    actions = { attachLabel: jest.fn(), detachLabel: jest.fn(), createLabel: jest.fn(() => of()) };
    anchor = document.createElement('button');
    document.body.appendChild(anchor);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse: coarse } },
        { provide: ActionSheet, useValue: sheet },
        {
          provide: LabelsStore,
          useValue: { labels: signal([tally(1, 'Home'), tally(2, 'Work')]) },
        },
        { provide: ToastService, useValue: { showProblem: jest.fn() } },
        { provide: ReaderApi, useValue: {} },
        { provide: SocialPostApi, useValue: {} },
      ],
    });
  });

  afterEach(() => anchor.remove());

  const handler = () => actions as unknown as EntryActionHandler;
  const panel = () => document.querySelector('app-label-menu-panel');

  it('opens the panel as a popover at the trigger on a fine pointer', () => {
    TestBed.inject(LabelMenu).open(anEntry(), anchor, handler());

    expect(panel()).not.toBeNull();
    expect(sheet.open).not.toHaveBeenCalled();
  });

  it('closes the popover on Escape and gives focus back to the trigger', () => {
    TestBed.inject(LabelMenu).open(anEntry(), anchor, handler());

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    expect(panel()).toBeNull();
    expect(document.activeElement).toBe(anchor);
  });

  describe('on a coarse pointer', () => {
    beforeEach(() => coarse.set(true));

    const openedSheet = (): ActionSheetData => sheet.open.mock.calls[0][0];
    const checks = (): ActionSheetCheck[] =>
      openedSheet().actions.filter((item): item is ActionSheetCheck => 'toggle' in item);

    it('opens the action sheet with a check per label, instead of the popover', () => {
      TestBed.inject(LabelMenu).open(
        anEntry({ labels: [{ id: 2, name: 'Work' }] }),
        anchor,
        handler(),
      );

      expect(panel()).toBeNull();
      expect(checks().map((check) => [check.label, check.checked()])).toEqual([
        ['Home', false],
        ['Work', true],
      ]);
    });

    it('attaches or detaches through the handler on a toggle', () => {
      const entry = anEntry({ labels: [{ id: 2, name: 'Work' }] });
      TestBed.inject(LabelMenu).open(entry, anchor, handler());

      checks()[0].toggle();
      checks()[1].toggle();

      expect(actions.attachLabel).toHaveBeenCalledWith(entry, { id: 1, name: 'Home' });
      expect(actions.detachLabel).toHaveBeenCalledWith(entry, { id: 2, name: 'Work' });
    });

    it('opens the new-label dialog from its New label action', () => {
      const choice = new Subject<string | undefined>();
      sheet.open.mockReturnValue(choice);
      TestBed.overrideProvider(Dialog, { useValue: dialog });
      const entry = anEntry();
      TestBed.inject(LabelMenu).open(entry, anchor, handler());

      expect(openedSheet().actions.at(-1)).toEqual(
        expect.objectContaining({ id: 'new', label: 'New label…' }),
      );
      choice.next('new');

      expect(dialog.open).toHaveBeenCalledWith(
        NewLabelDialogComponent,
        expect.objectContaining({ panelClass: 'app-dialog', data: { entry, actions: handler() } }),
      );
    });
  });
});
