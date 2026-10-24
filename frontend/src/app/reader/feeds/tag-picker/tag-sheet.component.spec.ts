import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TagDto } from '../../models';
import { TagSearchSource } from './tag-search';
import { TagSheetComponent } from './tag-sheet.component';

const tag = (id: number, name: string): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: id,
  parentId: null,
});

describe('TagSheetComponent', () => {
  let fixture: ComponentFixture<TagSheetComponent>;
  let toggled: number[];
  const ref = { close: jest.fn() };
  const element = (): HTMLElement => fixture.nativeElement;
  const optionLabels = (): string[] =>
    [...element().querySelectorAll('[role="option"]')].map(
      (option) => option.textContent?.replace(/\s+/g, ' ').trim() ?? '',
    );

  beforeEach(async () => {
    toggled = [];
    ref.close.mockClear();
    const source: TagSearchSource = {
      tags: signal([tag(1, 'Tech'), tag(2, 'Music')]),
      selected: signal(new Set<number>()),
      suggested: signal([tag(2, 'Music')]),
      toggle: (id) => toggled.push(id),
    };
    await TestBed.configureTestingModule({
      imports: [TagSheetComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DIALOG_DATA, useValue: source },
        { provide: DialogRef, useValue: ref },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(TagSheetComponent);
    fixture.detectChanges();
  });

  it('lists suggestions, then every tag, without a dropdown to open', () => {
    expect(optionLabels()).toEqual(['Music', 'Tech', 'Music']);
  });

  it('filters and toggles a row', () => {
    const query = element().querySelector('input.query') as HTMLInputElement;
    query.value = 'tec';
    query.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    (element().querySelector('[role="option"]') as HTMLElement).click();

    expect(toggled).toEqual([1]);
  });

  it('closes on Done', () => {
    (element().querySelector('app-button[footer] button') as HTMLButtonElement).click();

    expect(ref.close).toHaveBeenCalled();
  });
});
