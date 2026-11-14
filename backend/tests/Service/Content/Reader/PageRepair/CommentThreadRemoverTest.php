<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\CommentThreadRemover;
use App\Tests\Support\RepairsPages;
use PHPUnit\Framework\TestCase;

final class CommentThreadRemoverTest extends TestCase
{
    use RepairsPages;

    private CommentThreadRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new CommentThreadRemover();
    }

    public function testRemovesTheNoScriptCommentListButKeepsTheDescription(): void
    {
        $html = '<article><p>New tune with my good mate Pogo.</p>'
            . '<section class="comments"><h2>Comment by <a href="/setareh">Setareh</a></h2>'
            . '<p>Next level sound design.</p><time pubdate>2026-08-08T20:20:06Z</time></section></article>';

        $result = $this->htmlAfterRepair($this->remover, $html);

        self::assertStringNotContainsString('Comment by', $result);
        self::assertStringNotContainsString('Next level sound design.', $result);
        self::assertStringContainsString('New tune with my good mate Pogo.', $result);
    }

    public function testRemovesTheWholeCommentThreadFamily(): void
    {
        $html = '<div class="comments-area">area</div>'
            . '<ol class="commentlist">list</ol>'
            . '<ol class="comment-list">dashed list</ol>'
            . '<div class="comment-respond">respond</div>'
            . '<form class="comment-form">form</form>'
            . '<p>Kept.</p>';

        $result = $this->htmlAfterRepair($this->remover, $html);

        foreach (['area', 'list', 'dashed list', 'respond', 'form'] as $removed) {
            self::assertStringNotContainsString('>' . $removed . '<', $result);
        }
        self::assertStringContainsString('Kept.', $result);
    }

    public function testMatchesAWholeClassTokenOnly(): void
    {
        $html = '<p class="comments-open">Kept one.</p><p class="nocomments">Kept two.</p>';

        $result = $this->htmlAfterRepair($this->remover, $html);

        self::assertStringContainsString('Kept one.', $result);
        self::assertStringContainsString('Kept two.', $result);
    }
}
