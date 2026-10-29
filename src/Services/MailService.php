<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppNotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TenantSmtpConnectionRepository;
use App\Support\Env;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

final class MailService
{
    private SettingsRepository $settings;

    /** @var array<string,string|null> */
    private array $config;

    private AppNotificationRepository $appNotifications;
    private TenantSmtpConnectionRepository $tenantSmtp;

    public function __construct()
    {
        $this->settings = new SettingsRepository();
        $this->appNotifications = new AppNotificationRepository();
        $this->tenantSmtp = new TenantSmtpConnectionRepository();
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $keys = [
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_email',
            'smtp_from_name',
            'mail_enabled',
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_from_address',
            'mail_from_name',
        ];

        $dbValues = $this->settings->getMultiple($keys);

        $dbHost = $dbValues['smtp_host'] ?? $dbValues['mail_host'] ?? null;
        $dbPort = $dbValues['smtp_port'] ?? $dbValues['mail_port'] ?? null;
        $dbUsername = $dbValues['smtp_username'] ?? $dbValues['mail_username'] ?? null;
        $dbPassword = $dbValues['smtp_password'] ?? $dbValues['mail_password'] ?? null;
        $dbEncryption = $dbValues['smtp_encryption'] ?? $dbValues['mail_encryption'] ?? null;
        $dbFromAddress = $dbValues['smtp_from_email'] ?? $dbValues['mail_from_address'] ?? null;
        $dbFromName = $dbValues['smtp_from_name'] ?? $dbValues['mail_from_name'] ?? null;

        $this->config = [
            'enabled' => ($dbValues['mail_enabled'] ?? Env::get('MAIL_ENABLED', '1')) === '1',
            'host' => $dbHost ?? Env::get('MAIL_HOST', 'smtp.example.invalid'),
            'port' => $dbPort ?? Env::get('MAIL_PORT', '587'),
            'username' => $dbUsername ?? Env::get('MAIL_USERNAME', ''),
            'password' => $dbPassword ?? Env::get('MAIL_PASSWORD', ''),
            'encryption' => $dbEncryption ?? Env::get('MAIL_ENCRYPTION', 'tls'),
            'from_address' => $dbFromAddress ?? Env::get('MAIL_FROM_ADDRESS', ''),
            'from_name' => $dbFromName ?? Env::get('MAIL_FROM_NAME', 'Lokalio Labs'),
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return [
            'mail_enabled' => $this->config['enabled'] ? '1' : '0',
            'mail_host' => $this->config['host'],
            'mail_port' => $this->config['port'],
            'mail_username' => $this->config['username'],
            'mail_password' => $this->config['password'] ? '***' : '',
            'mail_encryption' => $this->config['encryption'],
            'mail_from_address' => $this->config['from_address'],
            'mail_from_name' => $this->config['from_name'],
        ];
    }

    /** @param array<string,mixed>|null $notificationProfile */
    private function createMailer(?array $notificationProfile = null, ?int $tenantId = null): PHPMailer
    {
        $mail = new PHPMailer(true);
        $config = $this->configForTenant($tenantId);

        $mail->isSMTP();
        $mail->Host = (string) $config['host'];
        $mail->Port = (int) $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $config['username'];
        $mail->Password = (string) $config['password'];
        $mail->CharSet = 'UTF-8';

        $encryption = (string) $config['encryption'];
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $fromAddress = (string) $config['from_address'];
        $fromName = trim((string) ($notificationProfile['sender_name'] ?? '')) ?: (string) $config['from_name'];
        if ($fromAddress !== '') {
            $mail->setFrom($fromAddress, $fromName);
        }

        $replyTo = trim((string) ($notificationProfile['reply_to_email'] ?? ''));
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo, $fromName);
        }

