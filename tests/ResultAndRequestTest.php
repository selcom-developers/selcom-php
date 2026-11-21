<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Tests;

use PHPUnit\Framework\TestCase;
use SelcomDevelopers\Selcom\Amounts;
use SelcomDevelopers\Selcom\Currency;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Operation;
use SelcomDevelopers\Selcom\OperationKind;
use SelcomDevelopers\Selcom\Request\Address;
use SelcomDevelopers\Selcom\Request\CreateOrderMinimalRequest;
use SelcomDevelopers\Selcom\Request\CreateOrderRequest;
use SelcomDevelopers\Selcom\Request\ListOrdersRequest;
use SelcomDevelopers\Selcom\Request\PaymentMethod;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Result\ResponseClassifier;
use SelcomDevelopers\Selcom\Signing\Hs256DigestSigner;
use SelcomDevelopers\Selcom\Signing\RequestSigner;
use SelcomDevelopers\Selcom\Status;
use SelcomDevelopers\Selcom\Tests\Support\Fixtures;

final class ResultAndRequestTest extends TestCase
{
    public function testEveryOperationIsDescribedConsistently(): void
    {
        self::assertCount(16, Operation::cases());
        foreach (Operation::cases() as $op) {
            self::assertStringStartsWith('/v1/', $op->path());
            self::assertContains($op->method(), ['GET', 'POST', 'DELETE']);
            // Every operation builds a request that signs cleanly.
            $params = Fixtures::request($op)->params(Fixtures::credentials());
            RequestSigner::sign($op->method(), $params, '2026-11-21T10:30:46+03:00', 'k', new Hs256DigestSigner('s'));
        }
        self::assertSame('/v1/checkout/create-order-minimal', Operation::CheckoutCreateOrderMinimal->path());
        self::assertSame('/v1/walletcashin/namelookup', Operation::WalletCashinNameLookup->path());
        self::assertSame('/v1/vendor/balance', Operation::VendorBalance->path());
        self::assertSame('DELETE', Operation::CheckoutDeleteCard->method());
    }

    public function testKindsAndRetryRules(): void
    {
        self::assertSame(OperationKind::Payment, Operation::CheckoutWalletPayment->kind());
        self::assertSame(OperationKind::Payment, Operation::WalletCashinProcess->kind());
        self::assertSame(OperationKind::StatusQuery, Operation::UtilityPaymentQuery->kind());
        self::assertSame(OperationKind::OrderStatus, Operation::CheckoutOrderStatus->kind());
        self::assertSame(OperationKind::Other, Operation::VendorBalance->kind());

        $retryable = array_filter(Operation::cases(), static fn (Operation $o): bool => $o->isRetryableWhenNotSent());
        self::assertEqualsCanonicalizing(
            [
                'checkout.order-status', 'checkout.list-orders', 'checkout.stored-cards',
                'utilitypayment.lookup', 'utilitypayment.query', 'walletcashin.namelookup',
                'walletcashin.query', 'vendor.balance',
            ],
            array_map(static fn (Operation $o): string => $o->value, array_values($retryable)),
        );
    }

    public function testResultCodeNormalisationAndOrderStatusMapping(): void
    {
        self::assertSame('000', ResponseClassifier::normaliseResultCode(0));
        self::assertSame('111', ResponseClassifier::normaliseResultCode(' 111 '));
        self::assertNull(ResponseClassifier::normaliseResultCode(true));
        self::assertNull(ResponseClassifier::normaliseResultCode(''));
        self::assertNull(ResponseClassifier::normaliseResultCode(-1));
        self::assertNull(ResponseClassifier::normaliseResultCode(0.0));

        foreach (['COMPLETED', 'complete', ' Completed '] as $s) {
            self::assertSame(Status::Success, ResponseClassifier::paymentStatus($s));
        }
        foreach (['PENDING', 'inprogress'] as $s) {
            self::assertSame(Status::Pending, ResponseClassifier::paymentStatus($s));
        }
        foreach (['CANCELLED', 'CANCELED', 'CANCEL', 'USERCANCELLED', 'USERCANCELED', 'REJECTED', 'FAILED'] as $s) {
            self::assertSame(Status::Failed, ResponseClassifier::paymentStatus($s));
        }
        self::assertSame(Status::Ambiguous, ResponseClassifier::paymentStatus('WEIRD'));
        self::assertSame(Status::Ambiguous, ResponseClassifier::paymentStatus(null));
    }

