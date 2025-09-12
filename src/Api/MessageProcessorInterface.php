<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

interface MessageProcessorInterface
{
    /**
     * Process discount exclusion messages by moving them to the default message group
     * and clearing related session data
     *
     * @return int Number of messages processed
     */
    public function processDiscountExclusionMessages(): int;

    /**
     * Check if there are any discount exclusion messages to process
     *
     * @return bool
     */
    public function hasDiscountExclusionMessages(): bool;
}
