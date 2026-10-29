<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Services\MaintenanceService;

$service = new MaintenanceService();
$deactivated = $service->deactivateInactiveActivations(30);
$prunedRate = $service->pruneRateLimitRows(48);
$prunedVerifications = $service->pruneOldVerifications(90);
$expired = $service->expireLicenses();
$expiring = $service->checkExpiringLicenses(7);

fwrite(STDOUT, sprintf(
    "deactivated=%d pruned_rate=%d pruned_verifications=%d expired=%d expiring_notified=%d\n",
    $deactivated, $prunedRate, $prunedVerifications, $expired, $expiring
));
