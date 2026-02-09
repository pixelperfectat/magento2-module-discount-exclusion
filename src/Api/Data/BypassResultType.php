<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api\Data;

/**
 * Describes the outcome of the max-discount calculation for a bypassed rule.
 */
enum BypassResultType: string
{
    /** Rule discount exceeds the existing discount — apply the difference */
    case ADJUSTED = 'adjusted';

    /** Existing discount is equal to or greater than the rule discount — block */
    case EXISTING_BETTER = 'existing_better';

    /** Rule type does not support max-discount calculation — allow full stacking */
    case STACKING_FALLBACK = 'stacking_fallback';
}
