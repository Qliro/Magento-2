<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Logger;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Redactor;
use Qliro\QliroOne\Model\Logger\SecretProvider;

/**
 * @see \Qliro\QliroOne\Model\Logger\Redactor
 */
class RedactorTest extends TestCase
{
    private const API_KEY = 'live-8f14e45fceea167a5a36dedd4bea2543';
    private const API_SECRET = 'c20ad4d76fe97759aa27a0c99bff6710';
    private const EMAIL = 'anna.andersson@example.com';
    private const IDENTITY = '19850101-1234';
    private const PHONE = '+46701234567';

    private Redactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new Redactor();
    }

    /**
     * The case the ticket names: a create order payload carries the API key the service injects and
     * the whole customer record. Nothing of either may be readable in what is written.
     */
    public function testACreateOrderPayloadComesOutWithNoCredentialAndNoCustomerLeft(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'reference' => 'qliroone-42',
            'extra' => [
                'uri' => 'https://pago.qit.nu/checkout/merchantapi/orders',
                'body' => $this->createOrderPayload(),
            ],
        ]);

        $written = json_encode($context);

        foreach ([self::API_KEY, self::API_SECRET, self::EMAIL, self::IDENTITY, '19850101', 'Andersson'] as $secret) {
            self::assertStringNotContainsString($secret, $written, sprintf('%s is readable in the log', $secret));
        }

        $body = $context['extra']['body'];
        self::assertSame(Redactor::MASK, $body['MerchantApiKey']);
        self::assertSame(Redactor::MASK, $body['Customer']['Email']);
        self::assertSame(Redactor::MASK, $body['Customer']['PersonalNumber']);
        self::assertSame(Redactor::MASK, $body['Customer']['Address']['LastName']);
        self::assertSame(Redactor::MASK, $body['Customer']['Address']['Street']);
    }

    /**
     * A log line has to stay worth reading, so everything that is neither a credential nor personal
     * is left exactly as it was.
     */
    public function testLeavesEverythingThatIsNeitherACredentialNorPersonal(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => [
                'uri' => 'https://pago.qit.nu/checkout/merchantapi/orders',
                'body' => $this->createOrderPayload(),
            ],
        ]);

        $body = $context['extra']['body'];

        self::assertSame('qliroone-42', $body['MerchantReference']);
        self::assertSame('Physical', $body['Customer']['JuridicalType']);
        self::assertSame('SE', $body['Customer']['Address']['CountryCode']);
        self::assertSame('SEK', $body['Currency']);
        self::assertSame('T-Shirt', $body['OrderItems'][0]['Description']);
        self::assertSame(199.0, $body['OrderItems'][0]['PricePerItemIncVat']);
        self::assertSame('https://pago.qit.nu/checkout/merchantapi/orders', $context['extra']['uri']);
    }

    /**
     * The module's own bookkeeping is not payload: the merchant reference is what makes a log line
     * findable, and the tags are what this class reads to decide how far to go.
     */
    public function testKeepsTheReferenceAndTheTagsItIsGiven(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => 'sensitive,checkout',
            'reference' => 'qliroone-42',
            'process_id' => 1234567890,
            'mark' => 'REST API',
        ]);

        self::assertSame('sensitive,checkout', $context['tags']);
        self::assertSame('qliroone-42', $context['reference']);
        self::assertSame(1234567890, $context['process_id']);
        self::assertSame('REST API', $context['mark']);
    }

    /**
     * A key naming a credential is masked under any spelling and at any depth, because the point
     * is that a name nobody thought of here still cannot write a secret out.
     *
     * @dataProvider credentialKeyProvider
     */
    public function testMasksACredentialWhateverItIsCalled(string $key): void
    {
        $context = $this->redactor->redactContext(['extra' => [$key => self::API_KEY]]);

        self::assertSame(Redactor::MASK, $context['extra'][$key]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function credentialKeyProvider(): array
    {
        return [
            'the key the service injects' => ['MerchantApiKey'],
            'snake case' => ['merchant_api_key'],
            'the secret' => ['MerchantApiSecret'],
            'a header' => ['Authorization'],
            'a bearer token' => ['access_token'],
            'a password' => ['Password'],
            'a name nobody listed' => ['CallbackSharedSecret'],
        ];
    }

    /**
     * A personal field is masked under the spellings the two sides of the integration use, Qliro's
     * own and Magento's, because a payload reaches the log from both.
     *
     * @dataProvider personalKeyProvider
     */
    public function testMasksAPersonalFieldWhateverItIsCalled(string $key, string $value): void
    {
        $context = $this->redactor->redactContext(['extra' => [$key => $value]]);

        self::assertSame(Redactor::MASK, $context['extra'][$key]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function personalKeyProvider(): array
    {
        return [
            'the qliro street' => ['Street', 'Storgatan 1'],
            'a numbered street line' => ['Street1', 'Storgatan 1'],
            'magento street' => ['street', 'Storgatan 1'],
            'the city' => ['City', 'Stockholm'],
            'a town' => ['Town', 'Stockholm'],
            'the postal code' => ['PostalCode', '11122'],
            'magento postcode' => ['postcode', '11122'],
            'the mobile number' => ['MobileNumber', '+46701234567'],
            'a bare mobile' => ['Mobile', '0701234567'],
            'magento telephone' => ['telephone', '0701234567'],
            'the identity number' => ['PersonalNumber', '19850101-1234'],
            'an identity number under another name' => ['SSN', '198501011234'],
            'the care of' => ['CareOf', 'Anna Andersson'],
            'the company' => ['CompanyName', 'Andersson AB'],
            'magento first name' => ['customer_firstname', 'Anna'],
            'magento last name' => ['customer_lastname', 'Andersson'],
            'a middle name' => ['middlename', 'M'],
            'a vat number under another name' => ['vat_number', 'SE556'],
            'magento taxvat, where a swedish store keeps the identity number' => ['taxvat', '8501011234'],
            'the same on a customer array' => ['customer_taxvat', '8501011234'],
            'magento vat id' => ['vat_id', 'SE556'],
            'magento date of birth' => ['dob', '1985-01-01'],
            'magento company' => ['company', 'Andersson AB'],
        ];
    }

    /**
     * A credential holding a structure is masked whole, rather than walked into.
     */
    public function testMasksACredentialThatHoldsAStructure(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => ['Authorization' => ['scheme' => 'QliroOne', 'value' => self::API_SECRET]],
        ]);

        self::assertSame(Redactor::MASK, $context['extra']['Authorization']);
    }

    /**
     * An email and an identity number give themselves away wherever they sit, so they are masked
     * with no tag set at all: a callback body carries a customer record and sets none.
     */
    public function testMasksAnEmailAndAnIdentityNumberWithoutTheTag(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => [
                'body' => [
                    'Comment' => sprintf('called %s about %s', self::EMAIL, self::IDENTITY),
                    'CustomerEmail' => self::EMAIL,
                ],
            ],
        ]);

        self::assertSame(Redactor::MASK, $context['extra']['body']['CustomerEmail']);
        self::assertSame(
            sprintf('called %s about %s', Redactor::MASK, Redactor::MASK),
            $context['extra']['body']['Comment']
        );
    }

    /**
     * An identity number written as twelve digits is masked with no tag at all, because nothing
     * else in these payloads looks like a century followed by a date. A phone number is what the
     * tag buys: a run of digits with a plus in front is otherwise too easy to confuse with an id.
     */
    public function testMasksAnIdentityNumberAlwaysAndAPhoneNumberOnATaggedLine(): void
    {
        $body = ['Comment' => sprintf('%s called from %s', '198501011234', self::PHONE)];

        $untagged = $this->redactor->redactContext(['extra' => ['body' => $body]]);
        $tagged = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => ['body' => $body],
        ]);

        self::assertStringNotContainsString('198501011234', $untagged['extra']['body']['Comment']);
        self::assertStringContainsString(self::PHONE, $untagged['extra']['body']['Comment']);
        self::assertStringNotContainsString('198501011234', $tagged['extra']['body']['Comment']);
        self::assertStringNotContainsString(self::PHONE, $tagged['extra']['body']['Comment']);
    }

    /**
     * A failed request reaches the log as an exception message with the body serialized into it,
     * which is the one place a payload arrives as text rather than as an array.
     */
    public function testMasksAPayloadSerializedIntoAMessage(): void
    {
        $message = 'Client error: `POST /checkout/merchantapi/orders` response: '
            . '{"MerchantApiKey":"' . self::API_KEY . '","Customer":{"Email":"' . self::EMAIL . '",'
            . '"PersonalNumber":"' . self::IDENTITY . '","JuridicalType":"Physical"}}';

        $redacted = $this->redactor->redactMessage($message);

        self::assertStringNotContainsString(self::API_KEY, $redacted);
        self::assertStringNotContainsString(self::EMAIL, $redacted);
        self::assertStringNotContainsString(self::IDENTITY, $redacted);
        self::assertStringContainsString('"JuridicalType":"Physical"', $redacted);
        self::assertStringContainsString('POST /checkout/merchantapi/orders', $redacted);
    }

    /**
     * A callback body that could not be parsed is logged as the text it arrived as, so the keys
     * spelled out in that text are masked as well as the ones in an array.
     */
    public function testMasksAPayloadThatArrivedAsUnparsedText(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => [
                'payload' => [
                    'raw_body' => '{"OrderId":123,"Customer":{"LastName":"Andersson","Email":"'
                        . self::EMAIL . '"},',
                    'exception' => 'Unable to unserialize value.',
                ],
            ],
        ]);

        $rawBody = $context['extra']['payload']['raw_body'];

        self::assertStringNotContainsString('Andersson', $rawBody);
        self::assertStringNotContainsString(self::EMAIL, $rawBody);
        self::assertStringContainsString('"OrderId":123', $rawBody);
    }

    /**
     * A container reaches the log as the object it is, and the formatter unpacks it only after the
     * masking has run, so the masking has to unpack it first.
     */
    public function testMasksAnObjectBeforeTheFormatterCanUnpackIt(): void
    {
        $container = (object)[
            'MerchantApiKey' => self::API_KEY,
            'Customer' => (object)['Email' => self::EMAIL, 'LastName' => 'Andersson'],
        ];

        $context = $this->redactor->redactContext(['extra' => ['container' => $container]]);
        $written = json_encode($context);

        self::assertStringNotContainsString(self::API_KEY, $written);
        self::assertStringNotContainsString(self::EMAIL, $written);
        self::assertStringNotContainsString('Andersson', $written);
        self::assertSame(\stdClass::class, $context['extra']['container']['class']);
    }

    /**
     * An exception in the context keeps what it is logged for, with its message masked. The file
     * log unpacks a throwable itself, so leaving the object would have written the body out there.
     */
    public function testKeepsAnExceptionReadableWithItsMessageMasked(): void
    {
        $exception = new \RuntimeException('rejected: {"Email":"' . self::EMAIL . '"}', 422);

        $context = $this->redactor->redactContext(['extra' => ['exception' => $exception]]);
        $logged = $context['extra']['exception'];

        self::assertSame(\RuntimeException::class, $logged['class']);
        self::assertStringNotContainsString(self::EMAIL, $logged['message']);
        self::assertStringContainsString('rejected', $logged['message']);
        self::assertSame(422, $logged['code']);
        self::assertStringContainsString('RedactorTest.php:', $logged['file']);
    }

    /**
     * The endpoint, the uri and the order ids in them are how a line is found again, so the digit
     * scanning the tag turns on leaves them alone. Only what is personal goes from them.
     */
    public function testLeavesTheEndpointAndItsOrderIdReadableOnATaggedLine(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'method' => 'GET',
            'endpoint' => 'checkout/merchantapi/orders/1234567890',
            'extra' => [
                'uri' => 'https://pago.qit.nu/checkout/merchantapi/orders/1234567890',
                'body' => ['OrderId' => 1234567890],
            ],
        ]);

        self::assertSame('checkout/merchantapi/orders/1234567890', $context['endpoint']);
        self::assertSame('https://pago.qit.nu/checkout/merchantapi/orders/1234567890', $context['extra']['uri']);
        self::assertSame(1234567890, $context['extra']['body']['OrderId']);
    }

    /**
     * An identity number is masked when it arrives as a number and when it arrives inside JSON
     * without quotes, both of which the key based masking alone would walk past.
     */
    public function testMasksAnIdentityNumberThatIsNotAQuotedString(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => [
                'body' => [
                    'SomeFieldNobodyListed' => 198501011234,
                    'raw' => '{"MobileNumber":46701234567,"OrderId":123}',
                ],
            ],
        ]);

        $body = $context['extra']['body'];

        self::assertSame(Redactor::MASK, $body['SomeFieldNobodyListed']);
        self::assertStringNotContainsString('46701234567', $body['raw']);
        self::assertStringContainsString('"OrderId":123', $body['raw']);
    }

    /**
     * A dump of a payload spells its keys out as `[Key] => value`, so those are masked as well as
     * the ones in JSON. Nothing in the module dumps a payload today, and nothing should start.
     */
    public function testMasksAPayloadThatArrivedAsADump(): void
    {
        $dump = sprintf("Array\n(\n    [Email] => %s\n    [LastName] => Andersson\n)", self::EMAIL);

        $context = $this->redactor->redactContext(['extra' => ['dump' => $dump]]);

        self::assertStringNotContainsString(self::EMAIL, $context['extra']['dump']);
        self::assertStringNotContainsString('Andersson', $context['extra']['dump']);
    }

    /**
     * Masking by key name holds only as long as every call site names its keys the way this class
     * expects, and two did not: the token check wrote the API key out under `configured` and as
     * the merchant claim of a payload. The store's own credentials are masked by value as well.
     */
    public function testMasksTheStoreOwnCredentialsByValueUnderAnyKeyName(): void
    {
        $redactor = new Redactor($this->secretProvider([self::API_KEY, self::API_SECRET]));

        $context = $redactor->redactContext([
            'extra' => [
                'configured' => self::API_KEY,
                'merchant' => self::API_KEY,
                'note' => 'signed with ' . self::API_SECRET,
            ],
        ]);

        self::assertSame(Redactor::MASK, $context['extra']['configured']);
        self::assertSame(Redactor::MASK, $context['extra']['merchant']);
        self::assertSame('signed with ' . Redactor::MASK, $context['extra']['note']);
    }

    /**
     * A store with no credentials configured has nothing to mask by value, and the masking by key
     * name is unaffected.
     */
    public function testWorksWithoutAnyCredentialConfigured(): void
    {
        $redactor = new Redactor($this->secretProvider([]));

        $context = $redactor->redactContext(['extra' => ['MerchantApiKey' => self::API_KEY, 'note' => 'plain']]);

        self::assertSame(Redactor::MASK, $context['extra']['MerchantApiKey']);
        self::assertSame('plain', $context['extra']['note']);
    }

    /**
     * A personal field holding a list is masked whole. Magento's own street is a list, so walking
     * into it would have written the street out one line at a time.
     */
    public function testMasksAPersonalFieldThatHoldsAList(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => [
                'street' => ['Storgatan 1', 'lgh 3'],
                'raw' => '{"Street":["Storgatan 1"],"OrderId":5}',
            ],
        ]);

        self::assertSame(Redactor::MASK, $context['extra']['street']);
        self::assertStringNotContainsString('Storgatan', $context['extra']['raw']);
        self::assertStringContainsString('"OrderId":5', $context['extra']['raw']);
    }

    /**
     * An id, a reference and an amount keep their digits on a tagged line, whatever they look like.
     * A ten digit order id whose middle digits read as a month and a day is not an identity number,
     * and masking it would leave nothing to correlate the line with.
     */
    public function testKeepsIdsReferencesAndAmountsOnATaggedLine(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => [
                'body' => [
                    'OrderId' => 1204151234,
                    'QliroOrderId' => 1912311234,
                    'MerchantReference' => '1204151234',
                    'TotalPrice' => 1204151234.0,
                    'SomeFieldNobodyListed' => 198501011234,
                ],
            ],
        ]);

        $body = $context['extra']['body'];

        self::assertSame(1204151234, $body['OrderId']);
        self::assertSame(1912311234, $body['QliroOrderId']);
        self::assertSame('1204151234', $body['MerchantReference']);
        self::assertSame(1204151234.0, $body['TotalPrice']);
        self::assertSame(Redactor::MASK, $body['SomeFieldNobodyListed']);
    }

    /**
     * A merchant reference is a date and a counter on many stores, which is the shape of an
     * identity number. The reference of the line is known, so it survives the patterns while
     * anything else of that shape does not.
     */
    public function testKeepsTheMerchantReferenceOfTheLine(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'reference' => '20260909-0001',
            'extra' => [
                'body' => [
                    'MerchantReference' => '20260909-0001',
                    'Comment' => 'order 20260909-0001 for customer 19850101-1234',
                ],
            ],
        ]);

        $body = $context['extra']['body'];

        self::assertSame('20260909-0001', $body['MerchantReference']);
        self::assertStringContainsString('order 20260909-0001', $body['Comment']);
        self::assertStringNotContainsString('19850101-1234', $body['Comment'], 'the identity number survived');
    }

    /**
     * Another line's reference is not this line's, so it is masked like any other value of that
     * shape: the exemption is for the reference the line is correlated by, not for the format.
     */
    public function testDoesNotKeepAReferenceThisLineIsNotAbout(): void
    {
        $context = $this->redactor->redactContext([
            'reference' => '20260909-0001',
            'extra' => ['body' => ['Comment' => 'see also 19850101-1234']],
        ]);

        self::assertStringNotContainsString('19850101-1234', $context['extra']['body']['Comment']);
    }

    /**
     * A reference too short to be one is not held out: a single digit would exempt every digit.
     */
    public function testDoesNotKeepAReferenceTooShortToBeOne(): void
    {
        $context = $this->redactor->redactContext([
            'reference' => '7',
            'extra' => ['body' => ['Comment' => 'customer 19850101-1234']],
        ]);

        self::assertStringNotContainsString('19850101-1234', $context['extra']['body']['Comment']);
    }

    /**
     * A callback url gives the credentials away without naming them: the HTTP auth user and
     * password sit before the host, and the token is a JWT whose payload is the merchant API key
     * in base64, which no masking by key name and no masking by value can see.
     */
    public function testMasksWhatACallbackUrlCarries(): void
    {
        $jwt = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.'
            . rtrim(strtr(base64_encode((string)json_encode(['merchant' => self::API_KEY])), '+/', '-_'), '=')
            . '.c2lnbmF0dXJl';

        $context = $this->redactor->redactContext([
            'extra' => [
                'body' => [
                    'MerchantCheckoutStatusPushUrl' => 'https://shop.se/qliro/callback/status?token=' . $jwt,
                    'MerchantNotificationUrl' => 'https://qliro:s3cretpass@shop.se/qliro/notify?token=' . $jwt,
                ],
                'uri' => '/qliro/callback/status?token=' . $jwt,
            ],
        ]);

        $written = (string)json_encode($context);

        self::assertStringNotContainsString('eyJ0eXAi', $written, 'the token is still readable');
        self::assertStringNotContainsString('s3cretpass', $written, 'the http auth password is still readable');
        self::assertStringContainsString(
            'https://shop.se/qliro/callback/status?token=' . Redactor::MASK,
            $context['extra']['body']['MerchantCheckoutStatusPushUrl']
        );
        self::assertStringContainsString(
            'https://' . Redactor::MASK . '@shop.se/qliro/notify',
            $context['extra']['body']['MerchantNotificationUrl']
        );
    }

    /**
     * A dump writes a nested value as its own block below the key, so masking the line the key sits
     * on is not enough: the block goes with it. What is not a personal field stays, because the
     * error code and the order id are what the dump is being read for.
     *
     * Both shapes are covered, `print_r` opening its block on the key's line and `var_export`
     * opening it on the next one, because the module has logged a payload each way.
     *
     * @dataProvider dumpProvider
     */
    public function testMasksAPersonalFieldAndTheBlockItOpens(string $dump): void
    {
        $redacted = $this->redactor->redactContext(['extra' => ['dump' => $dump]])['extra']['dump'];

        foreach (['Kungsgatan', 'Andersson', self::EMAIL, '46701234567', 'Stockholm'] as $personal) {
            self::assertStringNotContainsString($personal, $redacted, $personal . ' is readable');
        }

        self::assertStringContainsString('1234567890', $redacted, 'the order id is gone');
        self::assertStringContainsString('DECLINED', $redacted, 'the error code is gone');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dumpProvider(): array
    {
        $payload = [
            'OrderId' => 1234567890,
            'ErrorCode' => 'DECLINED',
            'Customer' => [
                'Email' => self::EMAIL,
                'LastName' => 'Andersson',
                'MobileNumber' => '+46701234567',
                'CompanyName' => '',
                'Address' => ['Street' => ['Kungsgatan 1'], 'City' => 'Stockholm', 'CountryCode' => 'SE'],
            ],
        ];

        return [
            'print_r' => [print_r($payload, true)],
            'var_export' => [var_export($payload, true)],
        ];
    }

    /**
     * A key spelled with hyphens is the same key, and a value that is not a quoted string is still
     * a value: a header name and a small object both reach the log as text from an exception.
     */
    public function testMasksAHyphenatedKeyAndANonStringValueInText(): void
    {
        $message = '{"x-api-key":"' . self::API_KEY . '","authorization":{"scheme":"Basic","value":"dXNlcg=="},'
            . '"merchant-api-key":"' . self::API_SECRET . '","OrderId":42}';

        $redacted = $this->redactor->redactMessage($message);

        self::assertStringNotContainsString(self::API_KEY, $redacted);
        self::assertStringNotContainsString(self::API_SECRET, $redacted);
        self::assertStringNotContainsString('dXNlcg==', $redacted);
        self::assertStringContainsString('"OrderId":42', $redacted);
    }

    /**
     * A dump does not quote its values, so a bracket inside one used to either leave the field
     * below it readable or swallow the rest of the dump. The block is bounded by indentation.
     *
     * @dataProvider awkwardDumpProvider
     */
    public function testABracketInsideAValueBreaksNeitherTheMaskingNorTheDump(array $payload): void
    {
        foreach ([print_r($payload, true), var_export($payload, true)] as $dump) {
            $redacted = $this->redactor->redactContext(['extra' => ['dump' => $dump]])['extra']['dump'];

            self::assertStringNotContainsString('Andersson', $redacted);
            self::assertStringNotContainsString('Storgatan', $redacted);
            self::assertStringContainsString('DECLINED', $redacted, 'the error code was swallowed');
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function awkwardDumpProvider(): array
    {
        return [
            'a closing bracket in a value' => [[
                'CompanyName' => ['Acme) AB', 'Andersson'],
                'ErrorCode' => 'DECLINED',
            ]],
            'an opening bracket in a value' => [[
                'street' => ['Storgatan (5', 'Apt 2'],
                'ErrorCode' => 'DECLINED',
            ]],
        ];
    }

    /**
     * A value can hold a newline, and the rest of it is written below with no key of its own, so
     * masking the field's own line is not enough.
     *
     * @dataProvider multiLineValueProvider
     */
    public function testMasksAValueThatCarriesOnToTheNextLine(string $dump): void
    {
        $redacted = $this->redactor->redactContext(['extra' => ['dump' => $dump]])['extra']['dump'];

        self::assertStringNotContainsString('Apt 4B', $redacted);
        self::assertStringContainsString('DENIED', $redacted, 'the error code was swallowed');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multiLineValueProvider(): array
    {
        $payload = ['Street' => "Kungsgatan 1\nApt 4B", 'ErrorCode' => 'DENIED'];

        return [
            'print_r' => [print_r($payload, true)],
            'var_export' => [var_export($payload, true)],
        ];
    }

    /**
     * The user and password of a url are masked whether or not they are percent encoded, and a
     * host with a port keeps its name even when an address turns up later in the query.
     *
     * @dataProvider urlProvider
     */
    public function testMasksTheCredentialsOfAUrlWithoutManglingItsHost(string $url, string $expected): void
    {
        self::assertSame($expected, $this->redactor->redactMessage($url));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlProvider(): array
    {
        return [
            'percent encoded' => [
                'https://qliro:s3cret@shop.se/cb',
                'https://' . Redactor::MASK . '@shop.se/cb',
            ],
            'a password with a slash in it' => [
                'https://bob:pa/ss@shop.se/cb',
                'https://' . Redactor::MASK . '@shop.se/cb',
            ],
            'a port and an address in the query' => [
                'https://shop.example.com:8443?email=anna@example.com',
                'https://shop.example.com:8443?email=' . Redactor::MASK,
            ],
        ];
    }

    /**
     * A masked field's block ends where its own bracket closes, so a stack trace or a message
     * appended after the dump is not swallowed with it.
     */
    public function testTheBlockEndsWhereItsBracketDoes(): void
    {
        $text = print_r(['Street' => ['Kungsgatan 1'], 'ErrorCode' => 'NOT_FOUND'], true)
            . "\n        #0 /app/Foo.php(12): Bar->baz()\n        #1 {main}";

        $redacted = $this->redactor->redactContext(['extra' => ['dump' => $text]])['extra']['dump'];

        self::assertStringNotContainsString('Kungsgatan', $redacted);
        self::assertStringContainsString('#0 /app/Foo.php(12)', $redacted, 'the trace was swallowed');
        self::assertStringContainsString('[ErrorCode] => NOT_FOUND', $redacted);
    }

    /**
     * A date holds its value in no public property, so unpacking it the way any other object is
     * unpacked would write the class name and lose the timestamp.
     */
    public function testADateKeepsItsValue(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => ['when' => new \DateTimeImmutable('2026-09-09 10:11:12')],
        ]);

        self::assertStringStartsWith('2026-09-09 10:11:12', $context['extra']['when']);
    }

    /**
     * An article number written as digits and a dash is not an identity number, and it is what a
     * merchant looks a line up by.
     */
    public function testAnArticleNumberIsNotMistakenForAnIdentityNumber(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => ['sku' => '123456-7890', 'PersonalNumber' => '19850101-1234'],
        ]);

        self::assertSame('123456-7890', $context['extra']['sku']);
        self::assertSame(Redactor::MASK, $context['extra']['PersonalNumber']);
    }

    /**
     * An array that holds itself comes back as something that can be written: an assignment into
     * the array we were given writes through the reference, so the masked copy is built fresh.
     */
    public function testAnArrayThatHoldsItselfComesBackWritable(): void
    {
        $payload = ['Email' => self::EMAIL];
        $payload['self'] = &$payload;

        $context = $this->redactor->redactContext(['extra' => ['loop' => $payload]]);

        self::assertNotFalse(json_encode($context), 'the extra column cannot be written');
    }

    /**
     * A key whose digits are an id spares the value under it, not everything below it: a phone
     * number in a child value is still masked on a tagged line.
     */
    public function testADigitSafeKeyDoesNotSpareAWholeSubtree(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => [
                'uri' => 'https://pago.qit.nu/orders/201501011234',
                'body' => ['note' => 'called from ' . self::PHONE],
            ],
        ]);

        self::assertSame('https://pago.qit.nu/orders/201501011234', $context['extra']['uri']);
        self::assertStringNotContainsString(self::PHONE, $context['extra']['body']['note']);
    }

    /**
     * A url with a port and an address later in the query keeps its host: the user and password
     * masking must not read across the query to find the at sign.
     */
    public function testKeepsTheHostOfAUrlThatHasAPortAndAnAddressInItsQuery(): void
    {
        self::assertSame(
            'https://shop.example.com:8443?email=' . Redactor::MASK,
            $this->redactor->redactMessage('https://shop.example.com:8443?email=anna@example.com')
        );
    }

    /**
     * An order id is what a line is correlated by, and it survives in a message as well as under a
     * key. An identity number written as twelve digits does not: no order id can look like that.
     */
    public function testKeepsAnOrderIdInAMessageAndStillMasksAnIdentityNumber(): void
    {
        self::assertSame(
            '>>> GET checkout/merchantapi/orders/2503121234',
            $this->redactor->redactMessage('>>> GET checkout/merchantapi/orders/2503121234', true)
        );
        self::assertSame(
            'customer ' . Redactor::MASK . ' called',
            $this->redactor->redactMessage('customer 198501011234 called', true)
        );
    }

    /**
     * A structure that holds itself terminates, and one nested past all reason is cut off, so a
     * logged exception trace cannot exhaust the stack.
     */
    public function testTerminatesOnAStructureThatHoldsItself(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $first->child = $second;
        $second->parent = $first;
        $first->Email = self::EMAIL;

        $context = $this->redactor->redactContext(['extra' => ['object' => $first]]);
        $written = (string)json_encode($context);

        self::assertStringContainsString('[recursion]', $written);
        self::assertStringNotContainsString(self::EMAIL, $written);
    }

    /**
     * An exception is logged to be read, so it keeps its trace, masked like everything else.
     */
    public function testKeepsTheTraceOfAnException(): void
    {
        $context = $this->redactor->redactContext([
            'extra' => ['exception' => new \RuntimeException('rejected')],
        ]);

        self::assertArrayHasKey('trace', $context['extra']['exception']);
        self::assertStringContainsString('RedactorTest', $context['extra']['exception']['trace']);
    }

    /**
     * A blank personal field must not take the line below it with it. `[CompanyName] => ` with
     * nothing after it is what a private customer looks like in a dump, and the error code on the
     * next line is the one thing an operator is reading the dump for.
     */
    public function testABlankPersonalFieldDoesNotSwallowTheNextLine(): void
    {
        $dump = "    [CompanyName] => \n    [ErrorCode] => ORDER_NOT_FOUND\n    [OrderId] => 777\n";

        $redacted = $this->redactor->redactContext(['extra' => ['dump' => $dump]])['extra']['dump'];

        self::assertStringContainsString('[ErrorCode] => ORDER_NOT_FOUND', $redacted);
        self::assertStringContainsString('[OrderId] => 777', $redacted);
        self::assertNotSame(Redactor::MASK, $redacted);
    }

    /**
     * The same at the end of a string, where an empty value could be mistaken for a nested block
     * and take the whole message with it.
     */
    public function testABlankPersonalFieldAtTheEndKeepsTheMessage(): void
    {
        $redacted = $this->redactor
            ->redactContext(['extra' => ['dump' => "order 42 failed\n    [City] => "]])['extra']['dump'];

        self::assertStringContainsString('order 42 failed', $redacted);
    }

    /**
     * An id or a reference keeps its digits even when they happen to read as a century and a date,
     * which is what the identity pattern looks for. A field nobody listed does not.
     */
    public function testAnIdKeepsDigitsThatReadAsADate(): void
    {
        $context = $this->redactor->redactContext([
            'tags' => Redactor::TAG_SENSITIVE,
            'extra' => [
                'QliroOrderId' => 201501011234,
                'MerchantReference' => '201501011234',
                'uri' => 'https://pago.qit.nu/orders/201501011234',
                'SomeFieldNobodyListed' => 201501011234,
            ],
        ]);

        self::assertSame(201501011234, $context['extra']['QliroOrderId']);
        self::assertSame('201501011234', $context['extra']['MerchantReference']);
        self::assertSame('https://pago.qit.nu/orders/201501011234', $context['extra']['uri']);
        self::assertSame(Redactor::MASK, $context['extra']['SomeFieldNobodyListed']);
    }

    /**
     * @dataProvider tagProvider
     */
    public function testReadsTheSensitiveTagOutOfWhateverTheManagerPacked(mixed $tags, bool $expected): void
    {
        self::assertSame($expected, $this->redactor->isSensitive($tags));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function tagProvider(): array
    {
        return [
            'the packed string' => ['checkout,sensitive', true],
            'with the spaces a caller left' => ['checkout, sensitive', true],
            'an array' => [['sensitive'], true],
            'another tag' => ['checkout', false],
            'nothing' => ['', false],
        ];
    }

    /**
     * @param string[] $secrets
     * @return SecretProvider
     */
    private function secretProvider(array $secrets): SecretProvider
    {
        $provider = $this->createMock(SecretProvider::class);
        $provider->method('getSecrets')->willReturn($secrets);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrderPayload(): array
    {
        return [
            'MerchantApiKey' => self::API_KEY,
            'MerchantReference' => 'qliroone-42',
            'Currency' => 'SEK',
            'Customer' => [
                'Email' => self::EMAIL,
                'MobileNumber' => self::PHONE,
                'PersonalNumber' => self::IDENTITY,
                'JuridicalType' => 'Physical',
                'Address' => [
                    'FirstName' => 'Anna',
                    'LastName' => 'Andersson',
                    'Street' => 'Storgatan 1',
                    'PostalCode' => '11122',
                    'City' => 'Stockholm',
                    'CountryCode' => 'SE',
                ],
            ],
            'OrderItems' => [
                [
                    'MerchantReference' => '7:tshirt',
                    'Description' => 'T-Shirt',
                    'PricePerItemIncVat' => 199.0,
                    'Quantity' => 1,
                ],
            ],
        ];
    }
}
