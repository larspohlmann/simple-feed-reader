<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function build(User $user, DigestModel $model): Email
    {
        $locale = $user->getLocale();
        $text = $this->textRenderer->render($model, $locale);

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
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

        return $email;
    }
}
