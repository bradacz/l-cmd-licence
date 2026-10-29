<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AIInsightsRepository;
use App\Repositories\AppRepository;
use App\Repositories\OperationsRepository;

final class AIInsightsService
{
    public function run(?int $appId, string $insightType, ?int $triggeredBy = null): int
    {
        $app = $appId !== null ? (new AppRepository())->findById($appId) : null;
        $ops = new OperationsRepository();

        $overview = $ops->appOverview($appId);
        $verifications = $ops->verificationSummary($appId);
        $activations = $ops->activationSummary($appId);
        $health = $ops->healthSummary();
        $webhooks = $ops->recentWebhooks($appId, 10);

        $inputSummary = sprintf(
            'App scope: %s. Insight type: %s. Licenses: %d. Active: %d. Verifications 24h: %d. Activations 24h: %d.',
            $app !== null ? (string) $app['name'] : 'all apps',
            $insightType,
            (int) ($overview['total_licenses'] ?? 0),
            (int) ($overview['active_licenses'] ?? 0),
            (int) ($verifications['last_24h'] ?? 0),
            (int) ($activations['last_24h'] ?? 0)
        );

        $lines = [];
        $lines[] = 'This is an internal deterministic summary generated inside L-CMD License Server.';
        $lines[] = 'Scope: ' . ($app !== null ? ((string) $app['name'] . ' (' . (string) $app['slug'] . ')') : 'all applications') . '.';

        if ($insightType === 'webhook_summary') {
            $lines[] = 'Recent webhook events checked: ' . count($webhooks) . '.';
            $lines[] = 'Webhook failures in last 24 hours: ' . (int) ($health['webhook_failures_24h'] ?? 0) . '.';
        } elseif ($insightType === 'failed_jobs_summary') {
            $lines[] = 'Failed jobs in last 24 hours: ' . (int) ($health['failed_jobs_24h'] ?? 0) . '.';
            $lines[] = 'Pending jobs: ' . (int) ($health['pending_jobs'] ?? 0) . '. Reserved jobs: ' . (int) ($health['reserved_jobs'] ?? 0) . '.';
        } elseif ($insightType === 'audit_summary') {
            $lines[] = 'Last audit activity timestamp: ' . (string) ($health['last_audit_at'] ?? 'n/a') . '.';
            $lines[] = 'Use Audit Log for exact record-level inspection.';
        } else {
            $lines[] = 'Total licenses: ' . (int) ($overview['total_licenses'] ?? 0) . ', active: ' . (int) ($overview['active_licenses'] ?? 0) . ', expired: ' . (int) ($overview['expired_licenses'] ?? 0) . '.';
            $lines[] = 'Verifications in last 7 days: ' . (int) ($verifications['last_7d'] ?? 0) . '. Activations in last 7 days: ' . (int) ($activations['last_7d'] ?? 0) . '.';
        }

        $lines[] = 'Recommended operator action: review recent webhook and audit records if failures rise or if active license growth stops unexpectedly.';

        return (new AIInsightsRepository())->create([
            'app_id' => $appId,
            'triggered_by' => $triggeredBy,
            'insight_type' => $insightType,
            'input_summary' => $inputSummary,
            'output_text' => implode("\n", $lines),
            'source_context' => [
                'overview' => $overview,
                'verifications' => $verifications,
                'activations' => $activations,
                'health' => $health,
                'recent_webhooks_count' => count($webhooks),
            ],
            'status' => 'completed',
        ]);
    }

    /** @return array<string,string> */
    public static function insightTypes(): array
    {
        return [
            'ops_summary' => 'Ops Summary',
            'webhook_summary' => 'Webhook Summary',
            'failed_jobs_summary' => 'Failed Jobs Summary',
            'audit_summary' => 'Audit Summary',
        ];
    }
}
