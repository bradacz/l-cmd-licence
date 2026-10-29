<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TenantSmtpConnectionRepository;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class CustomerSmtpService
{
    private TenantSmtpConnectionRepository $connections;

    public function __construct()
    {
        $this->connections = new TenantSmtpConnectionRepository();
    }

    /** @return array<string,mixed> */
    public function getConfig(int $tenantId): array
    {
        $config = $this->connections->findByTenantId($tenantId) ?? [];

        return [
            'is_enabled' => (bool) ($config['is_enabled'] ?? true),
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 587),
            'username' => (string) ($config['username'] ?? ''),
            'password' => !empty($config['password']) ? '***' : '',
            'encryption' => (string) ($config['encryption'] ?? 'tls'),
            'from_email' => (string) ($config['from_email'] ?? ''),
            'from_name' => (string) ($config['from_name'] ?? ''),
        ];
    }

    public function update(int $tenantId, array $payload): void
    {
        $current = $this->connections->findByTenantId($tenantId) ?? [];
        $password = trim((string) ($payload['password'] ?? ''));

        $this->connections->upsert($tenantId, [
            'is_enabled' => (bool) ($payload['is_enabled'] ?? ($current['is_enabled'] ?? true)),
            'host' => trim((string) ($payload['host'] ?? ($current['host'] ?? ''))),
            'port' => (int) ($payload['port'] ?? ($current['port'] ?? 587)),
            'username' => trim((string) ($payload['username'] ?? ($current['username'] ?? ''))),
            'password' => str_contains($password, '***') ? (string) ($current['password'] ?? '') : $password,
            'encryption' => trim((string) ($payload['encryption'] ?? ($current['encryption'] ?? 'tls'))),
            'from_email' => trim((string) ($payload['from_email'] ?? ($current['from_email'] ?? ''))),
            'from_name' => trim((string) ($payload['from_name'] ?? ($current['from_name'] ?? ''))),
        ]);
    }

    /** @return array<string,mixed> */
    public function testConnection(int $tenantId): array
    {
        try {
            $mail = $this->createMailer($tenantId);
            $mail->SMTPDebug = SMTP::DEBUG_OFF;

            if ($mail->smtpConnect()) {
                $mail->smtpClose();
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'SMTP connect failed'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    public function sendTestEmail(int $tenantId, string $toEmail): array
    {
        return $this->sendRawEmail(
            $tenantId,
            $toEmail,
            'Test email - SimplyKeys',
            '<h1>Test email</h1><p>Pokud vidíte tuto zprávu, SMTP nastavení pro váš workspace funguje správně.</p>',
            'Test email - SMTP nastavení pro váš workspace funguje správně.'
        );
    }

    /** @return array<string,mixed> */
    public function sendRawEmail(int $tenantId, string $toEmail, string $subject, string $htmlBody, string $textBody): array
    {
        try {
            $mail = $this->createMailer($tenantId);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function createMailer(int $tenantId): PHPMailer
    {
        $config = $this->connections->findByTenantId($tenantId) ?? [];
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) ($config['host'] ?? '');
        $mail->Port = (int) ($config['port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) ($config['username'] ?? '');
        $mail->Password = (string) ($config['password'] ?? '');
        $mail->CharSet = 'UTF-8';

        $encryption = (string) ($config['encryption'] ?? 'tls');
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        if ($fromEmail !== '') {
            $mail->setFrom($fromEmail, (string) ($config['from_name'] ?? 'SimplyKeys'));
        }

        return $mail;
    }
}
