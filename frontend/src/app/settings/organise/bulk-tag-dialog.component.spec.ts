import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { BulkTagDialogComponent, BulkTagDialogData } from './bulk-tag-dialog.component';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { makeSubscription } from '../../reader/testing/subscription.factory';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };
const NEWS: TagDto = { id: 3, name: 'Nachrichten', color: null, icon: null, position: 1 };

const feed = (id: number, tagIds: number[]): SubscriptionDto =>
  makeSubscription({
    id,
    feedId: id,
    title: `Feed ${id}`,
    feedUrl: `https://feed-${id}.example/rss`,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: tagId === TECH.id ? TECH.name : NEWS.name,
      color: null,
      icon: null,
      position: index,
    })),
  });

const SUB_WITH_TECH = feed(10, [TECH.id]);
const SUB_WITHOUT = feed(11, []);

describe('BulkTagDialogComponent', () => {
  let fixture: ComponentFixture<BulkTagDialogComponent>;

  async function render(data: BulkTagDialogData) {
    const close = jest.fn();
    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [BulkTagDialogComponent, provideTranslocoTesting()],
        providers: [
          { provide: DIALOG_DATA, useValue: data },
          { provide: DialogRef, useValue: { close } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(BulkTagDialogComponent);
    fixture.detectChanges();

    return { close };
  }

  it('counts how many of the selection already carry each tag', async () => {
    await render({ mode: 'add', subscriptions: [SUB_WITH_TECH, SUB_WITHOUT], tags: [TECH, NEWS] });

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('1/2');
    expect(text).toContain('0/2');
  });

  it('singularises the panel heading at a selection of one', async () => {
    await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });

    const heading = fixture.debugElement.query(By.css('h2')).nativeElement.textContent as string;
    expect(heading).toContain('1 feed');
    expect(heading).not.toContain('1 feeds');
  });

  it('counts affected() as the feeds that do not yet carry the tag in add mode', async () => {
    const SUB_WITHOUT_TOO = feed(13, []);
    await render({
      mode: 'add',
      subscriptions: [SUB_WITH_TECH, SUB_WITHOUT, SUB_WITHOUT_TOO],
      tags: [TECH],
    });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    // SUB_WITH_TECH already carries TECH (carried=1 of total=3); the other
    // two would change. A carried/total swap would read 1, not 2.
    expect(fixture.componentInstance.affected()).toBe(2);
  });

  it('counts affected() as the feeds that carry the tag in remove mode', async () => {
    const SUB_WITH_BOTH = feed(12, [TECH.id, NEWS.id]);
    await render({ mode: 'remove', subscriptions: [SUB_WITH_TECH, SUB_WITH_BOTH], tags: [TECH] });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.affected()).toBe(2);
  });

  it('offers every tag in add mode', async () => {
    await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH, NEWS] });

    expect(fixture.debugElement.queryAll(By.css('[data-test="tag-pill"]'))).toHaveLength(2);
  });

  it('offers only the tags the selection carries in remove mode', async () => {
    await render({ mode: 'remove', subscriptions: [SUB_WITH_TECH], tags: [TECH, NEWS] });

    const pills = fixture.debugElement.queryAll(By.css('[data-test="tag-pill"]'));
    expect(pills).toHaveLength(1);
    expect(pills[0].nativeElement.textContent).toContain('Tech');
  });

  it('warns how many feeds lose their last tag', async () => {
    await render({ mode: 'remove', subscriptions: [SUB_WITH_TECH], tags: [TECH] });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.losingLastTag()).toBe(1);
  });

  it('does not count a feed that keeps another tag as losing its last one', async () => {
    const SUB_WITH_BOTH = feed(12, [TECH.id, NEWS.id]);
    await render({
      mode: 'remove',
      subscriptions: [SUB_WITH_TECH, SUB_WITH_BOTH],
      tags: [TECH],
    });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    // SUB_WITH_TECH carries only TECH and would lose its last tag.
    // SUB_WITH_BOTH carries TECH and NEWS and keeps NEWS after the removal, so
    // it must not be counted.
    expect(fixture.componentInstance.losingLastTag()).toBe(1);
  });

  it('closes with the chosen tag on apply', async () => {
    const { close } = await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    fixture.debugElement.query(By.css('[data-test="apply"]')).nativeElement.click();

    expect(close).toHaveBeenCalledWith(TECH);
  });

  it('closes with nothing on cancel', async () => {
    const { close } = await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });

    fixture.debugElement.query(By.css('[data-test="cancel"]')).nativeElement.click();

    expect(close).toHaveBeenCalledWith(undefined);
  });

  it('discards a chosen tag on cancel instead of applying it', async () => {
    const { close } = await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });
    fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
    fixture.detectChanges();

    fixture.debugElement.query(By.css('[data-test="cancel"]')).nativeElement.click();

    expect(close).toHaveBeenCalledWith(undefined);
  });

  it('keeps apply disabled until a tag is chosen', async () => {
    await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });

    expect(
      fixture.debugElement.query(By.css('[data-test="apply"]')).componentInstance.disabled(),
    ).toBe(true);
  });
});
