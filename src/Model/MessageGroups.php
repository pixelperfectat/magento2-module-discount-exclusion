<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model;

/**
 * Message group names used by the module.
 *
 * @deprecated Removal planned for 2.0.0. The message group is only read by the
 *             deprecated MessageProcessor; nothing adds messages to it.
 * @see \PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface
 */
class MessageGroups
{
    public const string DISCOUNT_EXCLUSION = 'discount_exclusion';
}
