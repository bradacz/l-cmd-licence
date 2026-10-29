<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantRepository;

final class DemoTenantService
{
    public function isDemoTenantId(int $tenantId): bool
    {
        $tenant = (new TenantRepository())->findById($tenantId);

        return (int) ($tenant['is_demo'] ?? 0) === 1;
    }

    /** @param array<string,mixed> $current
      * @return array<string,mixed>|null */
    public function simulatedCustomerAction(array $current, string $action, array $context = []): ?array
    {
        if (!$this->isDemoTenantId((int) ($current['tenant_id'] ?? 0))) {
            return null;
        }

        return match ($action) {
            'stripe_test' => [
                'success' => true,
                'mode' => 'demo',
                'message' => 'Stripe test was simulated in demo mode.',
            ],
            'smtp_test' => [
                'success' => true,
                'mode' => 'demo',
                'message' => 'SMTP test was simulated in demo mode.',
            ],
            'template_test' => [
                'success' => true,
                'mode' => 'demo',
                'message' => 'Template send was simulated in demo mode.',
                'preview' => [
                    'subject' => (string) ($context['subject'] ?? ''),
                    'body' => (string) ($context['body'] ?? ''),
                ],
            ],
            'ai_insights_run' => [
                'success' => true,
                'mode' => 'demo',
                'message' => 'AI insight run was simulated in demo mode.',
                'run_id' => 0,
                'recent_runs' => [],
            ],
            'ai_assistant_run' => [
                'success' => true,
                'mode' => 'demo',
                'message' => 'AI assistant run was simulated in demo mode.',
                'run_id' => 0,
                'recent_runs' => [],
            ],
            default => null,
        };
    }
}
