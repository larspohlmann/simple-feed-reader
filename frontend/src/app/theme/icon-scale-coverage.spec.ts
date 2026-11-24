import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import {
  ASTWithSource,
  createCssSelectorFromNode,
  CssSelector,
  parseTemplate,
  SelectorMatcher,
  TmplAstBoundAttribute,
  TmplAstContent,
  TmplAstDeferredBlock,
  TmplAstElement,
  TmplAstForLoopBlock,
  TmplAstIfBlock,
  TmplAstSwitchBlock,
  TmplAstTemplate,
} from '@angular/compiler';

/**
 * #2524: inside a `text-scaled` container, an icon whose row's text scales sits in a
 * `scales-with-text` row. Walks every template from each `text-scaled` element through the
 * components, projections and template outlets it renders, and lists each icon no scaled row holds.
 * It sees only the tags in `ICON_TAGS` (a raw `material-symbols-outlined` span, or an `app-avatar`
 * without `size`, is invisible to it) and reads static `class` attributes, never bound classes.
 */
const SOURCE_ROOT = join(__dirname, '..', '..');
const ICON_TAGS = ['app-icon', 'app-favicon', 'app-tag-glyph', 'app-network-logo'];

type TemplateNode = ReturnType<typeof parseTemplate>['nodes'][number];

interface Exemption {
  file: string;
  tag: string;
  sites: number;
  reason: string;
}

interface Site {
  file: string;
  line: number;
  tag: string;
}

interface Component {
  file: string;
  nodes: TemplateNode[];
  scalesWithText: boolean;
}

/** The children a component element hands to its template, read in the parent's scope. */
interface Projection {
  nodes: TemplateNode[];
  from: Scope;
}

interface Scope {
  component: Component;
  outlets: Map<string, TmplAstTemplate>;
  projection: Projection | null;
}

interface Position {
  inScaledText: boolean;
  inScaledRow: boolean;
  path: readonly string[];
}

const EXEMPT: readonly Exemption[] = [
  {
    file: 'app/reader/article/entry-comments/entry-comments.component.html',
    tag: 'app-icon',
    sites: 1,
    reason: 'the "Load comments" list action is a button, so its label keeps the fixed type',
  },
  {
    file: 'app/reader/article/listen-row/listen-row.component.html',
    tag: 'app-icon',
    sites: 2,
    reason: 'the listen and playlist buttons size their labels with --fs-sm, fixed in a button',
  },
  {
    file: 'app/reader/article/reader-panel/reader-panel.component.html',
    tag: 'app-icon',
    sites: 1,
    reason: 'the panel toggle is a button, so its label keeps the fixed type',
  },
  {
    file: 'app/reader/entry/entry-actions/entry-actions.component.html',
    tag: 'app-icon',
    sites: 7,
    reason: "the article's action row is icon buttons alone, with no text of its own",
  },
  {
    file: 'app/reader/entry/entry-like/entry-like.component.html',
    tag: 'app-icon',
    sites: 2,
    reason: "the like button sits in the article's action row, whose text is fixed",
  },
  {
    file: 'app/reader/link-card/open-in-reader.component.html',
    tag: 'app-icon',
    sites: 1,
    reason: 'the open-in-reader label is a button sized with --fs-xs, fixed in a button',
  },
  {
    file: 'app/reader/list/magazine/source-group.component.html',
    tag: 'app-icon',
    sites: 2,
    reason: 'the "more from" toggle is a button sized with --fs-sm, fixed in a button',
  },
  {
    file: 'app/reader/list/magazine/story-sources/story-sources.component.html',
    tag: 'app-favicon',
    sites: 1,
    reason:
      "the mini card's tile favicon stands in for the outlet's picture, and pictures keep their size",
  },
  {
    file: 'app/reader/list/short-badge/short-badge.component.html',
    tag: 'app-icon',
    sites: 1,
    reason: 'a play badge over a thumbnail, with no visible text beside it',
  },
];

function sourceFiles(directory: string): string[] {
  return readdirSync(join(SOURCE_ROOT, directory), { withFileTypes: true }).flatMap((entry) => {
    const path = `${directory}/${entry.name}`;
    if (entry.isDirectory()) return sourceFiles(path);
    return entry.name.endsWith('.ts') && !entry.name.endsWith('.spec.ts') ? [path] : [];
  });
}

function readTemplate(
  file: string,
  source: string,
): { templateFile: string; markup: string } | null {
  const url = /templateUrl:\s*'([^']+)'/.exec(source);
  if (url) {
    const templateFile = relative(SOURCE_ROOT, join(SOURCE_ROOT, dirname(file), url[1]));
    return { templateFile, markup: readFileSync(join(SOURCE_ROOT, templateFile), 'utf8') };
  }
  const inline = /template:\s*`([\s\S]*?)`/.exec(source);
  return inline ? { templateFile: file, markup: inline[1] } : null;
}

