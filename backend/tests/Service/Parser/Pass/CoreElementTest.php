<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Pass;

use App\Service\Parser\Pass\CoreElement;
use PHPUnit\Framework\TestCase;

final class CoreElementTest extends TestCase
{
    private const string CORE_NAMESPACE = 'urn:example:core';

    public function testReadsTheTrimmedTextOfACoreChild(): void
    {
        $item = $this->core('<item xmlns="urn:example:core"><title> Hello </title></item>');

        self::assertSame('Hello', $item->text('title'));
    }

    public function testSkipsAnEmptyCoreChildForOneWithText(): void
    {
        $item = $this->core('<item xmlns="urn:example:core"><title>  </title><title>Second</title></item>');

        self::assertSame('Second', $item->text('title'));
    }

    public function testAPrefixedChildDoesNotShadowTheCoreOne(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core" xmlns:x="urn:example:other"><x:title>Wrong</x:title><title>Right</title></item>',
        );

        self::assertSame('Right', $item->text('title'));
    }

    public function testANullNamespaceReadsOnlyUnnamespacedChildren(): void
    {
        $item = new CoreElement(
            self::element('<item xmlns:x="urn:example:other"><x:link>https://wrong.example/</x:link><link>https://right.example/</link></item>'),
            null,
        );

        self::assertSame('https://right.example/', $item->text('link'));
    }

    public function testReadsTheFirstCoreChildThatIsAnHttpUrl(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core"><comments>not a url</comments><comments> https://example.com/c </comments></item>',
        );

        self::assertSame('https://example.com/c', $item->httpUrl('comments'));
    }

    public function testAnHttpUrlOutsideTheCoreNamespaceIsNotRead(): void
    {
        $item = $this->core(
            '<item xmlns="urn:example:core" xmlns:x="urn:example:other"><x:comments>https://example.com/c</x:comments></item>',
        );

        self::assertNull($item->httpUrl('comments'));
    }

    public function testFindsTheFirstCoreChildElement(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><x:author/><author><name>A</name></author></feed>',
        );

        self::assertSame(self::CORE_NAMESPACE, $feed->child('author')?->namespaceURI);
        self::assertNull($feed->child('missing'));
    }

    public function testListsEveryCoreChildElementInOrder(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><link href="a"/><x:link href="x"/><link href="b"/></feed>',
        );

        $hrefs = array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            iterator_to_array($feed->children('link'), false),
        );

        self::assertSame(['a', 'b'], $hrefs);
    }

    public function testAtReadsAnotherElementInTheSameNamespace(): void
    {
        $feed = $this->core(
            '<feed xmlns="urn:example:core" xmlns:x="urn:example:other"><author><x:name>Wrong</x:name><name>Right</name></author></feed>',
        );
        $author = $feed->child('author');
        self::assertNotNull($author);

        self::assertSame('Right', $feed->at($author)->text('name'));
    }

    private function core(string $xml): CoreElement
    {
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
