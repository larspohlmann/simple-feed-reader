<?php

declare(strict_types=1);

namespace App\Tests\Dto\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Dto\ClientError\ClientErrorReportRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ClientErrorReportRequestTest extends KernelTestCase
{
    public function testAcceptsASingleValidItem(): void
    {
        $request = new ClientErrorReportRequest([$this->item('boom')]);

        self::assertCount(0, $this->validator()->validate($request));
    }

    public function testRejectsAnEmptyBatch(): void
    {
        self::assertGreaterThan(0, $this->validator()->validate(new ClientErrorReportRequest([]))->count());
    }

    public function testRejectsMoreThanTenItems(): void
    {
        $items = array_fill(0, 11, $this->item('boom'));

        self::assertGreaterThan(0, $this->validator()->validate(new ClientErrorReportRequest($items))->count());
    }

    public function testCascadesToRejectAnItemWithABlankMessage(): void
    {
        $request = new ClientErrorReportRequest([$this->item('')]);

        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    public function testRejectsAnOversizedStack(): void
    {
        $request = new ClientErrorReportRequest([$this->item('boom', str_repeat('x', 8001))]);

        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    /**
     * Proves the whole point of this DTO: `#[MapRequestPayload]` deserializes
     * a JSON body the same way this test does, via SerializerInterface, and
     * relies on the `@param list<ClientErrorItem>` phpdoc alone to know each
     * array element denormalizes into a ClientErrorItem, not a plain array.
     */
    public function testDeserializingAJsonBatchHydratesEachItemAsAClientErrorItem(): void
    {
        $json = json_encode([
            'errors' => [
                [
                    'message' => 'boom',
                    'kind' => 'Error',
                    'url' => 'https://app.example/reader',
                    'route' => '/reader',
                    'buildVersion' => 'dev+local@',
                    'userAgent' => 'jest',
                    'at' => '2026-09-11T00:00:00.000Z',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = $this->serializer()->deserialize($json, ClientErrorReportRequest::class, 'json');

        self::assertInstanceOf(ClientErrorReportRequest::class, $request);
        self::assertInstanceOf(ClientErrorItem::class, $request->errors[0]);
        self::assertSame('boom', $request->errors[0]->message);
        self::assertSame('/reader', $request->errors[0]->route);
    }

    public function testAnElevenItemJsonBatchFailsCountAfterDeserializing(): void
    {
        $json = json_encode(['errors' => array_fill(0, 11, ['message' => 'boom'])], JSON_THROW_ON_ERROR);

        $request = $this->serializer()->deserialize($json, ClientErrorReportRequest::class, 'json');

        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    /**
     * Proves `#[Assert\Valid]` cascades into a deserialized item, not just a
     * hand-built one: the batch itself is valid, only the nested item is not.
     */
    public function testAnOversizedMessageFailsValidationThroughTheCascadeAfterDeserializing(): void
    {
        $json = json_encode(['errors' => [['message' => str_repeat('x', 2001)]]], JSON_THROW_ON_ERROR);

        $request = $this->serializer()->deserialize($json, ClientErrorReportRequest::class, 'json');

        self::assertInstanceOf(ClientErrorItem::class, $request->errors[0]);
        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        return $validator;
    }

    private function serializer(): SerializerInterface
    {
        self::bootKernel();
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);

        return $serializer;
    }

    private function item(string $message, ?string $stack = null): ClientErrorItem
    {
        return new ClientErrorItem(
            message: $message,
            stack: $stack,
            kind: 'Error',
            url: 'https://app.example/reader',
            route: '/reader',
            buildVersion: 'dev+local@',
            userAgent: 'jest',
            at: '2026-09-11T00:00:00.000Z',
        );
    }
}
