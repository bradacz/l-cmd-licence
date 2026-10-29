<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FormRequestLogRepository;
use App\Repositories\SettingsRepository;

final class PublicFormService
{
    private FormRequestLogRepository $logs;
    private SettingsRepository $settings;
    private MailService $mail;

    public function __construct()
    {
        $this->logs = new FormRequestLogRepository();
        $this->settings = new SettingsRepository();
        $this->mail = new MailService();
    }

    public function submitContact(array $payload, array $context): array
    {
        $data = [
            'fullName' => $this->cleanText($payload['fullName'] ?? '', 100),
            'companyName' => $this->cleanText($payload['companyName'] ?? '', 100),
            'email' => $this->normalizeEmail($payload['email'] ?? ''),
            'phone' => $this->cleanText($payload['phone'] ?? '', 30),
            'message' => $this->cleanText($payload['message'] ?? '', 2000),
            'pageSource' => $this->cleanText($payload['pageSource'] ?? '', 100),
        ];

        return $this->submit('contact', $data, $payload, $context);
    }

    public function submitBooking(array $payload, array $context): array
    {
        $data = [
            'fullName' => $this->cleanText($payload['fullName'] ?? '', 100),
            'email' => $this->normalizeEmail($payload['email'] ?? ''),
            'phone' => $this->cleanText($payload['phone'] ?? '', 30),
            'message' => $this->cleanText($payload['message'] ?? '', 2000),
            'preferredDate' => $this->cleanText($payload['preferredDate'] ?? '', 20),
            'preferredTime' => $this->cleanText($payload['preferredTime'] ?? '', 50),
            'pageSource' => $this->cleanText($payload['pageSource'] ?? '', 100),
        ];

        return $this->submit('booking', $data, $payload, $context);
    }

    public function submitNewsletter(array $payload, array $context): array
    {
        $data = [
            'firstName' => $this->cleanText($payload['firstName'] ?? '', 100),
            'lastName' => $this->cleanText($payload['lastName'] ?? '', 100),
            'company' => $this->cleanText($payload['company'] ?? '', 200),
            'email' => $this->normalizeEmail($payload['email'] ?? ''),
            'pageSource' => $this->cleanText($payload['pageSource'] ?? '', 100),
        ];

        return $this->submit('newsletter', $data, $payload, $context);
    }

    public function publicConfig(): array
    {
        return [
            'turnstileSiteKey' => trim((string) ($this->settings->get('turnstile_site_key') ?? '')),
            'enabled' => trim((string) ($this->settings->get('turnstile_site_key') ?? '')) !== '',
        ];
    }

    private function submit(string $type, array $data, array $payload, array $context): array
    {
        $ip = (string) ($context['ip'] ?? '0.0.0.0');
        $userAgent = $this->cleanText($context['userAgent'] ?? '', 500);
        $honeypot = trim((string) ($payload['honeypot'] ?? ''));
        $recipient = trim((string) ($this->settings->get('contact_form_recipient_email') ?? ''));

        if ($honeypot !== '') {
            $this->log($type, $data['email'] ?? null, $ip, $userAgent, $data['pageSource'] ?? null, 'blocked', 100, 'honeypot');
            return ['success' => true, 'message' => 'sent'];
        }

        $validation = $this->validate($type, $data);
        if ($validation !== null) {
            $this->log($type, $data['email'] ?? null, $ip, $userAgent, $data['pageSource'] ?? null, 'validation_failed', 0, $validation['reason']);
            return $validation['response'];
        }

        $spamScore = $this->spamScore($payload, $data, $userAgent);
        if ($this->isTooFast($payload['startedAt'] ?? null)) {
            $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'blocked', max(50, $spamScore), 'submitted_too_fast');
            return ['success' => true, 'message' => 'sent'];
        }