    public function testOrderStatusEdgeCases(): void
    {
        $kind = OperationKind::OrderStatus;
        self::assertSame(Status::NotFound, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":[]}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 200, '{"resultcode":"000"}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 200, '{"resultcode":"000","data":{"payment_status":"COMPLETED"}}')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify($kind, 500, '{"resultcode":"400"}')[0]);
        self::assertSame(Status::NotFound, ResponseClassifier::classify($kind, 404, '{"resultcode":"404"}')[0]);
        // A 111 whose text says SUCCESS is still in progress; a 000 with an empty result is a success.
        self::assertSame(Status::Pending, ResponseClassifier::classify(OperationKind::Payment, 200, '{"resultcode":"111","result":"SUCCESS"}')[0]);
        self::assertSame(Status::Success, ResponseClassifier::classify(OperationKind::Payment, 200, '{"resultcode":"000","result":""}')[0]);
        // No verdict on a payment is AMBIGUOUS, never FAILED.
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify(OperationKind::Payment, 200, '')[0]);
        self::assertSame(Status::Ambiguous, ResponseClassifier::classify(OperationKind::StatusQuery, 200, '[]')[0]);
    }

    public function testStatusFinality(): void
    {
        self::assertTrue(Status::Success->isFinal());
        self::assertTrue(Status::Failed->isFinal());
        self::assertTrue(Status::NotFound->isFinal());
        self::assertFalse(Status::Pending->isFinal());
        self::assertFalse(Status::Ambiguous->isFinal());
    }

    public function testAmountsAreParsedStrictly(): void
    {
        self::assertSame(10000, Amounts::parse('10,000'));
        self::assertSame(10000, Amounts::parse('10000'));
        self::assertSame(10000, Amounts::parse('10000.00'));
        self::assertSame(0, Amounts::parse('0'));
        self::assertSame(5, Amounts::parse(5));
        foreach (['10,00', '1,0000', '10.5', '-5', '1e3', ' ', '', 'abc', '10 000', '99999999999999999999'] as $bad) {
            self::assertNull(Amounts::tryParse($bad), $bad);
        }
        self::assertNull(Amounts::tryParse(10.5));
        self::assertNull(Amounts::tryParse(null));
        $this->expectException(\InvalidArgumentException::class);
        Amounts::parse('10.5');
    }

    public function testCreateOrderBuildsDottedBillingInOrderAndEncodesUrls(): void
    {
        $address = new Address('Test', 'Buyer', '1 Test Street', 'Dar es Salaam', 'Dar es Salaam', '11101', 'TZ', '255700000001', address2: 'Flat 2');
        $request = new CreateOrderRequest(
            'ORD-1001',
            'test.buyer@example.com',
            'Test Buyer',
            '255700000001',
            8000,
            Currency::TZS,
            [PaymentMethod::Card, PaymentMethod::MobileMoneyPull],
            $address,
            2,
            redirectUrl: 'https://shop.example.com/done',
            expiryMinutes: 30,
        );
        $params = $request->params(Fixtures::credentials());
        $signed = RequestSigner::sign('POST', $params, '2026-11-21T10:30:46+03:00', 'k', new Hs256DigestSigner('s'));

        self::assertSame(
            'vendor,order_id,buyer_email,buyer_name,buyer_phone,amount,currency,payment_methods,redirect_url,'
            . 'billing.firstname,billing.lastname,billing.address_1,billing.address_2,billing.city,'
            . 'billing.state_or_region,billing.postcode_or_pobox,billing.country,billing.phone,no_of_items,expiry',
            $signed->signedFields,
        );
        self::assertStringContainsString('payment_methods=CARD,MOBILEMONEYPULL&', $signed->signingString);
        self::assertStringContainsString('redirect_url=' . base64_encode('https://shop.example.com/done') . '&', $signed->signingString);
    }

    /** @return iterable<string, array{\Closure(): object}> */
    public static function invalidRequests(): iterable
    {
        $address = static fn (): Address => new Address('T', 'B', 'a', 'c', 's', 'p', 'TZ', '255700000001');
        yield 'zero amount' => [static fn () => new UtilityPaymentRequest('TXN-1', 'LUKU', 'ref', 0)];
        yield 'negative amount' => [static fn () => new UtilityPaymentRequest('TXN-1', 'LUKU', 'ref', -5)];
        yield 'empty transid' => [static fn () => new UtilityPaymentRequest(' ', 'LUKU', 'ref', 100)];
        yield 'blank msisdn' => [static fn () => new UtilityPaymentRequest('TXN-1', 'LUKU', 'ref', 100, ' ')];
        yield 'no payment methods' => [static fn () => new CreateOrderRequest('O', 'e', 'n', 'p', 1, Currency::TZS, [], $address(), 1)];
        yield 'blank billing city' => [static fn () => new Address('T', 'B', 'a', '', 's', 'p', 'TZ', '1')];
        yield 'bad webhook url' => [static fn () => new CreateOrderMinimalRequest('O', 'e', 'n', 'p', 1, Currency::TZS, 1, webhookUrl: 'javascript:alert(1)')];
        yield 'bad date' => [static fn () => new ListOrdersRequest('2026-13-01', '2026-11-21')];
        yield 'date format' => [static fn () => new ListOrdersRequest('21/11/2026', '2026-11-21')];
    }

    /**
     * @param \Closure(): object $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testInvalidRequestsAreRefusedBeforeAnythingIsBuilt(\Closure $build): void
    {
        $this->expectException(ValidationException::class);
        $build();
    }
}
