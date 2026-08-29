import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { SubscriptionDto } from '../../reader/models';

const SUBSCRIPTION = {
  id: 7,
  feedId: 7,
  title: 'heise online',
  faviconUrl: null,
  customTitle: null,
  feedUrl: 'https://heise.example/rss',
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: '2026-01-01T00:00:00Z',
  lastFetchedAt: null,
  position: 0,
  tags: [{ id: 2, name: 'Tech', color: null, icon: null, position: 0 }],
  unreadCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
} as SubscriptionDto;

describe('OrganiseFeedRowComponent', () => {
  let fixture: ComponentFixture<OrganiseFeedRowComponent>;
  const sheetOpen = jest.fn(() => of(undefined));

  async function render(inputs: Record<string, unknown> = {}) {
    sheetOpen.mockClear();
    await TestBed.configureTestingModule({
      imports: [OrganiseFeedRowComponent, provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse: signal(false) } },
        { provide: ActionSheet, useValue: { open: sheetOpen } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(OrganiseFeedRowComponent);
    fixture.componentRef.setInput('subscription', SUBSCRIPTION);
    fixture.componentRef.setInput('sortable', true);
    fixture.componentRef.setInput('reorderable', true);
    fixture.componentRef.setInput('canMoveUp', true);
    fixture.componentRef.setInput('canMoveDown', true);
    for (const [key, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(key, value);
    }
    fixture.detectChanges();
  }

  it('renders the feed title and its tag pills', async () => {
    await render();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('heise online');
    expect(text).toContain('Tech');
  });

  it('disables the up arrow at the top of a group', async () => {
    await render({ canMoveUp: false });

    const up = fixture.debugElement.query(By.css('[data-test="move-up"]'));
    expect(up.nativeElement.disabled).toBe(true);
  });

  it('emits moveDown when the down arrow is pressed', async () => {
    await render();
    const moved = jest.fn();
    fixture.componentInstance.moveDown.subscribe(moved);

    fixture.debugElement.query(By.css('[data-test="move-down"]')).nativeElement.click();

    expect(moved).toHaveBeenCalled();
  });

  it('hides the drag handle but keeps the arrows when only dragging is off', async () => {
    await render({ sortable: false });

    expect(fixture.debugElement.query(By.css('[data-test="drag-handle"]'))).toBeNull();
    expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).not.toBeNull();
  });

  it('hides the arrows in an unordered list', async () => {
    await render({ reorderable: false });

    expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).toBeNull();
  });

  it('emits selectedChange when the checkbox is toggled', async () => {
    await render();
    const changed = jest.fn();
    fixture.componentInstance.selectedChange.subscribe(changed);

    const box = fixture.debugElement.query(By.css('[data-test="select"]')).nativeElement;
    box.click();

    expect(changed).toHaveBeenCalledWith(true);
  });

  it('shows the drag handle when sortable', async () => {
    await render();

    expect(fixture.debugElement.query(By.css('[data-test="drag-handle"]'))).not.toBeNull();
  });

  it('disables the down arrow at the bottom of a group', async () => {
    await render({ canMoveDown: false });

    const down = fixture.debugElement.query(By.css('[data-test="move-down"]'));
    expect(down.nativeElement.disabled).toBe(true);
  });

  it('emits edit when the edit button is pressed', async () => {
    await render();
    const edited = jest.fn();
    fixture.componentInstance.edit.subscribe(edited);

    fixture.debugElement.query(By.css('[data-test="edit"]')).nativeElement.click();

    expect(edited).toHaveBeenCalled();
  });

  it('shows the excluded icon when the feed is hidden from a surface', async () => {
    await render({
      subscription: { ...SUBSCRIPTION, includeInAllItems: false },
    });

    expect(fixture.debugElement.query(By.css('[data-test="excluded"]'))).not.toBeNull();
  });

  it('hides the excluded icon when the feed is included everywhere', async () => {
    await render();

    expect(fixture.debugElement.query(By.css('[data-test="excluded"]'))).toBeNull();
  });

  it('opens a popover menu on a fine pointer instead of the action sheet', async () => {
    await render();

    expect(fixture.debugElement.query(By.css('.pop'))).toBeNull();

    fixture.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();
    fixture.detectChanges();

    expect(fixture.debugElement.query(By.css('.pop'))).not.toBeNull();
    expect(sheetOpen).not.toHaveBeenCalled();
  });

  it('emits toggleAllItems from the fine-pointer popover and closes it', async () => {
    await render();
    const toggled = jest.fn();
    fixture.componentInstance.toggleAllItems.subscribe(toggled);

    fixture.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();
    fixture.detectChanges();
    fixture.debugElement.query(By.css('[data-test="toggle-all-items"]')).nativeElement.click();
    fixture.detectChanges();

    expect(toggled).toHaveBeenCalled();
    expect(fixture.debugElement.query(By.css('.pop'))).toBeNull();
  });

  it('emits toggleForYou from the fine-pointer popover', async () => {
    await render();
    const toggled = jest.fn();
    fixture.componentInstance.toggleForYou.subscribe(toggled);

    fixture.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();
    fixture.detectChanges();
    fixture.debugElement.query(By.css('[data-test="toggle-for-you"]')).nativeElement.click();

    expect(toggled).toHaveBeenCalled();
  });

  it('emits unsubscribe from the fine-pointer popover', async () => {
    await render();
    const unsubscribed = jest.fn();
    fixture.componentInstance.unsubscribe.subscribe(unsubscribed);

    fixture.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();
    fixture.detectChanges();
    fixture.debugElement.query(By.css('[data-test="unsubscribe"]')).nativeElement.click();

    expect(unsubscribed).toHaveBeenCalled();
  });

  it('opens the action sheet instead of a popover on a coarse pointer', async () => {
    sheetOpen.mockClear();
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [OrganiseFeedRowComponent, provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse: signal(true) } },
        { provide: ActionSheet, useValue: { open: sheetOpen } },
      ],
    }).compileComponents();

    const coarse = TestBed.createComponent(OrganiseFeedRowComponent);
    coarse.componentRef.setInput('subscription', SUBSCRIPTION);
    coarse.detectChanges();

    coarse.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();

    expect(sheetOpen).toHaveBeenCalled();
    expect(coarse.debugElement.query(By.css('.pop'))).toBeNull();
  });
});
