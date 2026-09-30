import { TestBed } from '@angular/core/testing';
import { MarkedTextComponent } from './marked-text.component';

function mount(text: string, terms: string[]) {
  TestBed.configureTestingModule({ imports: [MarkedTextComponent] });
  const fixture = TestBed.createComponent(MarkedTextComponent);
  fixture.componentRef.setInput('text', text);
  fixture.componentRef.setInput('terms', terms);
  fixture.detectChanges();
  return fixture;
}

describe('MarkedTextComponent', () => {
  it('renders a real mark element containing the matched term', () => {
    const element = mount('hello world', ['world']).nativeElement as HTMLElement;
    const mark = element.querySelector('mark');
    expect(mark).not.toBeNull();
    expect(mark!.textContent).toBe('world');
  });

  it('renders text with no unmarked wrapping when there is no match', () => {
    const element = mount('hello world', ['xyz']).nativeElement as HTMLElement;
    expect(element.querySelector('mark')).toBeNull();
    expect(element.textContent).toContain('hello world');
  });

  it('renders a script-tag term as text, never as an actual element', () => {
    const element = mount('a <script>alert(1)</script> b', ['<script>'])
      .nativeElement as HTMLElement;
    expect(element.querySelector('script')).toBeNull();
    expect(element.textContent).toContain('<script>');
    const mark = element.querySelector('mark');
    expect(mark!.textContent).toBe('<script>');
  });

  it('renders script-tag text content as text when it is not itself a term', () => {
    const element = mount('<script>evil()</script>', ['evil']).nativeElement as HTMLElement;
    expect(element.querySelector('script')).toBeNull();
    expect(element.textContent).toContain('<script>');
  });
});
