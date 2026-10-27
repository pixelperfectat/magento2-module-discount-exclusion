<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model;

class SessionKeys
{
    /**
     * @deprecated Removal planned for 2.0.0. Nothing writes this session key; it is
     *             only cleared for backwards compatibility.
     */
    public const string PROCESSED_PRODUCT_IDS = 'processed_product_ids';
    public const string QUEUED_DISCOUNT_MESSAGES = 'queued_discount_messages';
}
