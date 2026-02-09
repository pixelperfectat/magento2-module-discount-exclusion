<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

/**
 * Builds and adds exclusion/bypass messages for a given coupon code
 */
interface ExclusionMessageBuilderInterface
{
    /**
     * Add exclusion and bypass messages for the given coupon code
     *
     * Reads from the ExclusionResultCollector and adds appropriate
     * warning/notice messages to the message manager.
     *
     * @param string $couponCode
     *
     * @return void
     */
    public function addMessagesForCoupon(string $couponCode): void;

    /**
     * Build exclusion and bypass messages without adding them
     *
     * Returns an array of messages with their type, suitable for
     * queuing in the session for later display.
     *
     * @param string $couponCode
     *
     * @return array<int, array{type: string, text: string}>
     */
    public function buildMessagesForCoupon(string $couponCode): array;
}
