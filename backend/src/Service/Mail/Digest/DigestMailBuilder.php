<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the digest Email from a DigestModel, honouring the recipient's
 * digest_format: `text` sends the plain body only; `html` adds the airy HTML
 * body plus its CID images, keeping text as the alternative fallback (#726).
 */
final readonly class DigestMailBuilder
{
    public function __construct(
        private DigestPageBuilder $pageBuilder,
        private DigestImageEmbedderInterface $embedder,
        private DigestTextRenderer $textRenderer,
        private DigestHtmlRenderer $htmlRenderer,
        private DigestLinkBuilder $links,
        private DigestBrandLogo $brandLogo,
        private MailSettings $mailSettings,
    ) {
    }

    public function build(User $user, DigestModel $model): Email
    {
        $locale = $user->getLocale();
        $text = $this->textRenderer->render($model, $locale);

        $identity = $this->mailSettings->identity();
        $email = (new Email())
            ->from(new Address($identity->address, $identity->name))
            ->to($user->getEmail())
            ->subject($text->subject)
            ->text($text->body);

        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $this->links->settingsEmailUrl() . '>');

        if ($user->getPreferences()->getDigestFormat() === DigestFormat::Text) {
            return $email;
        }

        return $this->addHtml($email, $model, $locale);
    }

    private function addHtml(Email $email, DigestModel $model, string $locale): Email
    {
        $page = $this->pageBuilder->build($model, DigestPageBuilder::DEFAULT_MAX_CARDS);
        $images = $this->embedder->embed($page);

        $email->html($this->htmlRenderer->render($page, $images, $locale));
        foreach ($images->images as $image) {
            $email->embed($image->bytes, $image->cid, $image->contentType);
        }
        $email->embed($this->brandLogo->bytes(), DigestHtmlRenderer::LOGO_CID, $this->brandLogo->contentType());

        return $email;
    }
}
