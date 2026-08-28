<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a DigestModel to the plain-text subject and body an email carries
 * (#636). Plain text on purpose, matching AccountMailer: the API renders no
 * HTML anywhere else, and plain bodies survive every client.
 */
final readonly class DigestTextRenderer
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function render(DigestModel $model, string $locale): DigestRenderedMail
    {
        $subject = $this->translator->trans('digest.subject', ['%count%' => $model->totalCount], 'emails', $locale);

        $intro = $this->translator->trans('digest.intro', [], 'emails', $locale);
        $blocks = array_map(fn (DigestGroup $group): string => $this->group($group, $locale), $model->groups);
        $footer = $this->translator->trans('digest.footer', [], 'emails', $locale);

        $body = $intro . "\n\n" . implode("\n\n", $blocks) . "\n\n" . $footer;

        return new DigestRenderedMail($subject, $body);
    }

    private function group(DigestGroup $group, string $locale): string
    {
        $heading = $this->translator->trans(
            'digest.group_heading',
            ['%term%' => $group->term, '%count%' => $group->totalCount],
            'emails',
            $locale,
        );

        $lines = array_map(fn (DigestEntry $entry): string => $this->entry($entry), $group->entries);
        $block = $heading . "\n" . implode("\n", $lines);

        if (!$group->hasMore) {
            return $block;
        }

        return $block . "\n" . $this->translator->trans(
            'digest.more',
            ['%url%' => $group->moreUrl],
            'emails',
            $locale,
        );
    }

    private function entry(DigestEntry $entry): string
    {
        $lines = ["• {$entry->title} — {$entry->feedName}"];

        if ('' !== $entry->shortDescription) {
            $lines[] = "  {$entry->shortDescription}";
        }

        $lines[] = "  {$entry->url}";

        return implode("\n", $lines);
    }
}
