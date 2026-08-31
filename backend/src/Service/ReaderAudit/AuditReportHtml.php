<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Renders the audit as one self-contained HTML page: the ranked candidates with
 * a link that opens each one in the running SPA, the feeds that fail most often,
 * and the stages those failures point at.
 *
 * Plain string building rather than Twig — this page is a developer tool with no
 * route, no layout to inherit and no translation, and a template would put half
 * of it in a second file for nothing.
 */
final readonly class AuditReportHtml
{
    public function __construct(private int $maxCandidates = 300)
    {
    }

    public function render(AuditFindings $findings, string $generatedAt): string
    {
        return self::PAGE_HEAD
            . $this->summary($findings, $generatedAt)
            . $this->suspects($findings)
            . $this->feeds($findings)
            . $this->candidates($findings)
            . '</body>';
    }

    private function summary(AuditFindings $findings, string $generatedAt): string
    {
        $flagged = \count($findings->ranked());

        return \sprintf(
            '<h1>Reader cleanup audit</h1><p class="meta">%s — %d articles over %d feeds;'
            . ' %d extracted, %d flagged (%d%%)</p>',
            $this->escape($generatedAt),
            $findings->audited(),
            $findings->feedCount(),
            $findings->extracted(),
            $flagged,
            $findings->audited() === 0 ? 0 : (int) round(100 * $flagged / $findings->audited()),
        );
    }

    private function suspects(AuditFindings $findings): string
    {
        $rows = '';
        foreach ($findings->tally(static fn (CleanupMarker $m): string => $m->suspect) as $suspect => $count) {
            $rows .= \sprintf('<tr><td>%s</td><td class="n">%d</td></tr>', $this->escape($suspect), $count);
        }

        return '<h2>Where to look first</h2><table><thead><tr><th>Stage</th>'
            . '<th class="n">Articles</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function feeds(AuditFindings $findings): string
    {
        $rows = '';
        foreach ($findings->byFeed() as $feed) {
            $rows .= \sprintf(
                '<tr><td>%s</td><td class="n">%d</td><td class="n">%d</td>'
                . '<td class="n">%d%%</td><td class="n">%d</td></tr>',
                $this->escape($feed['feed']),
                $feed['audited'],
                $feed['flagged'],
                (int) round($feed['share'] * 100),
                $feed['worst'],
            );
        }

        return '<h2>Feeds by failure rate</h2><table><thead><tr><th>Feed</th><th class="n">Audited</th>'
            . '<th class="n">Flagged</th><th class="n">Share</th><th class="n">Worst score</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    private function candidates(AuditFindings $findings): string
    {
        $ranked = \array_slice($findings->ranked(), 0, $this->maxCandidates);

        $items = '';
        foreach ($ranked as $finding) {
            $items .= $this->candidate($finding);
        }

        $heading = \sprintf(
            '<h2>Candidates (%d worst of %d flagged)</h2>',
            \count($ranked),
            \count($findings->ranked()),
        );

        return $heading . $items;
    }

    private function candidate(AuditFinding $finding): string
    {
        return \sprintf(
            '<article><h3><span class="score">%d</span> <a href="%s">%s</a></h3>'
            . '<p class="meta">%s — <a class="src" href="%s">source page</a> — %s</p>%s</article>',
            $finding->score(),
            $this->escape($finding->readerLink),
            $this->escape($finding->title),
            $this->escape($finding->feedTitle),
            $this->escape($finding->sourceUrl),
            $this->escape($this->metricLine($finding)),
            $this->markers($finding),
        );
    }

    private function markers(AuditFinding $finding): string
    {
        $items = '';
        foreach ($finding->markers as $marker) {
            $items .= \sprintf(
                '<li><code>%s</code> <span class="suspect">%s</span><br><span class="detail">%s</span></li>',
                $this->escape($marker->code),
                $this->escape($marker->suspect),
                $this->escape($marker->detail),
            );
        }

        return '<ul class="markers">' . $items . '</ul>';
    }

    private function metricLine(AuditFinding $finding): string
    {
        $parts = [];
        foreach ($finding->metrics as $name => $value) {
            $parts[] = $name . ' ' . $value;
        }

        return implode(', ', $parts);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private const string PAGE_HEAD = <<<'HTML'
        <!doctype html><meta charset="utf-8"><title>Reader cleanup audit</title>
        <style>
        body{font:15px/1.5 system-ui,sans-serif;margin:2rem auto;max-width:60rem;
        padding:0 1rem;color:#111;background:#fff}
        h1{margin-bottom:.2rem}h2{margin-top:2.5rem;border-bottom:1px solid #ddd;padding-bottom:.3rem}
        .meta{color:#666;font-size:.85rem;margin:.2rem 0}
        table{border-collapse:collapse;width:100%;font-size:.9rem}
        th,td{border-bottom:1px solid #eee;padding:.35rem .5rem;text-align:left}
        td.n,th.n{text-align:right;white-space:nowrap}
        article{border-top:1px solid #eee;padding:.8rem 0}
        h3{margin:.2rem 0;font-size:1rem;font-weight:600}
        .score{display:inline-block;min-width:1.8rem;padding:0 .35rem;border-radius:.25rem;
        background:#c62828;color:#fff;text-align:center;font-size:.8rem}
        a{color:#0b57d0}a.src{color:#666}
        ul.markers{margin:.4rem 0 0;padding-left:1.1rem;font-size:.85rem}
        li{margin:.15rem 0}code{background:#f2f2f2;padding:0 .25rem;border-radius:.2rem}
        .suspect{color:#8a4b00}.detail{color:#555}
        @media (prefers-color-scheme:dark){body{background:#111;color:#eee}h2{border-color:#333}
        th,td,article{border-color:#282828}code{background:#222}a{color:#8ab4f8}
        .detail{color:#aaa}.suspect{color:#e0a458}}
        </style><body>
        HTML;
}
