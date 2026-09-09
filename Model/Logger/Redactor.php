<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger;

/**
 * Masks credentials and customer data on their way into the log, wherever they were logged from
 *
 * A payload reaches the log from the API service, from the callback controllers, from a container
 * object and from exception messages, so the masking is done once here rather than at each of
 * those call sites.
 */
class Redactor
{
    /**
     * What a masked value reads as, kept short so a payload stays readable
     */
    public const MASK = '[redacted]';

    /**
     * The tag set around a request or a callback known to carry a customer record
     */
    public const TAG_SENSITIVE = 'sensitive';

    /**
     * A key holding a credential, masked whatever it holds and however deep it sits
     */
    private const CREDENTIAL_KEYS = [
        'merchantapikey',
        'merchantapisecret',
        'apikey',
        'apisecret',
        'secret',
        'password',
        'authorization',
        'authentication',
        'token',
        'accesstoken',
        'authtoken',
        'signature',
    ];

    /**
     * A fragment that makes a key a credential even under a name we have not seen
     */
    private const CREDENTIAL_FRAGMENTS = ['secret', 'password', 'apikey', 'authorization', 'token'];

    /**
     * A key holding personal data, masked when it holds a value of its own
     */
    private const PERSONAL_KEYS = [
        'email',
        'customeremail',
        'emailaddress',
        'mobile',
        'mobilenumber',
        'cellphone',
        'cellphonenumber',
        'phone',
        'phonenumber',
        'telephone',
        'telephonenumber',
        'personalnumber',
        'socialsecuritynumber',
        'ssn',
        'nationalid',
        'nationalidentificationnumber',
        'vatnumber',
        'vatid',
        'taxvat',
        'organizationnumber',
        'dateofbirth',
        'birthdate',
        'birthday',
        'dob',
        'firstname',
        'lastname',
        'fullname',
        'customername',
        'careof',
        'company',
        'companyname',
        'street',
        'street1',
        'street2',
        'streetaddress',
        'addressline',
        'addressline1',
        'addressline2',
        'houseno',
        'housenumber',
        'apartment',
        'postalcode',
        'postcode',
        'zip',
        'zipcode',
        'city',
        'town',
    ];

    /**
     * A fragment that makes a key personal even under a name we have not seen, long enough not to
     * turn up inside an unrelated word
     */
    private const PERSONAL_FRAGMENTS = [
        'taxvat',
        'firstname',
        'lastname',
        'middlename',
        'emailaddress',
        'personalnumber',
        'socialsecurity',
        'nationalid',
        'identitynumber',
        'mobilenumber',
        'phonenumber',
        'telephone',
        'streetaddress',
        'postalcode',
        'postcode',
        'dateofbirth',
        'companyname',
        'careof',
        'vatnumber',
    ];

    /**
     * A key whose digits are an id, an amount or a timestamp, never an identity number
     *
     * A value under one of these keeps its digits: none of the identity patterns are run on it.
     * Read after the personal keys, so a personal field is masked before this can spare it.
     */
    private const DIGIT_SAFE_KEYS = ['uri', 'url', 'endpoint', 'method', 'id', 'code', 'expires'];

    /**
     * The same, as fragments: `QliroOrderId`, `PricePerItemIncVat`, `MerchantReference`
     */
    private const DIGIT_SAFE_FRAGMENTS = [
        'orderid',
        'quoteid',
        'linkid',
        'storeid',
        'itemid',
        'recordid',
        'transactionid',
        'reference',
        'price',
        'amount',
        'total',
        'quantity',
        'statuscode',
        'duration',
        'timestamp',
        'sku',
        'articlenumber',
        'itemnumber',
        'productid',
        'ean',
        'gtin',
    ];

    /**
     * An email address is personal wherever it turns up, including in the middle of a message
     */
    private const EMAIL_PATTERN = '/[\w.+-]+@[\w-]+\.[\w.-]*\w/';

    /**
     * A personal identity number as the Nordic countries write it, 6 or 8 digits, a mark, 4 digits
     */
    private const IDENTITY_PATTERN = '/\b\d{6}(?:\d{2})?[-+]\d{4}\b/';

    /**
     * The same number written without its mark: twelve digits opening with a century and a date
     *
     * The ten digit form is deliberately not here. It cannot be told apart from a Qliro order id,
     * which is what a log line is correlated by, and the ten digit form arrives under a key of its
     * own in every payload this module sends or receives.
     */
    private const BARE_IDENTITY_PATTERN =
        '/\b(?:19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{4}\b/';