function readComponents(): Map<string, Component> {
  const components = new Map<string, Component>();
  for (const file of sourceFiles('app')) {
    const source = readFileSync(join(SOURCE_ROOT, file), 'utf8');
    const selector = /@Component\(\{[\s\S]*?selector:\s*'([^']+)'/.exec(source);
    const template = readTemplate(file, source);
    if (!selector || !template) continue;
    const hostClass = /host:\s*\{[^}]*\bclass:\s*'([^']*)'/.exec(source)?.[1] ?? '';
    const parsed = parseTemplate(template.markup, template.templateFile, {
      preserveWhitespaces: false,
    });
    const component = {
      file: template.templateFile,
      nodes: parsed.nodes,
      scalesWithText: hostClass.split(/\s+/).includes('scales-with-text'),
    };
    for (const tag of selector[1].split(',').map((part) => part.trim())) {
      if (/^[a-z][\w-]*$/.test(tag)) components.set(tag, component);
    }
  }
  return components;
}

const COMPONENTS = readComponents();

function classesOf(element: TmplAstElement): string[] {
  return (element.attributes.find((attribute) => attribute.name === 'class')?.value ?? '')
    .split(/\s+/)
    .filter(Boolean);
}

function hasAttribute(template: TmplAstTemplate, name: string): boolean {
  return [...template.attributes, ...template.inputs].some((attribute) => attribute.name === name);
}

function outletName(bindings: TmplAstBoundAttribute[]): string | null {
  const outlet = bindings.find((binding) => binding.name === 'ngTemplateOutlet');
  if (!outlet) return null;
  const source = outlet.value instanceof ASTWithSource ? (outlet.value.source ?? '') : '';
  return /^\s*([A-Za-z_$][\w$]*)/.exec(source)?.[1] ?? null;
}

function deferredChildren(block: TmplAstDeferredBlock): TemplateNode[] {
  const states = [block.placeholder, block.loading, block.error];
  return [...block.children, ...states.flatMap((state) => state?.children ?? [])];
}

function blockChildren(node: TemplateNode): TemplateNode[] | null {
  if (node instanceof TmplAstIfBlock) return node.branches.flatMap((branch) => branch.children);
  if (node instanceof TmplAstForLoopBlock) {
    return [...node.children, ...(node.empty?.children ?? [])];
  }
  if (node instanceof TmplAstSwitchBlock) return node.cases.flatMap((each) => each.children);
  return node instanceof TmplAstDeferredBlock ? deferredChildren(node) : null;
}

function referencedTemplates(nodes: TemplateNode[], found = new Map<string, TmplAstTemplate>()) {
  for (const node of nodes) {
    if (node instanceof TmplAstTemplate) {
      for (const reference of node.references) found.set(reference.name, node);
      referencedTemplates(node.children, found);
    } else if (node instanceof TmplAstElement) {
      referencedTemplates(node.children, found);
    } else {
      referencedTemplates(blockChildren(node) ?? [], found);
    }
  }
  return found;
}

function projectable(nodes: TemplateNode[]): TemplateNode[] {
  return nodes.flatMap((node) => {
    const children = blockChildren(node);
    return children ? projectable(children) : [node];
  });
}

function matches(node: TemplateNode, select: string): boolean {
  if (!(node instanceof TmplAstElement || node instanceof TmplAstTemplate)) return false;
  const matcher = new SelectorMatcher<boolean>();
  matcher.addSelectables(CssSelector.parse(select), true);
  return matcher.match(createCssSelectorFromNode(node), () => undefined);
}

class Walker {
  readonly sites: Site[] = [];
  private readonly visited = new Set<string>();

  walkComponent(component: Component, at: Position, projection: Projection | null): void {
    const key = `${component.file}|${at.inScaledText}|${at.inScaledRow}`;
    if (projection === null) {
      if (this.visited.has(key)) return;
      this.visited.add(key);
    }
    const outlets = referencedTemplates(component.nodes);
    this.walkAll(component.nodes, at, { component, outlets, projection });
  }

  private walkAll(nodes: TemplateNode[], at: Position, scope: Scope): void {
    for (const node of nodes) this.walk(node, at, scope);
  }

  private walk(node: TemplateNode, at: Position, scope: Scope): void {
    if (node instanceof TmplAstElement) return this.walkElement(node, at, scope);
    if (node instanceof TmplAstTemplate) return this.walkTemplate(node, at, scope);
    if (node instanceof TmplAstContent) return this.walkContent(node, at, scope);
    const children = blockChildren(node);
    if (children) this.walkAll(children, at, scope);
  }

