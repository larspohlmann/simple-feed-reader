import { TestBed } from '@angular/core/testing';
import { MarkedTextComponent } from './marked-text.component';

function mount(text: string, terms: string[]) {
  TestBed.configureTestingModule({ imports: [MarkedTextComponent] });
  const f = TestBed.createComponent(MarkedTextComponent);
  f.componentRef.setInput('text', text);
  f.componentRef.setInput('terms', terms);
  f.detectChanges();
  return f;
}

describe('MarkedTextComponent', () => {
  it('renders a real mark element containing the matched term', () => {
    const el = mount('hello world', ['world']).nativeElement as HTMLElement;
    const mark = el.querySelector('mark');
    expect(mark).not.toBeNull();
    expect(mark!.textContent).toBe('world');
  });

  it('renders text with no unmarked wrapping when there is no match', () => {
    const el = mount('hello world', ['xyz']).nativeElement as HTMLElement;
    expect(el.querySelector('mark')).toBeNull();
    expect(el.textContent).toContain('hello world');
  });

  it('renders a script-tag term as text, never as an actual element', () => {
    const el = mount('a <script>alert(1)</script> b', ['<script>']).nativeElement as HTMLElement;
    expect(el.querySelector('script')).toBeNull();
    expect(el.textContent).toContain('<script>');
    const mark = el.querySelector('mark');
    expect(mark!.textContent).toBe('<script>');
  });

  it('renders script-tag text content as text when it is not itself a term', () => {
    const el = mount('<script>evil()</script>', ['evil']).nativeElement as HTMLElement;
    expect(el.querySelector('script')).toBeNull();
    expect(el.textContent).toContain('<script>');
  });
});