    /**
     * An international phone number
     */
    private const PHONE_PATTERN = '/\+\d[\d\s()-]{7,17}\d/';

    /**
     * The user and password of a url, which is how the callback HTTP auth credentials travel
     */
    private const URL_USERINFO_PATTERN = '#(\w+://)[^/\s:@"\'?&=\#]+:[^\s@"\'=]+@#';

    /**
     * A query parameter that carries a credential, `?token=` above all: the callback token is a
     * JWT whose payload is the merchant API key in base64, so the url gives the key away
     */
    private const URL_CREDENTIAL_QUERY_PATTERN =
        '/([?&](?:token|access_token|auth|authorization|signature|secret|apikey|api_key)=)[^&\s"\'<]+/i';

    /**
     * A JSON web token wherever it appears, for the same reason
     */
    private const JWT_PATTERN = '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+/';

    /**
     * Deeper than this a value is not walked, so a structure that references itself cannot
     * exhaust the stack. The formatter that writes the log truncates at three levels anyway
     */
    private const MAX_DEPTH = 12;

    /**
     * @param SecretProvider|null $secretProvider Masks the store's own credentials by value too
     */
    public function __construct(
        private readonly ?SecretProvider $secretProvider = null
    ) {
    }

    /**
     * Mask a log context, in place of the one that was logged
     *
     * @param array $context
     * @param bool|null $sensitive Overrides what the tags of the context say
     * @return array
     */
    public function redactContext(array $context, ?bool $sensitive = null): array
    {
        $sensitive = $sensitive ?? $this->isSensitive($context['tags'] ?? '');

        foreach ($context as $key => $value) {
            // The reference and the tags are the module's own bookkeeping, not payload
            if ($key === 'tags' || $key === 'reference' || $key === 'process_id' || $key === 'mark') {
                continue;
            }

            $context[$key] = $this->redactValue($key, $value, $sensitive, 0, []);
        }

        return $context;
    }

    /**
     * Mask a log message, which can carry a serialized payload from an exception
     *
     * @param string $message
     * @param bool $sensitive
     * @return string
     */
    public function redactMessage(string $message, bool $sensitive = false): string
    {
        return $this->redactString($message, $sensitive);
    }

    /**
     * Whether the tags of a log line say the payload is a customer record
     *
     * @param mixed $tags
     * @return bool
     */
    public function isSensitive($tags): bool
    {
        $tags = is_array($tags) ? $tags : explode(',', (string)$tags);

        return in_array(self::TAG_SENSITIVE, array_map('trim', $tags), true);
    }

    /**
     * @param int|string $key
     * @param mixed $value
     * @param bool $sensitive
     * @param int $depth
     * @param int[] $seen Object ids already walked, so a structure holding itself terminates
     * @return mixed
     */
    private function redactValue($key, $value, bool $sensitive, int $depth = 0, array $seen = [])
    {
        $name = $this->normalizeKey((string)$key);

        if ($depth > self::MAX_DEPTH) {
            return '[...]';
        }

        if ($this->isCredentialKey($name)) {
            return self::MASK;
        }

        // Before the walk below, or a personal field holding a list, as Magento's street does, is
        // walked into and its parts written out one by one
        if ($this->isPersonalKey($name) && $value !== null && !is_bool($value)) {
            return self::MASK;
        }

        // An endpoint or an order id is what a line is read by, so it keeps its digits. This
        // holds for the value under that key, not for a whole subtree: the children are walked
        // with the tag they were given
        $scanDigits = !$this->isDigitSafeKey($name);

        if ($value instanceof \Throwable) {
            return $this->redactThrowable($value, $sensitive);
        }

        if ($value instanceof \DateTimeInterface) {
            // Unpacking one gives nothing back: its value is not in a public property
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_object($value)) {
            if (in_array(spl_object_id($value), $seen, true)) {
                return '[recursion]';
            }

            $seen[] = spl_object_id($value);

            // A container reaches the log as an object and the formatter unpacks it after this runs
            return $this->redactValue($key, $this->unpackObject($value), $sensitive, $depth, $seen);
        }

        if (is_array($value)) {
            // Built fresh rather than assigned into: an array that holds a reference to itself is
            // written through by an assignment, and what came back would still hold itself
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue($childKey, $childValue, $sensitive, $depth + 1, $seen);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return $this->redactString($value, $sensitive && $scanDigits, $scanDigits);
        }

        if (is_int($value) || is_float($value)) {
            // A number can be an identity number as much as a string can
            $masked = $this->redactString((string)$value, $sensitive && $scanDigits, $scanDigits);

            return $masked === (string)$value ? $value : $masked;
        }

        return $value;
    }

