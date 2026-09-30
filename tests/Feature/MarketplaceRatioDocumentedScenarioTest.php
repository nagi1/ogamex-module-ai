<?php

use Symfony\Component\Yaml\Yaml;

/**
 * The documented marketplace ratio policy, read from the behaviour file the way the AI reads it
 * when it prices a trade: the ratio, the offer band, the server adjustment and the readjust
 * period all come from the file, so a modder can retune the trader without touching PHP.
 */
function marketplaceRatioPolicy(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/marketplace-ratio-documented.yaml');
}

/**
 * An offer carries the legs of the standard ratio shifted by $offerShiftPercent and is priced
 * against the ratio the server is running right now. It is actionable only while every leg sits
 * inside the offer band around that current ratio.
 */
function marketplaceOfferIsActionable(float $offerShiftPercent, ?array $currentRatio = null): bool
{
    $policy = marketplaceRatioPolicy();
    $currentRatio ??= $policy['standard_ratio'];
    $band = $policy['offer_band_percent'] / 100.0;

    foreach ($policy['standard_ratio'] as $resource => $standardLeg) {
        $offerLeg = $standardLeg * (1.0 + $offerShiftPercent / 100.0);

        if (abs($offerLeg / $currentRatio[$resource] - 1.0) > $band) {
            return false;
        }
    }

    return true;
}

it('reads the ratio, offer band, server adjustment and readjust period from the behaviour file', function () {
    $policy = marketplaceRatioPolicy();

    expect($policy['standard_ratio']['metal'])->toBe(2.5)
        ->and($policy['standard_ratio']['crystal'])->toBe(1.5)
        ->and($policy['standard_ratio']['deuterium'])->toBe(1.0)
        ->and($policy['offer_band_percent'])->toBe(25)
        ->and($policy['server_adjustment_percent'])->toBe(30)
        ->and($policy['server_readjust_hours'])->toBe(48);
});

dataset('offers around the offer band', [
    'just inside the band below' => [-24.9, true],
    'just outside the band below' => [-25.1, false],
    'just inside the band above' => [24.9, true],
    'just outside the band above' => [25.1, false],
]);

it('accepts offers inside the offer band and rejects offers past it', function (float $shiftPercent, bool $actionable) {
    expect(marketplaceOfferIsActionable($shiftPercent))->toBe($actionable);
})->with('offers around the offer band');

it('holds an offer on the offer band edge to be actionable and one tenth past it to be not', function () {
    $band = (float) marketplaceRatioPolicy()['offer_band_percent'];

    expect(marketplaceOfferIsActionable($band))->toBeTrue()
        ->and(marketplaceOfferIsActionable(-$band))->toBeTrue()
        ->and(marketplaceOfferIsActionable($band + 0.1))->toBeFalse()
        ->and(marketplaceOfferIsActionable(-$band - 0.1))->toBeFalse()
        ->and(marketplaceOfferIsActionable(0.0))->toBeTrue();
});

it('holds an offer to the offer band, not to the wider server adjust band', function () {
    $policy = marketplaceRatioPolicy();

    // 30% is how far the server may run its own ratio; it is not a licence to take a 30%-off offer.
    expect(marketplaceOfferIsActionable((float) $policy['server_adjustment_percent']))->toBeFalse()
        ->and(marketplaceOfferIsActionable($policy['offer_band_percent'] - 0.1))->toBeTrue();
});

it('prices an offer against the ratio the server is running now', function () {
    $policy = marketplaceRatioPolicy();

    $currentRatio = $policy['standard_ratio'];
    $currentRatio['metal'] = $currentRatio['metal'] * (1.0 - $policy['server_adjustment_percent'] / 100.0);

    // The standard offer sits on the standard ratio, and far outside the band once the server has
    // re-adjusted: the AI has to read the ratio it is running now instead of a cached one.
    expect(marketplaceOfferIsActionable(0.0))->toBeTrue()
        ->and(marketplaceOfferIsActionable(0.0, $currentRatio))->toBeFalse();
});
