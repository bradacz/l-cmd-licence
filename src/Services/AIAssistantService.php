<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AIAssistantRepository;
use App\Repositories\AppRepository;

final class AIAssistantService
{
    public function run(?int $appId, string $taskType, string $inputText, ?int $triggeredBy = null): int
    {
        $app = $appId !== null ? (new AppRepository())->findById($appId) : null;
        $scope = $app !== null ? (string) $app['name'] : 'this application';
        $input = trim($inputText);

        $output = match ($taskType) {
            'support_reply' => "Draft support reply for {$scope}:\n\nHello,\n\nthank you for your message. We reviewed your licensing request and are checking the current license, activation and billing context. If you send the exact error text and the email used during purchase, we can verify the issue quickly.\n\nBest regards,\nSupport team",
            'email_draft' => "Draft email for {$scope}:\n\nSubject: Update regarding your license\n\nHello,\n\nthis is a follow-up regarding your license and product access. We are confirming the current status and will provide the next steps as soon as the verification is completed.\n\nBest regards,\n{$scope} team",
            'product_copy' => "Draft product copy for {$scope}:\n\n{$scope} gives customers a clear licensed access model with fast activation, secure verification and centralized account operations. Use this product text as a base and refine the exact value proposition for your target audience.",
            'onboarding_text' => "Draft onboarding text for {$scope}:\n\n1. Install the application.\n2. Enter your license key.\n3. Complete activation on the current device.\n4. Use the app-aware API endpoints for future verification and device management.\n\nKeep the setup short and action-oriented.",
            'policy_draft' => "Draft policy note for {$scope}:\n\nRecommended starting policy: keep activation limits conservative, use explicit platform allowlists only when needed, and enable grace-period behavior only if the product has recurring billing.",
            default => "Draft for {$scope}:\n\n" . ($input !== '' ? $input : 'No input provided.'),
        };

        if ($input !== '') {
            $output .= "\n\nInput context:\n" . $input;
        }

        return (new AIAssistantRepository())->create([
            'app_id' => $appId,
            'triggered_by' => $triggeredBy,
            'task_type' => $taskType,
            'input_text' => $input,
            'output_text' => $output,
            'status' => 'completed',
        ]);
    }

    /** @return array<string,string> */
    public static function taskTypes(): array
    {
        return [
            'support_reply' => 'Support Reply',
            'email_draft' => 'Email Draft',
            'product_copy' => 'Product Copy',
            'onboarding_text' => 'Onboarding Text',
            'policy_draft' => 'Policy Draft',
        ];
    }
}
