<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\AudioPlayerMarkup;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Service\Reader\Media\Support\LiveAudioStream;
use Dom\Element;

/** Rebuilds every body <audio> into the one shape AudioPlayerMarkup emits; a live stream or a silent player goes. */
final readonly class AudioPlayerNormalizer implements BodyCleaningStepInterface
{
    public function __construct(private AudioPlayerMarkup $markup)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach (iterator_to_array($pass->document->querySelectorAll('audio')) as $player) {
            $clip = $this->clipOf($player);
            if ($clip === null) {
                $player->remove();
                continue;
            }
            $player->replaceWith($this->markup->element($pass->document, $clip));
        }
    }

    private function clipOf(Element $player): ?AudioClipModel
    {
        $url = $this->sourceOf($player);
        if ($url === null || LiveAudioStream::is($url)) {
            return null;
        }

        return new AudioClipModel(
            $url,
            $player->getAttribute('title'),
            AudioClipModel::secondsFrom($player->getAttribute('data-duration')),
            $player->classList->contains('reader-narration'),
        );
    }

    private function sourceOf(Element $player): ?string
    {
        $own = trim($player->getAttribute('src') ?? '');
        if ($own !== '') {
            return $own;
        }
        foreach ($player->getElementsByTagName('source') as $source) {
            if ($source->parentNode !== $player) {
                continue;
            }
            $type = $source->getAttribute('type') ?? '';
            $url = trim($source->getAttribute('src') ?? '');
            if ($url !== '' && ($type === '' || str_starts_with($type, 'audio/'))) {
                return $url;
            }
        }

        return null;
    }
}
