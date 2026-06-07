<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Order placement pending exception class.
 *
 * Extends LocalizedException (not \Exception) so the constructor accepts a Magento\Framework\Phrase
 * directly. Throwers across the module use __('...') which returns Phrase; under declare(strict_types=1)
 * that would be a TypeError against \Exception::__construct(string $message), but LocalizedException's
 * constructor signature is __construct(Phrase $phrase, ...) so the call is well-typed.
 *
 * Catch behaviour is unchanged for callers that catch this class by name. Code that catches
 * LocalizedException broadly will also catch this — that's the same behaviour as every other
 * LocalizedException subclass in the codebase.
 */
class OrderPlacementPendingException extends LocalizedException
{
}
