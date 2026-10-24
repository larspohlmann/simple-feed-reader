import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { TagDto } from '../../models';
import { TagPickerComponent } from './tag-picker.component';
import { TagSheetComponent } from './tag-sheet.component';

const tag = (id: number, name: string, parentId: number | null = null): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: id,
  parentId,
});

describe('TagPickerComponent', () => {
  let fixture: ComponentFixture<TagPickerComponent>;
  let toggled: number[];
  const isPhone = signal(false);
  const isCoarse = signal(false);
  const dialog = { open: jest.fn() };

  const tags = [tag(1, 'Tech'), tag(2, 'Reading'), tag(3, 'Long reads', 2), tag(4, 'Music')];
  const element = (): HTMLElement => fixture.nativeElement;
  const query = (): HTMLInputElement => element().querySelector('input.query') as HTMLInputElement;
  const textOf = (node: Element): string => {
    const copy = node.cloneNode(true) as Element;
    copy.querySelectorAll('app-icon').forEach((icon) => icon.remove());
    return copy.textContent?.replace(/\s+/g, ' ').trim() ?? '';
  };
  const chipLabels = (): string[] => [...element().querySelectorAll('.chip')].map(textOf);
  const optionLabels = (): string[] =>
    [...element().querySelectorAll('[role="option"]')].map(textOf);
  const key = (name: string): void => {
    query().dispatchEvent(new KeyboardEvent('keydown', { key: name, cancelable: true }));
    fixture.detectChanges();
  };
  const type = (text: string): void => {
    query().value = text;
    query().dispatchEvent(new Event('input'));
    fixture.detectChanges();
  };

  function mount(selected: number[], suggested: TagDto[] = []): void {
    fixture = TestBed.createComponent(TagPickerComponent);
    fixture.componentRef.setInput('tags', tags);
    fixture.componentRef.setInput('selected', new Set(selected));
    fixture.componentRef.setInput('suggested', suggested);
    fixture.componentInstance.toggled.subscribe((id) => toggled.push(id));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    toggled = [];
    isPhone.set(false);
    isCoarse.set(false);
    dialog.open.mockClear();
    await TestBed.configureTestingModule({
      imports: [TagPickerComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: LayoutService, useValue: { isPhone, isCoarse } },
        { provide: Dialog, useValue: dialog },
      ],
    }).compileComponents();
  });

  it('shows the selected tags as chips in tree order, a child named with its parent', () => {
    mount([3, 1]);

    expect(chipLabels()).toEqual(['Tech', 'Reading / Long reads']);
  });

  it('emits a chip id when its remove button is pressed', () => {
    mount([1]);

    (element().querySelector('.chip .remove') as HTMLButtonElement).click();

    expect(toggled).toEqual([1]);
  });

  it('offers suggestions one tap away while the list is closed', () => {
    mount([], [tag(4, 'Music')]);

    const suggestion = element().querySelector('button.suggestion') as HTMLButtonElement;
    expect(suggestion.textContent?.trim()).toBe('Music');
    suggestion.click();

    expect(toggled).toEqual([4]);
  });

  it('opens the list on focus with suggestions above every tag', () => {
    mount([1], [tag(4, 'Music')]);

    query().dispatchEvent(new Event('focus'));
    fixture.detectChanges();

    expect(query().getAttribute('aria-expanded')).toBe('true');
    expect(optionLabels()).toEqual(['Music', 'Tech', 'Reading', 'Long reads', 'Music']);
    expect(element().querySelector('button.suggestion')).toBeNull();
  });

  it('filters as the user types and toggles the active match on Enter', () => {
    mount([]);

    type('read');
    expect(optionLabels()).toEqual(['Reading', 'Reading / Long reads', 'Create tag “read”']);
    key('ArrowDown');
    expect(query().getAttribute('aria-activedescendant')).toMatch(/-option-1$/);
    key('Enter');

    expect(toggled).toEqual([3]);
    expect(query().value).toBe('');
  });

  it('drops the last chip on Backspace in an empty query', () => {
    mount([1, 4]);
    query().dispatchEvent(new Event('focus'));

    key('Backspace');

    expect(toggled).toEqual([4]);
  });

  it('creates a tag from the query and selects it', () => {
    mount([]);
    const http = TestBed.inject(HttpTestingController);

    type('Science');
    key('Enter');
    const request = http.expectOne('https://api.test/api/tags');
    expect(request.request.body).toEqual({
      name: 'Science',
      color: null,
      icon: null,
      parentId: null,
    });
    request.flush({ tag: tag(9, 'Science') });

    expect(toggled).toEqual([9]);
    http.verify();
  });

  it('closes the list on Escape without letting the dialog see it', () => {
    mount([]);
    query().dispatchEvent(new Event('focus'));
    fixture.detectChanges();
    const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
    const reachedOutside = jest.fn();
    element().addEventListener('keydown', reachedOutside);

    query().dispatchEvent(escape);
    fixture.detectChanges();

    expect(element().querySelector('[role="listbox"]')).toBeNull();
    expect(reachedOutside).not.toHaveBeenCalled();
  });

  it('opens the full-screen sheet instead of a list on touch', () => {
    isCoarse.set(true);
    mount([1]);

    expect(query()).toBeNull();
    (element().querySelector('button.add') as HTMLButtonElement).click();

    expect(dialog.open).toHaveBeenCalledWith(
      TagSheetComponent,
      expect.objectContaining({ data: expect.objectContaining({ toggle: expect.any(Function) }) }),
    );
    const source = dialog.open.mock.calls[0][1].data;
    source.toggle(4);
    expect(toggled).toEqual([4]);
    expect([...source.selected()]).toEqual([1]);
  });
});