    /**
     * Keep what an exception is worth logging for, masked, instead of the object the formatter unpacks
     *
     * @param \Throwable $exception
     * @param bool $sensitive
     * @return array
     */
    private function redactThrowable(\Throwable $exception, bool $sensitive): array
    {
        return [
            'class' => get_class($exception),
            'message' => $this->redactString($exception->getMessage(), $sensitive),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'code' => $exception->getCode(),
            // As a string, because the frames hold the arguments the call was made with
            'trace' => $this->redactString($exception->getTraceAsString(), $sensitive),
        ];
    }

    /**
     * @param object $value
     * @return array
     */
    private function unpackObject(object $value): array
    {
        $vars = get_object_vars($value);
        $vars['class'] = get_class($value);

        return $vars;
    }

    /**
     * Mask what a text value gives away, both by the keys it spells out and by what it looks like
     *
     * @param string $value
     * @param bool $sensitive
     * @param bool $scanDigits False for a value whose digits are an id, never an identity number
     * @return string
     */
    private function redactString(string $value, bool $sensitive, bool $scanDigits = true): string
    {
        // The store's own credentials, whatever key they were logged under
        foreach ($this->secretProvider?->getSecrets() ?? [] as $secret) {
            $value = str_replace($secret, self::MASK, $value);
        }

        // A body that could not be parsed, or an exception message, arrives as text with its keys in it
        $value = $this->redactSpelledOutFields($value);

        // A callback url carries the credentials in it: the HTTP auth user and password before the
        // host, and a token whose payload is the merchant API key
        $value = (string)(preg_replace(self::URL_USERINFO_PATTERN, '$1' . self::MASK . '@', $value) ?? self::MASK);
        $value = (string)(preg_replace(self::URL_CREDENTIAL_QUERY_PATTERN, '$1' . self::MASK, $value) ?? self::MASK);
        $value = $this->replace(self::JWT_PATTERN, $value);

        $value = $this->replace(self::EMAIL_PATTERN, $value);

        if (!$scanDigits) {
            return $value;
        }

        $value = $this->replace(self::IDENTITY_PATTERN, $value);
        $value = $this->replace(self::BARE_IDENTITY_PATTERN, $value);

        if (!$sensitive) {
            return $value;
        }

        return $this->replace(self::PHONE_PATTERN, $value);
    }

    /**
     * Mask a field whose name is spelled out in text, which is how a payload reaches a message
     *
     * Serialized JSON is how an exception carries a body. A dump, `print_r()` or `var_export()`,
     * is how a payload reaches the log from a call site that means to be helpful.
     *
     * @param string $value
     * @return string
     */
    private function redactSpelledOutFields(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // The key can be hyphenated, `x-api-key`, and the value can be a string, a list, a small
        // object, a keyword or a number. Objects first and scalars after, over the whole string
        // again: a match on a key that is not a secret consumes what is nested inside it, and the
        // second pass is what gives those nested fields their own turn
        $patterns = [
            '/"([\w.\-]+)"\s*:\s*\{[^{}]*\}/',
            '/"([\w.\-]+)"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|\[[^\]]*\]|true|false|null|-?\d+(?:\.\d+)?)/i',
        ];

        $replaced = $value;

        foreach ($patterns as $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                function (array $match): string {
                    return $this->isSecretKey($this->normalizeKey($match[1]))
                        ? sprintf('"%s":"%s"', $match[1], self::MASK)
                        : $match[0];
                },
                (string)$replaced
            );

            if ($replaced === null) {
                break;
            }
        }

