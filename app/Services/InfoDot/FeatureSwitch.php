<?php

namespace App\Services\InfoDot;

/**
 * Whether InfoDot has switched one feature of this platform off (R-61). The feature's backend action asks here before it runs, so a switched-off
 * feature is blocked for every way of reaching it, not just hidden in the screen. It refuses only that feature: the rest of the platform, the
 * data and any purchase are untouched. No answer from InfoDot, or a company with no InfoDot record, means the feature is available (the same
 * last-known-good and cache rules as the platform check apply, see CompanyEntitlement).
 */
class FeatureSwitch
{
    public function __construct(private readonly CompanyEntitlement $entitlement) {}

    /** @return array{available: bool, state: string, message: ?string} */
    public function of(?string $companyUuid, string $feature): array
    {
        if (blank($companyUuid) || ! $this->entitlement->enabled()) {
            return ['available' => true, 'state' => 'available', 'message' => null];
        }

        $row = $this->entitlement->of($companyUuid)['features'][$feature] ?? null;

        if ($row === null || $row['state'] === 'available') {
            return ['available' => true, 'state' => 'available', 'message' => null];
        }

        return ['available' => false, 'state' => $row['state'], 'message' => $row['message']];
    }
}
