import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { CatalogCategoryDto } from './catalog.models';
import { CategoryRailComponent } from './category-rail.component';

const category = (over: Partial<CatalogCategoryDto>): CatalogCategoryDto => ({
  id: 1,
  key: 'technology',
  name: 'Technology',
  icon: 'memory',
  color: '#3b82f6',
  feeds: [],
  ...over,
});

describe('CategoryRailComponent', () => {
  let fixture: ComponentFixture<CategoryRailComponent>;

  const mount = async (categories: CatalogCategoryDto[]) => {
    await TestBed.configureTestingModule({
      imports: [CategoryRailComponent, provideTranslocoTesting()],
    }).compileComponents();

    fixture = TestBed.createComponent(CategoryRailComponent);
    fixture.componentRef.setInput('categories', categories);
    fixture.componentRef.setInput('picked', {});
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  };

  it('renders the tinted glyph for a category that has an icon', async () => {
    const el = await mount([category({ icon: 'memory', color: '#3b82f6' })]);
    const lead = el.querySelector('.lead') as HTMLElement;

    expect(lead.querySelector('.material-symbols-outlined')?.textContent?.trim()).toBe('memory');
    expect(lead.querySelector('.dot')).toBeNull();
  });

  /* #126: the rail used to render a bare <app-icon>, so a category carrying a
     colour but no icon painted an empty box. The shared glyph falls back to the
     colour dot, which is what keeps such a category identifiable. */
  it('falls back to the colour dot for a category with a colour but no icon', async () => {
    const el = await mount([category({ icon: '', color: '#c08a3e' })]);
    const lead = el.querySelector('.lead') as HTMLElement;

    expect(lead.querySelector('.material-symbols-outlined')).toBeNull();
    expect((lead.querySelector('.dot') as HTMLElement).style.background).toBeTruthy();
  });
});