        if ($this->logs->countRecentByIp($type, $ip, 15) >= 5 || $this->logs->countRecentByEmail($type, $data['email'], 30) >= 3) {
            $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'rate_limited', $spamScore, 'rate_limited');
            return [
                'success' => false,
                'error' => 'rate_limited',
                'message' => 'Too many requests. Please try again later.',
                'status' => 429,
            ];
        }

        if (!$this->verifyTurnstile((string) ($payload['turnstileToken'] ?? ''), $ip)) {
            $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'captcha_failed', max(30, $spamScore), 'captcha_failed');
            return [
                'success' => false,
                'error' => 'captcha_failed',
                'message' => 'We could not verify your form submission. Please try again.',
                'status' => 403,
            ];
        }

        if ($recipient === '') {
            $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'email_failed', $spamScore, 'missing_recipient');
            return [
                'success' => false,
                'error' => 'send_failed',
                'message' => "We couldn't send the form right now.",
                'status' => 500,
            ];
        }

        if ($type === 'booking') {
            $sent = $this->mail->sendBookingNotification($recipient, $data);
        } elseif ($type === 'newsletter') {
            $sent = $this->mail->sendNewsletterNotification($recipient, $data);
        } else {
            $sent = $this->mail->sendContactNotification($recipient, $data);
        }

        if (!$sent) {
            $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'email_failed', $spamScore, 'notification_failed');
            return [
                'success' => false,
                'error' => 'send_failed',
                'message' => "We couldn't send the form right now.",
                'status' => 500,
            ];
        }

        if ($type === 'booking') {
            $this->mail->sendBookingConfirmation($data['email'], $data['fullName']);
        } elseif ($type === 'newsletter') {
            $this->mail->sendNewsletterConfirmation($data['email'], $data['firstName']);
        } else {
            $this->mail->sendContactConfirmation($data['email'], $data['fullName']);
        }

        $this->log($type, $data['email'], $ip, $userAgent, $data['pageSource'] ?? null, 'accepted', $spamScore, null);

        return ['success' => true, 'message' => 'sent'];
    }

    private function validate(string $type, array $data): ?array
    {
        if ($type === 'newsletter') {
            if (($data['email'] ?? '') === '') {
                return [
                    'reason' => 'missing_required_fields',
                    'response' => [
                        'success' => false,
                        'error' => 'validation_failed',
                        'message' => 'Please fill in all required fields.',
                        'status' => 400,
                    ],
                ];
            }

            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'reason' => 'invalid_email',
                    'response' => [
                        'success' => false,
                        'error' => 'validation_failed',
                        'message' => 'Please enter a valid email address.',
                        'status' => 400,
                    ],
                ];
            }

            if (($data['firstName'] ?? '') === '' || ($data['lastName'] ?? '') === '') {
                return [
                    'reason' => 'missing_required_fields',
                    'response' => [
                        'success' => false,
                        'error' => 'validation_failed',
                        'message' => 'Please fill in all required fields.',
                        'status' => 400,
                    ],
                ];
            }

            return null;
        }

        if (($data['fullName'] ?? '') === '' || ($data['email'] ?? '') === '') {
            return [
                'reason' => 'missing_required_fields',
                'response' => [
                    'success' => false,
                    'error' => 'validation_failed',
                    'message' => 'Please fill in all required fields.',
                    'status' => 400,
                ],
            ];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'reason' => 'invalid_email',
                'response' => [
                    'success' => false,
                    'error' => 'validation_failed',
                    'message' => 'Please enter a valid email address.',
                    'status' => 400,
                ],
            ];
        }

        if ($type === 'booking') {
            return null;
        }

        if (($data['message'] ?? '') !== '' && mb_strlen((string) $data['message']) < 5) {
            return [
                'reason' => 'message_too_short',
                'response' => [
                    'success' => false,
                    'error' => 'validation_failed',
                    'message' => 'Your message is too short.',
                    'status' => 400,
                ],
            ];
        }

        return null;
    }

    private function verifyTurnstile(string $token, string $ip): bool
    {
        $secret = trim((string) ($this->settings->get('turnstile_secret_key') ?? ''));
        if ($secret === '' || trim($token) === '') {
            return false;
        }

        $body = http_build_query([
            'secret' => $secret,
            'response' => trim($token),
            'remoteip' => $ip,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if ($result === false) {
            return false;
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) && !empty($decoded['success']);
    }

    private function isTooFast(mixed $startedAt): bool
    {
        $value = is_numeric($startedAt) ? (int) $startedAt : 0;
        if ($value <= 0) {
            return false;
        }

        if ($value > 1000000000000) {
            $value = (int) floor($value / 1000);
        }

        return (time() - $value) < 2;
    }

    private function spamScore(array $payload, array $data, string $userAgent): int
    {
        $score = 0;
        $message = (string) ($data['message'] ?? '');
        if ($this->countUrls($message) > 2) {
            $score += 20;
        }
        if ($message !== '' && preg_match('/<[^>]+>/', $message) === 1) {
            $score += 20;
        }
        if ($userAgent === '' || str_contains(mb_strtolower($userAgent), 'bot')) {
            $score += 10;
        }
        if ($this->isTooFast($payload['startedAt'] ?? null)) {
            $score += 50;
        }

        return $score;
    }

    private function countUrls(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        preg_match_all('/https?:\/\/|www\./i', $text, $matches);
        return count($matches[0] ?? []);
    }

    private function normalizeEmail(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function cleanText(mixed $value, int $maxLength): string
    {
        $text = trim((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return mb_substr($text, 0, $maxLength);
    }

    private function log(
        string $type,
        ?string $email,
        string $ip,
        string $userAgent,
        ?string $pageSource,
        string $status,
        int $spamScore,
        ?string $failureReason
    ): void {
        $this->logs->create([
            'type' => $type,
            'email' => $email,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'page_source' => $pageSource,
            'status' => $status,
            'spam_score' => $spamScore,
            'failure_reason' => $failureReason,
        ]);
    }
}
