<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Pass;

use App\Service\Parser\Pass\CoreElement;
use PHPUnit\Framework\TestCase;

final class CoreElementTest extends TestCase
{
    private const string CORE_NAMESPACE = 'urn:example:core';
    private const string OTHER_NAMESPACE_DECLARATION = ' xmlns:x="urn:example:other"';

    public function testReadsTheTrimmedTextOfACoreChild(): void
    {
        self::assertSame('Hello', $this->core('<title> Hello </title>')->text('title'));
    }

    public function testSkipsAnEmptyCoreChildForOneWithText(): void
    {
        self::assertSame('Second', $this->core('<title>  </title><title>Second</title>')->text('title'));
    }

    public function testAPrefixedChildDoesNotShadowTheCoreOne(): void
    {
        $item = $this->core('<x:title' . self::OTHER_NAMESPACE_DECLARATION . '>Wrong</x:title><title>Right</title>');

        self::assertSame('Right', $item->text('title'));
    }

    public function testANullNamespaceReadsOnlyUnnamespacedChildren(): void
    {
        $item = new CoreElement(
            self::element(
                '<item xmlns:x="urn:example:other">'
                . '<x:link>https://wrong.example/</x:link><link>https://right.example/</link>'
                . '</item>',
            ),
            null,
        );

        self::assertSame('https://right.example/', $item->text('link'));
    }

    public function testReadsTheFirstCoreChildThatIsAnHttpUrl(): void
    {
        $item = $this->core('<comments>not a url</comments><comments> https://example.com/c </comments>');

        self::assertSame('https://example.com/c', $item->httpUrl('comments'));
    }

    public function testAnHttpUrlOutsideTheCoreNamespaceIsNotRead(): void
    {
        $item = $this->core('<x:comments' . self::OTHER_NAMESPACE_DECLARATION . '>https://example.com/c</x:comments>');

        self::assertNull($item->httpUrl('comments'));
    }

    public function testFindsTheFirstCoreChildElement(): void
    {
        $feed = $this->core('<x:author' . self::OTHER_NAMESPACE_DECLARATION . '/><author><name>A</name></author>');

        self::assertSame(self::CORE_NAMESPACE, $feed->child('author')?->namespaceURI);
        self::assertNull($feed->child('missing'));
    }

    public function testListsEveryCoreChildElementInOrder(): void
    {
        $feed = $this->core('<link rel="a"/><x:link' . self::OTHER_NAMESPACE_DECLARATION . ' rel="x"/><link rel="b"/>');

        $relations = array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('rel'),
            iterator_to_array($feed->children('link'), false),
        );

        self::assertSame(['a', 'b'], $relations);
    }

    public function testAtReadsAnotherElementInTheSameNamespace(): void
    {
        $feed = $this->core(
            '<author><x:name' . self::OTHER_NAMESPACE_DECLARATION . '>Wrong</x:name><name>Right</name></author>',
        );
        $author = $feed->child('author');
        self::assertNotNull($author);

        self::assertSame('Right', $feed->at($author)->text('name'));
    }

    private function core(string $children): CoreElement
    {
        $xml = '<root xmlns="' . self::CORE_NAMESPACE . '">' . $children . '</root>';

        return new CoreElement(self::element($xml), self::CORE_NAMESPACE);
    }

    private static function element(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }
}