  private walkElement(element: TmplAstElement, at: Position, scope: Scope): void {
    const here = positionInside(element, at);
    if (ICON_TAGS.includes(element.name)) return this.checkIcon(element, here, scope);
    this.walkOutlet(outletName(element.inputs), here, scope);
    const component = COMPONENTS.get(element.name);
    if (component && !at.path.includes(element.name)) {
      const inside = { ...here, path: [...at.path, element.name] };
      this.walkComponent(component, inside, { nodes: projectable(element.children), from: scope });
      return;
    }
    this.walkAll(element.children, here, scope);
  }

  private checkIcon(icon: TmplAstElement, at: Position, scope: Scope): void {
    if (!at.inScaledText || at.inScaledRow || sizedByText(icon)) return;
    const line = icon.sourceSpan.start.line + 1;
    this.sites.push({ file: scope.component.file, line, tag: icon.name });
  }

  private walkTemplate(template: TmplAstTemplate, at: Position, scope: Scope): void {
    if (template.tagName === 'ng-template' && template.references.length > 0) return;
    if (hasAttribute(template, 'cdkConnectedOverlay')) {
      this.walkAll(template.children, { ...at, inScaledText: false, inScaledRow: false }, scope);
      return;
    }
    this.walkOutlet(outletName(template.templateAttrs.filter(isBound)), at, scope);
    this.walkAll(template.children, at, scope);
  }

  private walkOutlet(name: string | null, at: Position, scope: Scope): void {
    if (name === null) return;
    if (scope.outlets.has(name)) return this.walkLocalTemplate(name, at, scope);
    const projection = scope.projection;
    if (!projection) return;
    const slots = projection.nodes.filter(
      (node): node is TmplAstTemplate =>
        node instanceof TmplAstTemplate &&
        node.attributes.some((attribute) => attribute.value === name),
    );
    this.walkAll(
      slots.flatMap((slot) => slot.children),
      at,
      projection.from,
    );
  }

  private walkLocalTemplate(name: string, at: Position, scope: Scope): void {
    const template = scope.outlets.get(name);
    const step = `${scope.component.file}#${name}`;
    if (!template || at.path.includes(step)) return;
    this.walkAll(template.children, { ...at, path: [...at.path, step] }, scope);
  }

  private walkContent(content: TmplAstContent, at: Position, scope: Scope): void {
    const projection = scope.projection;
    if (!projection) return;
    const named = contentSelectors(scope.component.nodes).filter((selector) => selector !== '*');
    const selected =
      content.selector === '*'
        ? projection.nodes.filter((node) => !named.some((selector) => matches(node, selector)))
        : projection.nodes.filter((node) => matches(node, content.selector));
    this.walkAll(selected, at, projection.from);
  }
}

function positionInside(element: TmplAstElement, at: Position): Position {
  const classes = classesOf(element);
  const row =
    classes.includes('scales-with-text') || COMPONENTS.get(element.name)?.scalesWithText === true;
  return {
    inScaledText: at.inScaledText || classes.includes('text-scaled'),
    inScaledRow: at.inScaledRow || (row && at.inScaledText),
    path: at.path,
  };
}

function sizedByText(element: TmplAstElement): boolean {
  return element.attributes.some(
    (attribute) => attribute.name === 'size' && attribute.value === 'text',
  );
}

function isBound(attribute: unknown): attribute is TmplAstBoundAttribute {
  return attribute instanceof TmplAstBoundAttribute;
}

function contentSelectors(nodes: TemplateNode[]): string[] {
  return nodes.flatMap((node) => {
    if (node instanceof TmplAstContent) return [node.selector];
    if (node instanceof TmplAstElement || node instanceof TmplAstTemplate) {
      return contentSelectors(node.children);
    }
    return contentSelectors(blockChildren(node) ?? []);
  });
}

function collectSites(): Site[] {
  const walker = new Walker();
  const start: Position = { inScaledText: false, inScaledRow: false, path: [] };
  for (const [tag, component] of COMPONENTS) {
    walker.walkComponent(component, { ...start, path: [tag] }, null);
  }
  const unique = new Map(walker.sites.map((site) => [`${site.file}:${site.line}`, site]));
  return [...unique.values()].sort((left, right) =>
    `${left.file}:${left.line}`.localeCompare(`${right.file}:${right.line}`),
  );
}

const SITES = collectSites();

const covers = (exemption: Exemption, site: Site): boolean =>
  exemption.file === site.file && exemption.tag === site.tag;

describe('icon scale coverage', () => {
  it('leaves no icon beside scaling text outside a scaled row', () => {
    const bare = SITES.filter((site) => !EXEMPT.some((exemption) => covers(exemption, site)));
    expect(bare.map(({ file, line, tag }) => `${file}:${line} <${tag}>`)).toEqual([]);
  });

  it.each(EXEMPT)('exempts exactly $sites <$tag> in $file', (exemption) => {
    const sites = SITES.filter((site) => covers(exemption, site)).length;
    expect({ file: exemption.file, sites }).toEqual({
      file: exemption.file,
      sites: exemption.sites,
    });
  });
});
