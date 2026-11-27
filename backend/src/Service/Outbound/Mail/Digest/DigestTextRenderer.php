<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest;

use App\Service\Outbound\Mail\Digest\Model\DigestEntryModel;
use App\Service\Outbound\Mail\Digest\Model\DigestGroupModel;
use App\Service\Outbound\Mail\Digest\Model\DigestGroupSource;
use App\Service\Outbound\Mail\Digest\Model\DigestModel;
use App\Service\Outbound\Mail\Digest\Model\DigestRenderedMailModel;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Renders a DigestModel to the subject and the plain-text body, which an HTML digest keeps as its alternative part. */
final readonly class DigestTextRenderer
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function render(DigestModel $model, string $locale): DigestRenderedMailModel
    {
        $subject = $this->translator->trans('digest.subject', ['%count%' => $model->totalCount], 'emails', $locale);

        $intro = $this->translator->trans('digest.intro', [], 'emails', $locale);
        $blocks = array_map(fn (DigestGroupModel $group): string => $this->group($group, $locale), $model->groups);
        $footer = $this->translator->trans('digest.footer', [], 'emails', $locale);

        $body = $intro . "\n\n" . implode("\n\n", $blocks) . "\n\n" . $footer;

        return new DigestRenderedMailModel($subject, $body);
    }

    private function group(DigestGroupModel $group, string $locale): string
    {
        $lines = array_map(fn (DigestEntryModel $entry): string => $this->entry($entry), $group->entries);
        $block = $this->heading($group, $locale) . "\n" . implode("\n", $lines);

        if (!$group->hasMore) {
            return $block;
        }

        return $block . "\n" . $this->moreLine($group, $locale);
    }

    private function heading(DigestGroupModel $group, string $locale): string
    {
        return match ($group->source) {
            DigestGroupSource::SavedSearch => $this->translator->trans(
                'digest.group_heading',
                ['%term%' => $group->term, '%count%' => $group->totalCount],
                'emails',
                $locale,
            ),
            DigestGroupSource::ForYouStream => $this->translator->trans(
                'digest.for_you_stream_heading',
                ['%count%' => $group->totalCount],
                'emails',
                $locale,
            ),
        };
    }

    private function moreLine(DigestGroupModel $group, string $locale): string
    {
        return match ($group->source) {
            DigestGroupSource::SavedSearch => $this->translator->trans(
                'digest.more',
                ['%url%' => $group->moreUrl],
                'emails',
                $locale,
            ),
            DigestGroupSource::ForYouStream => $this->translator->trans(
                'digest.for_you_stream_more_link',
                ['%count%' => $group->totalCount - \count($group->entries)],
                'emails',
                $locale,
            ) . ' → ' . $group->moreUrl,
        };
    }

    private function entry(DigestEntryModel $entry): string
    {
        $lines = [$this->headlineLine($entry)];

        if ('' !== $entry->shortDescription) {
            $lines[] = "  {$entry->shortDescription}";
        }

        $lines[] = "  {$entry->url}";

        return implode("\n", $lines);
    }

    private function headlineLine(DigestEntryModel $entry): string
    {
        return $entry->isUntitled() ? "• {$entry->feedName}" : "• {$entry->title} — {$entry->feedName}";
    }
}
