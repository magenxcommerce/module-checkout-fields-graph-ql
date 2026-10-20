<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model;

/**
 * Normalizes a shopper-entered order comment to plain text.
 *
 * Tags are stripped and control characters (Unicode Cc, except newline and
 * tab) removed on the way in. Format characters such as RTL marks and emoji
 * joiners are kept. Every place that displays the comment still escapes it on
 * the way out.
 */
class NoteSanitizer
{
    /**
     * @param mixed $value
     * @return string
     */
    public function sanitize(mixed $value): string
    {
        $text = strip_tags((string) $value);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text);

        return trim($text);
    }
}