        return $mail;
    }

    public function sendLicenseCreated(
        string $toEmail,
        string $licenseKey,
        string $licenseType,
        ?string $productName = null,
        ?string $customerName = null,
        ?float $price = null,
        ?string $currency = null,
        ?string $expiresAt = null,
        ?int $appId = null
    ): bool {
        $expiresFormatted = $expiresAt
            ? date('j. n. Y', strtotime($expiresAt))
            : 'neomezena';

        $priceFormatted = $price !== null
            ? number_format($price, 2, ',', ' ') . ' ' . ($currency ?? 'CZK')
            : '-';

        $typeLabels = [
            'lifetime' => 'Doživotní licence',
            'annual' => 'Roční licence',
            'monthly' => 'Měsíční licence',
            'whitelabel' => 'Whitelabel licence',
            'trial' => 'Zkušební licence',
        ];
        $typeLabel = $typeLabels[$licenseType] ?? $licenseType;

        $vars = [
            '{{name}}' => $customerName ?? '',
            '{{email}}' => $toEmail,
            '{{key}}' => $licenseKey,
            '{{type}}' => $typeLabel,
            '{{expires}}' => $expiresFormatted,
            '{{price}}' => $priceFormatted,
            '{{currency}}' => $currency ?? 'CZK',
        ];

        $notificationProfile = $appId !== null ? $this->appNotifications->profileForApp($appId) : null;
        $tenantId = $appId !== null && $appId > 0 ? (new TenantContextService())->tenantIdForAppId($appId) : null;
        if (!$this->isEnabledForTenant($tenantId)) {
            return false;
        }
        if ($notificationProfile !== null && (int) ($notificationProfile['is_enabled'] ?? 1) !== 1) {
            return false;
        }

        $appOverride = $appId !== null ? $this->appNotifications->templateOverrideForApp($appId, 'mail_license') : null;
        $dbSubject = $this->settings->get('mail_license_subject');
        $dbBody = $this->settings->get('mail_license_body');
        $overrideEnabled = $appOverride !== null && (int) ($appOverride['is_enabled'] ?? 0) === 1;
        $overrideSubject = $overrideEnabled ? trim((string) ($appOverride['subject'] ?? '')) : '';
        $overrideBody = $overrideEnabled ? trim((string) ($appOverride['body'] ?? '')) : '';

        if ($overrideBody !== '') {
            $html = str_replace(array_keys($vars), array_values($vars), $overrideBody);
        } elseif ($dbBody !== null && $dbBody !== '') {
            $html = str_replace(array_keys($vars), array_values($vars), $dbBody);
        } else {
            $html = $this->buildLicenseEmailHtml(
                $productName ?? 'License Server',
                $customerName ?? '',
                $licenseKey,
                $typeLabel,
                $priceFormatted,
                $expiresFormatted
            );
        }

        if ($overrideSubject !== '') {
            $subject = str_replace(array_keys($vars), array_values($vars), $overrideSubject);
        } elseif ($dbSubject !== null && $dbSubject !== '') {
            $subject = str_replace(array_keys($vars), array_values($vars), $dbSubject);
        } else {
            $subject = "Vas licencni klic - " . ($productName !== null && $productName !== '' ? "{$productName} / {$typeLabel}" : $typeLabel);
        }

        try {
            $mail = $this->createMailer($notificationProfile, $tenantId);
            $mail->addAddress($toEmail, $customerName ?? '');
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = "Vas licencni klic: {$licenseKey}";

            return $mail->send();
        } catch (Exception $e) {
            error_log("MailService::sendLicenseCreated failed: " . $e->getMessage());
            return false;
        }
    }

    /** @return array<string,string> */
    public function getTemplates(): array
    {
        return [
            'mail_license_subject' => $this->settings->get('mail_license_subject') ?? '',
            'mail_license_body' => $this->settings->get('mail_license_body') ?? '',
        ];
    }

    public function getDefaultTemplates(): array
    {
        return [
            'mail_license_subject' => 'Vas licencni klic - {{type}}',
            'mail_license_body' => $this->buildLicenseEmailHtml('{{name}}', '{{key}}', '{{type}}', '{{price}}', '{{expires}}'),
        ];
    }

    public function sendTestEmail(string $toEmail): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Mail je vypnuty'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = 'Test email - Lokalio Labs License Server';
            $mail->Body = '<h1>Test email</h1><p>Pokud vidite tuto zpravu, SMTP je spravne nastaveno.</p>';
            $mail->AltBody = 'Test email - SMTP funguje.';

            $mail->send();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendGenericEmail(string $toEmail, string $subject, string $htmlBody, string $textBody): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            return $mail->send();
        } catch (Exception $e) {
            error_log('MailService::sendGenericEmail failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendTenantOnboarding(string $toEmail, string $accessKey, string $portalUrl, string $planLabel): bool
    {
        $subject = 'Your SimplyKeys access is ready';
        $html = '<p>Your SimplyKeys workspace is ready.</p>'
            . '<p><strong>Plan:</strong> ' . htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Access key:</strong> ' . htmlspecialchars($accessKey, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Continue at <a href="' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '</a>.</p>';
        $text = "Your SimplyKeys workspace is ready.\n\nPlan: {$planLabel}\nAccess key: {$accessKey}\nPortal: {$portalUrl}\n";

        return $this->sendGenericEmail($toEmail, $subject, $html, $text);
    }

    public function sendContactNotification(string $recipientEmail, array $data): bool
    {
        $subject = 'Nová kontaktní zpráva ze SimplyKeys';
        $html = '<p>Přišla nová zpráva z veřejného formuláře SimplyKeys.</p>'
            . '<p><strong>Jméno:</strong> ' . htmlspecialchars((string) ($data['fullName'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>E-mail:</strong> ' . htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Firma:</strong> ' . htmlspecialchars((string) ($data['companyName'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Telefon:</strong> ' . htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zdroj:</strong> ' . htmlspecialchars((string) ($data['pageSource'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zpráva:</strong></p><p>' . nl2br(htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>';
        $text = "Nová kontaktní zpráva ze SimplyKeys\n\n"
            . 'Jméno: ' . (string) ($data['fullName'] ?? '') . "\n"
            . 'E-mail: ' . (string) ($data['email'] ?? '') . "\n"
            . 'Firma: ' . (string) ($data['companyName'] ?? '') . "\n"
            . 'Telefon: ' . (string) ($data['phone'] ?? '') . "\n"
            . 'Zdroj: ' . (string) ($data['pageSource'] ?? '') . "\n\n"
            . "Zpráva:\n" . (string) ($data['message'] ?? '');

        return $this->sendGenericEmail($recipientEmail, $subject, $html, $text);
    }

    public function sendBookingNotification(string $recipientEmail, array $data): bool
    {
        $subject = 'Nová žádost o konzultaci ze SimplyKeys';
        $html = '<p>Přišla nová žádost o konzultaci ze SimplyKeys.</p>'
            . '<p><strong>Jméno:</strong> ' . htmlspecialchars((string) ($data['fullName'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>E-mail:</strong> ' . htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Telefon:</strong> ' . htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Preferované datum:</strong> ' . htmlspecialchars((string) ($data['preferredDate'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Preferovaný čas:</strong> ' . htmlspecialchars((string) ($data['preferredTime'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zdroj:</strong> ' . htmlspecialchars((string) ($data['pageSource'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zpráva:</strong></p><p>' . nl2br(htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>';
        $text = "Nová žádost o konzultaci ze SimplyKeys\n\n"
            . 'Jméno: ' . (string) ($data['fullName'] ?? '') . "\n"
            . 'E-mail: ' . (string) ($data['email'] ?? '') . "\n"
            . 'Telefon: ' . (string) ($data['phone'] ?? '') . "\n"
            . 'Preferované datum: ' . (string) ($data['preferredDate'] ?? '') . "\n"
            . 'Preferovaný čas: ' . (string) ($data['preferredTime'] ?? '') . "\n"
            . 'Zdroj: ' . (string) ($data['pageSource'] ?? '') . "\n\n"
            . "Zpráva:\n" . (string) ($data['message'] ?? '');

        return $this->sendGenericEmail($recipientEmail, $subject, $html, $text);
    }

    public function sendContactConfirmation(string $toEmail, string $fullName): bool
    {
        $subject = 'Potvrzení přijetí zprávy';
        $html = '<p>Děkujeme za zprávu.</p>'
            . '<p>Ozveme se vám co nejdříve na uvedený e-mail.</p>'
            . '<p>S pozdravem,<br><strong>Localio Labs s.r.o.</strong></p>';
        $text = "Děkujeme za zprávu.\nOzveme se vám co nejdříve na uvedený e-mail.\n\nS pozdravem,\nLocalio Labs s.r.o.";

        return $this->sendGenericEmail($toEmail, $subject, $html, $text);
    }

    public function sendBookingConfirmation(string $toEmail, string $fullName): bool
    {
        $subject = 'Potvrzení žádosti o konzultaci';
        $html = '<p>Děkujeme za žádost o konzultaci.</p>'
            . '<p>Ozveme se vám s potvrzením termínu.</p>'
            . '<p>S pozdravem,<br><strong>Localio Labs s.r.o.</strong></p>';
        $text = "Děkujeme za žádost o konzultaci.\nOzveme se vám s potvrzením termínu.\n\nS pozdravem,\nLocalio Labs s.r.o.";

        return $this->sendGenericEmail($toEmail, $subject, $html, $text);
    }

    public function sendNewsletterNotification(string $recipientEmail, array $data): bool
    {
        $subject = 'Nové přihlášení k odběru novinek ze SimplyKeys';
        $fullName = trim(((string) ($data['firstName'] ?? '')) . ' ' . ((string) ($data['lastName'] ?? '')));
        $html = '<p>Nové přihlášení k odběru novinek ze SimplyKeys.</p>'
            . '<p><strong>Jméno:</strong> ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>E-mail:</strong> ' . htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Firma:</strong> ' . htmlspecialchars((string) ($data['company'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zdroj:</strong> ' . htmlspecialchars((string) ($data['pageSource'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
        $text = "Nové přihlášení k odběru novinek ze SimplyKeys\n\n"
            . 'Jméno: ' . $fullName . "\n"
            . 'E-mail: ' . (string) ($data['email'] ?? '') . "\n"
            . 'Firma: ' . (string) ($data['company'] ?? '') . "\n"
            . 'Zdroj: ' . (string) ($data['pageSource'] ?? '');

        return $this->sendGenericEmail($recipientEmail, $subject, $html, $text);
    }

    public function sendNewsletterConfirmation(string $toEmail, string $firstName): bool
    {
        $subject = 'Potvrzení přihlášení k odběru';
        $html = '<p>Děkujeme za přihlášení k odběru novinek.</p>'
            . '<p>Budeme vás informovat o novinkách a důležitých aktualitách.</p>'
            . '<p>S pozdravem,<br><strong>Localio Labs s.r.o.</strong></p>';
        $text = "Děkujeme za přihlášení k odběru novinek.\nBudeme vás informovat o novinkách a důležitých aktualitách.\n\nS pozdravem,\nLocalio Labs s.r.o.";

        return $this->sendGenericEmail($toEmail, $subject, $html, $text);
    }

    public function testConnection(): array
    {
        try {
            $mail = $this->createMailer();
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

    public function sendExpiringSoon(int $licenseId): bool
    {
        return $this->sendTemplateEmail('expiring_soon', $licenseId);
    }

    public function sendExpired(int $licenseId): bool
    {
        return $this->sendTemplateEmail('expired', $licenseId);
    }

    public function sendRenewalSuccess(int $licenseId): bool
    {
        return $this->sendTemplateEmail('renewal_success', $licenseId);
    }

    private function sendTemplateEmail(string $templateKey, int $licenseId): bool
    {
        $pdo = \App\Database::pdo();
        $license = $pdo->prepare('SELECT * FROM licenses WHERE id = :id');
        $license->execute(['id' => $licenseId]);
        $lic = $license->fetch();
        if (!$lic) {
            return false;
        }

        $tenantId = isset($lic['tenant_id']) ? (int) $lic['tenant_id'] : null;
        if (!$this->isEnabledForTenant($tenantId)) {
            return false;
        }

        $appId = isset($lic['app_id']) ? (int) $lic['app_id'] : null;
        $notificationProfile = $appId !== null && $appId > 0 ? $this->appNotifications->profileForApp($appId) : null;
        if ($notificationProfile !== null && (int) ($notificationProfile['is_enabled'] ?? 1) !== 1) {
            return false;
        }

        $tpl = $pdo->prepare('SELECT * FROM email_templates WHERE template_key = :key');
        $tpl->execute(['key' => $templateKey]);
        $template = $tpl->fetch();
        if (!$template) {
            return false;
        }

        $appOverride = $appId !== null && $appId > 0
            ? $this->appNotifications->templateOverrideForApp($appId, $templateKey)
            : null;
        if ($appOverride !== null && (int) ($appOverride['is_enabled'] ?? 0) !== 1) {
            $appOverride = null;
        }

        $placeholders = [
            '{customer_name}' => (string) ($lic['customer_name'] ?? ''),
            '{customer_email}' => (string) $lic['customer_email'],
            '{license_key}' => (string) $lic['license_key'],
            '{product_name}' => (string) ($lic['product_name'] ?? $lic['product_slug'] ?? 'License Server'),
            '{license_type}' => (string) $lic['license_type'],
            '{expires_at}' => $lic['expires_at'] ? date('j. n. Y', strtotime((string) $lic['expires_at'])) : 'neomezená',
            '{activation_count}' => (string) $lic['current_activations'],
            '{max_activations}' => (string) $lic['max_activations'],
        ];

        $subjectTemplate = trim((string) ($appOverride['subject'] ?? '')) ?: (string) $template['subject'];
        $bodyTemplate = trim((string) ($appOverride['body'] ?? '')) ?: (string) $template['body'];
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);

        try {
            $mail = $this->createMailer($notificationProfile, $tenantId);
            $mail->addAddress((string) $lic['customer_email'], (string) ($lic['customer_name'] ?? ''));
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $sent = $mail->send();

            $audit = new \App\Repositories\AuditLogRepository();
            $audit->log(null, $licenseId, 'email.sent.' . $templateKey, 'license', $licenseId, [], [
                'recipient' => $lic['customer_email'],
                'subject' => $subject,
                'license_key' => $lic['license_key'],
            ], '0.0.0.0', 'system');

            return $sent;
        } catch (Exception $e) {
            error_log("MailService::{$templateKey} failed: " . $e->getMessage());
            return false;
        }
    }

    private function buildLicenseEmailHtml(
        string $productName,
        string $customerName,
        string $licenseKey,
        string $typeLabel,
        string $priceFormatted,
        string $expiresFormatted
    ): string {
        $greeting = $customerName !== '' ? "Ahoj {$customerName}," : 'Ahoj,';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0a0b;color:#fafafa">
<div style="max-width:520px;margin:0 auto;padding:40px 24px">
  <div style="text-align:center;margin-bottom:32px">
    <div style="display:inline-block;width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#6c5ce7,#a78bfa);line-height:48px;font-size:24px;color:#fff;font-weight:700">L</div>
    <h1 style="font-size:22px;font-weight:700;margin:16px 0 4px">Vas licencni klic</h1>
    <p style="font-size:13px;color:#d4d4d8;margin:0 0 8px">Produkt: {$productName}</p>
    <p style="font-size:14px;color:#a1a1aa;margin:0">Dekujeme za nakup!</p>
  </div>

  <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;padding:32px">
    <p style="font-size:15px;color:#d4d4d8;margin:0 0 24px">{$greeting}</p>

    <table style="width:100%;border-collapse:collapse;font-size:14px;color:#d4d4d8">
      <tr>
        <td style="padding:8px 0;color:#a1a1aa">Typ licence</td>
        <td style="padding:8px 0;text-align:right;font-weight:600;color:#fafafa">{$typeLabel}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#a1a1aa">Cena</td>
        <td style="padding:8px 0;text-align:right;font-weight:600;color:#fafafa">{$priceFormatted}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#a1a1aa">Platnost do</td>
        <td style="padding:8px 0;text-align:right;font-weight:600;color:#fafafa">{$expiresFormatted}</td>
      </tr>
    </table>

    <div style="margin:24px 0;border-top:1px solid #27272a"></div>

    <p style="text-align:center;font-size:12px;color:#a1a1aa;margin:0 0 12px">Vas licencni klic:</p>
    <div style="text-align:center;padding:16px;background:#0a0a0b;border:1px solid #6c5ce7;border-radius:12px">
      <code style="font-size:18px;letter-spacing:2px;color:#a78bfa;font-weight:700">{$licenseKey}</code>
    </div>
    <p style="text-align:center;font-size:12px;color:#71717a;margin:12px 0 0">
      Ulozte si klic na bezpecnem miste.
    </p>
  </div>

  <p style="text-align:center;font-size:11px;color:#52525b;margin-top:24px">
    &copy; 2024 Lokalio Labs. Vsechna prava vyhrazena.
  </p>
</div>
</body>
</html>
HTML;
    }

    /** @return array<string,string|null|bool> */
    private function configForTenant(?int $tenantId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            $tenantConfig = $this->tenantSmtp->findByTenantId($tenantId);
            if ($tenantConfig !== null && (bool) ($tenantConfig['is_enabled'] ?? false)) {
                return [
                    'enabled' => true,
                    'host' => (string) ($tenantConfig['host'] ?? ''),
                    'port' => (string) (int) ($tenantConfig['port'] ?? 587),
                    'username' => (string) ($tenantConfig['username'] ?? ''),
                    'password' => (string) ($tenantConfig['password'] ?? ''),
                    'encryption' => (string) ($tenantConfig['encryption'] ?? 'tls'),
                    'from_address' => (string) ($tenantConfig['from_email'] ?? ''),
                    'from_name' => (string) ($tenantConfig['from_name'] ?? 'L-CMD License Server'),
                ];
            }
            if ($tenantConfig !== null && (bool) ($tenantConfig['is_enabled'] ?? true) === false) {
                return [
                    'enabled' => false,
                    'host' => '',
                    'port' => '587',
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls',
                    'from_address' => '',
                    'from_name' => 'L-CMD License Server',
                ];
            }
        }

        return $this->config;
    }

    private function isEnabledForTenant(?int $tenantId): bool
    {
        return (bool) ($this->configForTenant($tenantId)['enabled'] ?? false);
    }
}
