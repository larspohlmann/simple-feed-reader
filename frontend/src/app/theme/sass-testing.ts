import * as sass from 'sass';

export type Declarations = Record<string, string>;

export interface CssRule {
  selector: string;
  declarations: Declarations;
}

const withoutComments = (css: string): string => css.replace(/\/\*[\s\S]*?\*\//g, '');

export function compileScssFile(path: string, loadPaths: string[] = []): string {
  return withoutComments(sass.compile(path, { loadPaths, style: 'expanded' }).css);
}

export function compileScssSource(source: string, loadPaths: string[]): string {
  return withoutComments(sass.compileString(source, { loadPaths, style: 'expanded' }).css);
}

const collapsed = (text: string): string => text.replace(/\s+/g, ' ').trim();

function declarationsOf(body: string): Declarations {
  const declarations: Declarations = {};
  for (const declaration of body.split(';')) {
    const colon = declaration.indexOf(':');
    if (colon > 0) {
      declarations[declaration.slice(0, colon).trim()] = collapsed(declaration.slice(colon + 1));
    }
  }
  return declarations;
}

export function cssRules(css: string): CssRule[] {
  const bare = css.replace(/^@charset [^;]*;/, '');
  return Array.from(bare.matchAll(/([^{}]+)\{([^{}]*)\}/g), ([, selector, body]) => ({
    selector: collapsed(selector),
    declarations: declarationsOf(body),
  }));
}

export function rulesBySelector(css: string): Record<string, Declarations> {
  return Object.fromEntries(
    cssRules(css).map(({ selector, declarations }) => [selector, declarations]),
  );
}