        // A pattern that could not run leaves nothing readable rather than the payload
        return $this->redactDump($replaced ?? self::MASK);
    }

    /**
     * Mask the fields of a dump, and the block a masked field opens
     *
     * Line by line, because a dump writes a nested value as a block of its own below the key: the
     * key's line says only `Array`, or nothing at all in `var_export`, and the value is underneath.
     * Everything that is not such a field is left as it was, so the error code and the order id an
     * operator is reading the dump for survive.
     *
     * @param string $value
     * @return string
     */
    private function redactDump(string $value): string
    {
        if (!str_contains($value, '=>')) {
            return $value;
        }

        $lines = preg_split('/\R/', $value);

        if ($lines === false) {
            return self::MASK;
        }

        $kept = [];
        $blockIndent = null;
        $openerIndent = null;
        $dumpDepth = 0;
        $maskedValueContinues = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            $indent = strlen($line) - strlen(ltrim($line));
            $isBracket = (bool)preg_match('/^(?:array \(|\(|\)[,;]?)$/', $trimmed);

            if ($isBracket) {
                $dumpDepth += str_starts_with($trimmed, ')') ? -1 : 1;
            }

            // A value can hold a newline, and the rest of it is written on the lines below with no
            // key of its own. Inside a dump those belong to the field that was just masked
            if ($maskedValueContinues) {
                if ($dumpDepth > 0 && !$isBracket && $trimmed !== ''
                    && !preg_match('/^(?:\[[^\]]+\]|\'[^\']+\'|"[^"]+")\s*=>/', $trimmed)) {
                    continue;
                }

                $maskedValueContinues = false;
            }

            if ($blockIndent !== null) {
                if ($trimmed === '') {
                    continue;
                }

                // The bracket the masked field's block opens with, `(` or `array (`
                if ($openerIndent === null) {
                    if (preg_match('/^(?:array \(|\()$/', $trimmed)) {
                        $openerIndent = $indent;

                        continue;
                    }

                    // No block after all, this line is the next field
                    $blockIndent = null;
                } elseif ($indent > $openerIndent) {
                    continue;
                } elseif (preg_match('/^\)[,;]?$/', $trimmed)) {
                    // The bracket it closes with, and with it the block
                    $blockIndent = null;
                    $openerIndent = null;

                    continue;
                } else {
                    // Out of the block without a closing bracket, so this line is not part of it
                    $blockIndent = null;
                    $openerIndent = null;
                }
            }

            if (!preg_match('/^(\s*)(\[[^\]]+\]|\'[^\']+\'|"[^"]+")\s*=>[^\S\r\n]*(.*)$/', $line, $match)) {
                $kept[] = $line;

                continue;
            }

            if (!$this->isSecretKey($this->normalizeKey(trim($match[2], '[]\'"')))) {
                $kept[] = $line;

                continue;
            }

            $kept[] = sprintf('%s%s => %s', $match[1], $match[2], self::MASK);

            if ($this->opensABlock(trim($match[3]), $lines, $index)) {
                $blockIndent = $indent;
                $openerIndent = null;
            } else {
                $maskedValueContinues = true;
            }
        }

        return implode(PHP_EOL, $kept);
    }

    /**
     * Whether a masked field holds a block of its own rather than a value on its line
     *
     * `print_r` writes `Array` or `Foo Object` on the key's line. `var_export` writes nothing and
     * opens with `array (` on the next one, which is also what an empty field looks like, so the
     * next line has to be read to tell a nested value from a field the customer left blank.
     *
     * @param string $written What the key's line says after the arrow
     * @param string[] $lines
     * @param int $index
     * @return bool
     */
    private function opensABlock(string $written, array $lines, int $index): bool
    {
        if (preg_match('/^(?:Array|[\w\\\\]+ Object|array \(|\()/', $written)) {
            return true;
        }

        if ($written !== '') {
            return false;
        }

        for ($next = $index + 1; $next < count($lines); $next++) {
            if (trim($lines[$next]) === '') {
                continue;
            }

            return (bool)preg_match('/^(?:array \(|\(|\\\\?[\w\\\\]+::__set_state\()/', trim($lines[$next]));
        }

        return false;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isSecretKey(string $name): bool
    {
        return $this->isCredentialKey($name) || $this->isPersonalKey($name);
    }

    /**
     * @param string $pattern
     * @param string $value
     * @return string
     */
    private function replace(string $pattern, string $value): string
    {
        return preg_replace($pattern, self::MASK, $value) ?? self::MASK;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isCredentialKey(string $name): bool
    {
        if (in_array($name, self::CREDENTIAL_KEYS, true)) {
            return true;
        }

        foreach (self::CREDENTIAL_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isPersonalKey(string $name): bool
    {
        if (in_array($name, self::PERSONAL_KEYS, true)) {
            return true;
        }

        foreach (self::PERSONAL_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isDigitSafeKey(string $name): bool
    {
        if (in_array($name, self::DIGIT_SAFE_KEYS, true)) {
            return true;
        }

        foreach (self::DIGIT_SAFE_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `merchant_api_key`, `MerchantApiKey` and `merchant-api-key` are one key
     *
     * @param string $key
     * @return string
     */
    private function normalizeKey(string $key): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $key));
    }
}
