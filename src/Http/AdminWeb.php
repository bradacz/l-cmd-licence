<?php

declare(strict_types=1);

namespace App\Http;

use App\Database;
use App\Repositories\AIAssistantRepository;
use App\Repositories\AIInsightsRepository;
use App\Repositories\AISettingsRepository;
use App\Repositories\AppProductRepository;
use App\Repositories\AppKeyRepository;
use App\Repositories\AppNotificationRepository;
use App\Repositories\AppRepository;
use App\Repositories\GoogleAnalyticsRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\OperationsRepository;
use App\Repositories\PolicyRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TenantAccessKeyRepository;
use App\Repositories\TenantRepository;
use App\Services\AppKeyService;
use App\Services\AppProductService;
use App\Services\AIAssistantService;
use App\Services\AIInsightsService;
use App\Services\CustomerAuthService;
use App\Services\CsrfService;
use App\Services\GoogleAnalyticsService;
use App\Services\RateLimiter;
use App\Services\TenantBillingService;
use App\Services\TenantOverageBillingService;
use App\Services\AuthService;
use App\Support\PolicyCatalog;
use App\Support\AppCheckoutUrlConfig;
use App\Support\Env;
use App\Support\CurrencyCatalog;
use App\Support\IntegrationGuideBuilder;
use App\Support\TenantPlanCatalog;
use App\Support\Url;

final class AdminWeb
{
    /** @param array<string,string> $routeParams */
    public static function loginPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (self::isLoggedIn()) {
            Response::redirect(self::u('/admin'));
            return;
        }

        $error = (string) ($request->query['error'] ?? '');
        $body = '
            <div class="card auth-card">
                <h1>L-CMD License Server</h1>
                <p>Sign in with the admin token from .env</p>
                ' . ($error !== '' ? '<div class="alert">Invalid token or too many attempts</div>' : '') . '
                <form method="post" action="' . self::e(self::u('/admin/login')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Admin Token</label>
                    <input type="password" name="token" required autocomplete="current-password">
                    <button type="submit">Sign In</button>
                </form>
            </div>
        ';

        Response::html(self::layout('Admin Login', $body));
    }

    /** @param array<string,string> $routeParams */
    public static function loginSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);

        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/login') . '?error=1');
            return;
        }

        // Tight brute-force guard for login endpoint.
        $limiter = new RateLimiter();
        if (!$limiter->allow($request->ip, '/admin/login', 10)) {
            Response::redirect(self::u('/admin/login') . '?error=1');
            return;
        }

        $token = trim((string) ($request->post['token'] ?? ''));
        $auth = new AuthService();
        if ($auth->validateAdminToken($token) === null) {
            Response::redirect(self::u('/admin/login') . '?error=1');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_at'] = time();

        Response::redirect(self::u('/admin'));
    }

    /** @param array<string,string> $routeParams */
    public static function logout(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin'));
            return;
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        Response::redirect(self::u('/admin/login'));
    }

    /** @param array<string,string> $routeParams */
    public static function dashboard(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $repo = new LicenseRepository();
        $stats = $repo->adminStats();

        $overview = $stats['overview'] ?? [];
        $revenue = $stats['revenue'] ?? [];
        $activations = $stats['activations'] ?? [];
        $products = (new ProductRepository())->listAllWithTariffCounts();
        $apps = (new OperationsRepository())->appCards();
        $productSaved = (string) ($request->query['product_saved'] ?? '') === '1';

        $productRows = '';
        foreach ($products as $product) {
            $productRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/products/' . (string) $product['id'])) . '">' . self::e((string) $product['name']) . '</a></td>'
                . '<td>' . self::e((string) $product['slug']) . '</td>'
                . '<td>' . self::e((string) ((int) ($product['tariff_count'] ?? 0))) . '</td>'
                . '<td>' . self::e((string) ((int) ($product['active_tariff_count'] ?? 0))) . '</td>'
                . '<td>' . self::e((int) ($product['is_active'] ?? 0) === 1 ? 'Yes' : 'No') . '</td>'
                . '</tr>';
        }

        if ($productRows === '') {
            $productRows = '<tr><td colspan="5">No products yet</td></tr>';
        }

        $appRows = '';
        foreach ($apps as $app) {
            $appRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/apps/' . (string) $app['id'])) . '">' . self::e((string) $app['name']) . '</a></td>'
                . '<td>' . self::e((string) $app['slug']) . '</td>'
                . '<td>' . self::e((string) ((int) ($app['product_count'] ?? 0))) . '</td>'
                . '<td>' . self::e((string) ((int) ($app['license_count'] ?? 0))) . '</td>'
                . '<td>' . self::e((int) ($app['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>'
                . '</tr>';
        }
        if ($appRows === '') {
            $appRows = '<tr><td colspan="5">No applications yet</td></tr>';
        }

        $content = '
            <div class="grid">
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#ff9500"><span class="material-symbols-rounded">confirmation_number</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Total Licenses</div>
                            <div class="stat-value">' . self::e((string) ($overview['total_licenses'] ?? '0')) . '</div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">update</span> Updated now</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#6bd098"><span class="material-symbols-rounded">check_circle</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Active</div>
                            <div class="stat-value">' . self::e((string) ($overview['active_licenses'] ?? '0')) . '</div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">trending_up</span> Active licenses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#f5593d"><span class="material-symbols-rounded">timer_off</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Expired</div>
                            <div class="stat-value">' . self::e((string) ($overview['expired_licenses'] ?? '0')) . '</div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">schedule</span> Expired licenses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#9c1a1a"><span class="material-symbols-rounded">block</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Revoked</div>
                            <div class="stat-value">' . self::e((string) ($overview['revoked_licenses'] ?? '0')) . '</div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">gavel</span> Revoked licenses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#51bcda"><span class="material-symbols-rounded">payments</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Revenue This Month</div>
                            <div class="stat-value">' . self::e((string) ($revenue['this_month'] ?? '0')) . ' <small style="font-size:14px;font-weight:500">CZK</small></div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">calendar_month</span> Current month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="stat-icon" style="background:#9368e9"><span class="material-symbols-rounded">bolt</span></div>
                        <div class="stat-info">
                            <div class="stat-label">Activations 24h</div>
                            <div class="stat-value">' . self::e((string) ($activations['last_24h'] ?? '0')) . '</div>
                        </div>
                    </div>
                    <div class="stat-footer"><span class="material-symbols-rounded">schedule</span> Last 24 hours</div>
                </div>
            </div>
            ' . ($productSaved ? '<div class="success">Product created.</div>' : '') . '
            <div class="grid" style="align-items:start">
                <div class="card">
                    <div class="topbar">
                        <h3>Products For License Generation</h3>
                        <a href="' . self::e(self::u('/admin/products')) . '" class="btn-secondary">Open Full Product Admin</a>
                    </div>
                    <table>
                        <thead><tr><th>Name</th><th>Slug</th><th>Tariffs</th><th>Active Tariffs</th><th>Active</th></tr></thead>
                        <tbody>' . $productRows . '</tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Add Product</h3>
                    <form method="post" action="' . self::e(self::u('/admin/products')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <input type="hidden" name="redirect_to" value="/admin?product_saved=1">
                        <label>Slug</label>
                        <input type="text" name="slug" required pattern="[a-z0-9-]+" placeholder="e.g. plugin-pro">
                        <label>Name</label>
                        <input type="text" name="name" required>
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" value="0">
                        <button type="submit" style="margin-top:12px">Create Product</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="topbar">
                    <h3>Applications</h3>
                    <a href="' . self::e(self::u('/admin/apps')) . '" class="btn-secondary">Open Apps</a>
                </div>
                <table>
                    <thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Licenses</th><th>Status</th></tr></thead>
                    <tbody>' . $appRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('L-CMD License Server', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function aboutPage(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $content = '
            <div class="topbar">
                <h1>About This System</h1>
            </div>

            <div class="card">
                <h3>Production Topology</h3>
                <div class="info-grid">
                    <div class="info-panel">
                        <h4>Licensing Backend</h4>
                        <p><strong>URL:</strong> https://api.vase-domena.com</p>
                        <p><strong>Role:</strong> single production source of truth</p>
                        <ul class="about-list">
                            <li>PHP application with admin dashboard and public API</li>
                            <li>MariaDB database for licenses, activations, settings, logs and templates</li>
                            <li>Stripe webhooks, SMTP sending, maintenance jobs and queue processing</li>
                        </ul>
                    </div>
                    <div class="info-panel">
                        <h4>Public Web</h4>
                        <p><strong>URL:</strong> https://www.vase-domena.com</p>
                        <p><strong>Role:</strong> sales frontend and checkout entry point</p>
                        <ul class="about-list">
                            <li>Displays products and tariffs for a selected application</li>
                            <li>Creates checkout sessions through the licensing API</li>
                            <li>Does not host a second copy of the licensing backend</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3>What Runs Where</h3>
                <table>
                    <thead><tr><th>Component</th><th>Where It Runs</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td>Admin dashboard</td><td>VPS / `api.vase-domena.com`</td><td>`/admin/*`, protected by admin token login</td></tr>
                        <tr><td>Public API</td><td>VPS / `api.vase-domena.com`</td><td>app-aware products, checkout, activate, verify and deactivate</td></tr>
                        <tr><td>Stripe webhook processing</td><td>VPS / `api.vase-domena.com`</td><td>`POST /api/v1/webhook/stripe`</td></tr>
                        <tr><td>MariaDB</td><td>same VPS</td><td>single production database</td></tr>
                        <tr><td>SMTP + email templates</td><td>same VPS</td><td>configured in `.env` and editable via admin settings</td></tr>
                        <tr><td>Maintenance + queue jobs</td><td>same VPS</td><td>cron runs `scripts/maintenance.php` and `scripts/queue_worker.php`</td></tr>
                        <tr><td>Public sales frontend</td><td>`www.vase-domena.com` hosting</td><td>frontend only, not the licensing backend</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Installation Requirements</h3>
                <div class="info-grid">
                    <div class="info-panel">
                        <h4>Server</h4>
                        <ul class="about-list">
                            <li>Ubuntu 22.04 or 24.04 LTS recommended</li>
                            <li>Apache 2 with `rewrite`, `ssl`, `headers`</li>
                            <li>PHP 8.1+ with `mysql`, `curl`, `json`, `mbstring`, `xml`, `openssl`</li>
                            <li>MariaDB, Composer and Git</li>
                            <li>Open ports 22, 80 and 443 only</li>
                        </ul>
                    </div>
                    <div class="info-panel">
                        <h4>Project Layout</h4>
                        <ul class="about-list">
                            <li>Deploy app into `/var/www/license-server` or your preferred VPS path</li>
                            <li>Web server `DocumentRoot` must point to `/var/www/license-server/public`</li>
                            <li>Environment file lives in the project root as `.env`</li>
                            <li>Database schema source: `database/schema.sql`</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3>Configuration Checklist</h3>
                <ol class="about-steps">
                    <li>Clone or upload this codebase to `/var/www/license-server` and run `composer install --no-dev --optimize-autoloader`.</li>
                    <li>Create `.env` with `APP_ENV`, `APP_URL`, `APP_KEY`, `LICENSE_SALT`, DB credentials, Stripe keys and `ADMIN_BEARER_TOKEN`.</li>
                    <li>Import `database/schema.sql` into the production MariaDB database.</li>
                    <li>Create a virtual host for `api.vase-domena.com` with document root set to `public/` and issue a valid SSL certificate.</li>
                    <li>Configure cron for hourly maintenance and 5-minute queue processing.</li>
                    <li>Validate `https://api.vase-domena.com/health`, admin login and key app-aware API endpoints after each deploy.</li>
                </ol>
            </div>

            <div class="card">
                <h3>Important Environment Variables</h3>
                <table>
                    <thead><tr><th>Variable</th><th>Purpose</th></tr></thead>
                    <tbody>
                        <tr><td>`APP_ENV`, `APP_URL`, `APP_BASE_PATH`</td><td>runtime mode and base URL; production usually uses empty `APP_BASE_PATH`</td></tr>
                        <tr><td>`APP_KEY`, `LICENSE_SALT`</td><td>JWT signing and license key generation secrets</td></tr>
                        <tr><td>`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`</td><td>MariaDB connection</td></tr>
                        <tr><td>`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`</td><td>checkout and webhook integration</td></tr>
                        <tr><td>`ADMIN_BEARER_TOKEN`</td><td>admin login token for web and admin API</td></tr>
                        <tr><td>`SMTP_*`</td><td>default outbound email settings, later manageable from admin</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Operational Rules</h3>
                <ul class="about-list">
                    <li>There is only one production deploy target for the licensing system: your primary licensing endpoint such as `api.vase-domena.com`.</li>
                    <li>Do not maintain multiple active PHP copies of the licensing system on different hosts.</li>
                    <li>All web and app integrations should target the current app-aware API routes.</li>
                    <li>SMTP settings and email templates can be changed in this admin dashboard under Settings.</li>
                </ul>
                <p class="doc-links">This page mirrors the current project README and deployment notes into one operator-facing summary inside the dashboard.</p>
            </div>
        ';

        Response::html(self::layout('About', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function licenses(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 50)));
        $search = trim((string) ($request->query['search'] ?? ''));
        $status = trim((string) ($request->query['status'] ?? ''));
        $type = trim((string) ($request->query['type'] ?? ''));

        $repo = new LicenseRepository();
        $rows = $repo->listForAdmin([
            'search' => $search !== '' ? $search : null,
            'status' => $status !== '' ? $status : null,
            'type' => $type !== '' ? $type : null,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/licenses/' . rawurlencode((string) $row['license_key']))) . '">' . self::e((string) $row['license_key']) . '</a></td>'
                . '<td>' . self::e((string) ($row['app_slug'] ?? '')) . '</td>'
                . '<td>' . self::e((string) $row['license_type']) . '</td>'
                . '<td>' . self::e((string) $row['customer_email']) . '</td>'
                . '<td>' . self::e((string) ($row['customer_name'] ?? '')) . '</td>'
                . '<td>' . self::e((string) $row['status']) . '</td>'
                . '<td>' . self::e((string) $row['current_activations']) . '/' . self::e((string) $row['max_activations']) . '</td>'
                . '</tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="7">No records</td></tr>';
        }

        $prevPage = max(1, $page - 1);
        $nextPage = $page + 1;
        $base = self::u('/admin/licenses') . '?search=' . rawurlencode($search) . '&status=' . rawurlencode($status) . '&type=' . rawurlencode($type) . '&per_page=' . $perPage;

        $content = '
            <div class="topbar">
                <h1>Licenses</h1>
            </div>
            <div class="card">
                <form method="get" action="' . self::e(self::u('/admin/licenses')) . '" class="filters">
                    <input type="text" name="search" placeholder="Search" value="' . self::e($search) . '">
                    <select name="status">
                        <option value="">All statuses</option>
                        ' . self::statusOptions($status) . '
                    </select>
                    <select name="type">
                        <option value="">All types</option>
                        ' . self::typeOptions($type) . '
                    </select>
                    <input type="hidden" name="per_page" value="' . $perPage . '">
                    <button type="submit">Filter</button>
                </form>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr><th>Key</th><th>App</th><th>Type</th><th>Email</th><th>Name</th><th>Status</th><th>Activations</th></tr>
                    </thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
                <div class="pager">
                    <a class="btn-secondary" href="' . self::e($base . '&page=' . $prevPage) . '">Prev</a>
                    <span>Page ' . $page . '</span>
                    <a class="btn-secondary" href="' . self::e($base . '&page=' . $nextPage) . '">Next</a>
                </div>
            </div>
        ';

        Response::html(self::layout('Licenses', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function licenseDetail(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        $key = (string) ($routeParams['key'] ?? '');
        $repo = new LicenseRepository();
        $detail = $repo->detailForAdmin($key);
        if (!$detail) {
            Response::html(self::layout('Not Found', '<div class="card"><h1>License not found</h1><a href="' . self::e(self::u('/admin/licenses')) . '">Back</a></div>'), 404);
            return;
        }

        $license = $detail['license'];
        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Saved</div>' : '';

        $activationsRows = '';
        foreach (($detail['activations'] ?? []) as $act) {
            $activationsRows .= '<tr>'
                . '<td>' . self::e((string) ($act['domain'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($act['activated_at'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($act['last_ping_at'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($act['php_version'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($act['cms_version'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($activationsRows === '') {
            $activationsRows = '<tr><td colspan="5">No activations</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>License Detail</h1>
                <a href="' . self::e(self::u('/admin/licenses')) . '" class="btn-secondary">Back</a>
            </div>
            <div class="card">' . $message . '
                <p><strong>Key:</strong> ' . self::e((string) $license['license_key']) . '</p>
                <p><strong>App:</strong> ' . self::e((string) ($license['app_name'] ?? $license['app_slug'] ?? '')) . '</p>
                <p><strong>Email:</strong> ' . self::e((string) $license['customer_email']) . '</p>
                <p><strong>Name:</strong> ' . self::e((string) ($license['customer_name'] ?? '')) . '</p>
                <p><strong>Type:</strong> ' . self::e((string) $license['license_type']) . '</p>
                <p><strong>Product:</strong> ' . self::e((string) ($license['product_name'] ?? $license['product_slug'] ?? '')) . '</p>
                <p><strong>Verifications (30 days):</strong> ' . self::e((string) ($detail['verifications_last_30_days'] ?? 0)) . '</p>
            </div>
            <div class="card">
                <h3>Update License</h3>
                <form method="post" action="' . self::e(self::u('/admin/licenses/' . rawurlencode((string) $license['license_key']))) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Status</label>
                    <select name="status">' . self::statusOptions((string) ($license['status'] ?? 'pending')) . '</select>
                    <label>Expires At (YYYY-MM-DD HH:MM:SS)</label>
                    <input type="text" name="expires_at" value="' . self::e((string) ($license['expires_at'] ?? '')) . '">
                    <label>Revoke Reason</label>
                    <textarea name="revoke_reason" rows="3">' . self::e((string) ($license['revoke_reason'] ?? '')) . '</textarea>
                    <button type="submit">Save</button>
                </form>
            </div>
            <div class="card">
                <h3>Activations</h3>
                <table>
                    <thead><tr><th>Domain</th><th>Activated</th><th>Last Ping</th><th>PHP</th><th>CMS</th></tr></thead>
                    <tbody>' . $activationsRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('License Detail', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function licenseUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/licenses'));
            return;
        }

        $key = (string) ($routeParams['key'] ?? '');
        $allowed = ['status', 'expires_at', 'revoke_reason'];
        $changes = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $request->post)) {
                $value = trim((string) $request->post[$field]);
                $changes[$field] = $value === '' ? null : $value;
            }
        }

        if (isset($changes['status']) && !in_array($changes['status'], ['pending', 'active', 'expired', 'revoked', 'refunded'], true)) {
            Response::redirect(self::u('/admin/licenses/' . rawurlencode($key)));
            return;
        }

        $repo = new LicenseRepository();
        $current = $repo->findByKey($key);
        if (!$current) {
            Response::redirect(self::u('/admin/licenses'));
            return;
        }

        $repo->updateByKey($key, $changes);
        Response::redirect(self::u('/admin/licenses/' . rawurlencode($key)) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function productsPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $repo = new ProductRepository();
        $products = $repo->listAll();

        $tableRows = '';
        foreach ($products as $p) {
            $tableRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/products/' . $p['id'])) . '">' . self::e((string) $p['name']) . '</a></td>'
                . '<td>' . self::e((string) $p['slug']) . '</td>'
                . '<td>' . self::e($p['is_active'] ? 'Yes' : 'No') . '</td>'
                . '<td>' . self::e((string) $p['sort_order']) . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No products</td></tr>';
        }

        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Saved</div>' : '';

        $content = '
            <div class="topbar">
                <h1>Products</h1>
            </div>
            ' . $message . '
            <div class="card">
                <table>
                    <thead><tr><th>Name</th><th>Slug</th><th>Active</th><th>Sort</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
            <div class="card">
                <h3>Add Product</h3>
                <form method="post" action="' . self::e(self::u('/admin/products')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Slug</label>
                    <input type="text" name="slug" required pattern="[a-z0-9-]+" placeholder="e.g. plugin-pro">
                    <label>Name</label>
                    <input type="text" name="name" required>
                    <label>Description</label>
                    <textarea name="description" rows="2"></textarea>
                    <button type="submit" style="margin-top:12px">Add</button>
                </form>
            </div>
        ';

        Response::html(self::layout('Products', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function productsCreate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/products'));
            return;
        }

        $slug = trim((string) ($request->post['slug'] ?? ''));
        $name = trim((string) ($request->post['name'] ?? ''));
        $description = trim((string) ($request->post['description'] ?? ''));
        $sortOrder = (int) ($request->post['sort_order'] ?? 0);
        $redirectTo = self::safeAdminRedirectPath((string) ($request->post['redirect_to'] ?? ''));

        if ($slug === '' || $name === '') {
            Response::redirect(self::u($redirectTo ?: '/admin/products'));
            return;
        }

        $repo = new ProductRepository();
        $repo->create($slug, $name, $description !== '' ? $description : null, $sortOrder);

        if ($redirectTo !== '') {
            Response::redirect(self::u($redirectTo));
            return;
        }

        Response::redirect(self::u('/admin/products') . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function productDetail(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $repo = new ProductRepository();
        $product = $repo->findById($id);
        if (!$product) {
            Response::html(self::layout('Not Found', '<div class="card"><h1>Product not found</h1></div>'), 404);
            return;
        }

        $tariffs = $repo->getTariffs($id);
        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Saved</div>' : '';

        $tariffRows = '';
        foreach ($tariffs as $t) {
            $tariffRows .= '<tr>'
                . '<td>' . self::e((string) $t['license_type']) . '</td>'
                . '<td>' . self::e((string) $t['stripe_price_id']) . '</td>'
                . '<td>' . self::e((string) $t['price']) . ' ' . self::e((string) $t['currency']) . '</td>'
                . '<td>' . self::e($t['is_recommended'] ? 'Yes' : 'No') . '</td>'
                . '<td>' . self::e(!empty($t['is_default']) ? 'Yes' : 'No') . '</td>'
                . '<td>' . self::e($t['is_active'] ? 'Yes' : 'No') . '</td>'
                . '</tr>';
        }
        if ($tariffRows === '') {
            $tariffRows = '<tr><td colspan="6">No tariffs</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>Product: ' . self::e((string) $product['name']) . '</h1>
                <a href="' . self::e(self::u('/admin/products')) . '" class="btn-secondary">Back</a>
            </div>
            ' . $message . '
            <div class="card">
                <h3>Edit Product</h3>
                <form method="post" action="' . self::e(self::u('/admin/products/' . $id)) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="_action" value="update_product">
                    <label>Name</label>
                    <input type="text" name="name" value="' . self::e((string) $product['name']) . '" required>
                    <label>Description</label>
                    <textarea name="description" rows="2">' . self::e((string) ($product['description'] ?? '')) . '</textarea>
                    <label>Active</label>
                    <select name="is_active">
                        <option value="1"' . ($product['is_active'] ? ' selected' : '') . '>Yes</option>
                        <option value="0"' . (!$product['is_active'] ? ' selected' : '') . '>No</option>
                    </select>
                    <button type="submit" style="margin-top:12px">Save</button>
                </form>
            </div>
            <div class="card">
                <h3>Tariffs</h3>
                <table>
                    <thead><tr><th>Type</th><th>Stripe Price ID</th><th>Price</th><th>Recommended</th><th>Default</th><th>Active</th></tr></thead>
                    <tbody>' . $tariffRows . '</tbody>
                </table>
            </div>
            <div class="card">
                <h3>Add Tariff</h3>
                <form method="post" action="' . self::e(self::u('/admin/products/' . $id)) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="_action" value="add_tariff">
                    <label>License Type</label>
                    <select name="license_type">
                        <option value="monthly">monthly</option>
                        <option value="annual">annual</option>
                        <option value="lifetime">lifetime</option>
                    </select>
                    <label>Stripe Price ID</label>
                    <input type="text" name="stripe_price_id" required placeholder="price_...">
                    <label>Purchase Kind</label>
                    <select name="purchase_kind">
                        <option value="license">license</option>
                        <option value="tenant_plan">tenant_plan</option>
                    </select>
                    <label>Target Plan Key</label>
                    <select name="target_plan_key">
                        <option value="">-</option>
                        <option value="starter">starter</option>
                        <option value="standard">standard</option>
                        <option value="business">business</option>
                    </select>
                    <label>Price</label>
                    <input type="number" name="price" step="0.01" required>
                    <label>Currency</label>
                    <select name="currency">' . self::renderCurrencyOptions('EUR') . '</select>
                    <label>Recommended</label>
                    <select name="is_recommended">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                    <label>Default</label>
                    <select name="is_default">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                    <button type="submit" style="margin-top:12px">Add Tariff</button>
                </form>
            </div>
        ';

        Response::html(self::layout('Product Detail', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function productUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/products'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $repo = new ProductRepository();
        $action = (string) ($request->post['_action'] ?? '');

        if ($action === 'update_product') {
            $repo->update($id, [
                'name' => trim((string) ($request->post['name'] ?? '')),
                'description' => trim((string) ($request->post['description'] ?? '')) ?: null,
                'is_active' => (int) ($request->post['is_active'] ?? 1),
            ]);
        } elseif ($action === 'add_tariff') {
            $repo->createTariff(
                $id,
                (string) ($request->post['license_type'] ?? 'monthly'),
                trim((string) ($request->post['stripe_price_id'] ?? '')),
                (string) ($request->post['purchase_kind'] ?? 'license'),
                trim((string) ($request->post['target_plan_key'] ?? '')) ?: null,
                (float) ($request->post['price'] ?? 0),
                CurrencyCatalog::normalize((string) ($request->post['currency'] ?? 'EUR')) ?? 'EUR',
                (bool) ($request->post['is_recommended'] ?? false),
                (bool) ($request->post['is_default'] ?? true)
            );
        }

        Response::redirect(self::u('/admin/products/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appsPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $apps = (new AppRepository())->listWithMetrics();
        $tableRows = '';
        foreach ($apps as $app) {
            $tableRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/apps/' . (string) $app['id'])) . '">' . self::e((string) $app['name']) . '</a></td>'
                . '<td>' . self::e((string) $app['slug']) . '</td>'
                . '<td>' . self::e((string) ($app['platform_type'] ?? '')) . '</td>'
                . '<td>' . self::e((int) ($app['product_count'] ?? 0) === 1 ? '1 product' : (string) ((int) ($app['product_count'] ?? 0)) . ' products') . '</td>'
                . '<td>' . self::e((int) ($app['key_count'] ?? 0) === 1 ? '1 key' : (string) ((int) ($app['key_count'] ?? 0)) . ' keys') . '</td>'
                . '<td>' . self::e((int) ($app['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>'
                . '</tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="6">No applications yet</td></tr>';
        }

        $message = [];
        if ((string) ($request->query['saved'] ?? '') === '1') {
            $message[] = '<div class="success">Application created.</div>';
        }
        $flashError = self::consumeFlash('app_form_error');
        if (is_array($flashError) && !empty($flashError['message'])) {
            $message[] = '<div class="alert">' . self::e((string) $flashError['message']) . '</div>';
        }

        $content = '
            <div class="topbar">
                <h1>Apps</h1>
            </div>
            ' . implode('', $message) . '
            <div class="card">
                <table>
                    <thead><tr><th>Name</th><th>Slug</th><th>Platform</th><th>Products</th><th>Keys</th><th>Status</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
            <div class="card">
                <h3>Create Application</h3>
                <form method="post" action="' . self::e(self::u('/admin/apps')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Name</label>
                    <input type="text" name="name" required>
                    <label>Slug</label>
                    <input type="text" name="slug" required pattern="[a-z0-9-]+" placeholder="e.g. loftp">
                    <label>Platform Type</label>
                    <input type="text" name="platform_type" placeholder="desktop, web, mobile">
                    <label>Base URL</label>
                    <input type="url" name="base_url" placeholder="https://app.example.invalid">
                    <label>Public Website URL</label>
                    <input type="url" name="public_base_url" placeholder="https://app.example.invalid">
                    <label>Checkout Success URL</label>
                    <input type="url" name="checkout_success_url" placeholder="https://app.example.invalid/checkout/success?session_id={CHECKOUT_SESSION_ID}">
                    <label>Checkout Cancel URL</label>
                    <input type="url" name="checkout_cancel_url" placeholder="https://app.example.invalid/checkout/cancel">
                    <label>Support Email</label>
                    <input type="email" name="support_email">
                    <label>Notification Email</label>
                    <input type="email" name="notification_email">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                    <label>Active</label>
                    <select name="is_active">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                    <button type="submit" style="margin-top:12px">Create Application</button>
                </form>
            </div>
        ';

        Response::html(self::layout('Apps', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function appCreate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $slug = trim((string) ($request->post['slug'] ?? ''));
        $name = trim((string) ($request->post['name'] ?? ''));
        if ($slug === '' || $name === '') {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $checkoutConfig = AppCheckoutUrlConfig::normalize(
            (string) ($request->post['public_base_url'] ?? ''),
            (string) ($request->post['checkout_success_url'] ?? ''),
            (string) ($request->post['checkout_cancel_url'] ?? '')
        );
        $checkoutError = AppCheckoutUrlConfig::validate(
            $checkoutConfig['checkout_success_url'],
            $checkoutConfig['checkout_cancel_url']
        );
        if ($checkoutError !== null) {
            self::setFlash('app_form_error', ['message' => $checkoutError]);
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $appId = (new AppRepository())->create([
            'slug' => $slug,
            'name' => $name,
            'description' => trim((string) ($request->post['description'] ?? '')) ?: null,
            'platform_type' => trim((string) ($request->post['platform_type'] ?? '')) ?: null,
            'base_url' => trim((string) ($request->post['base_url'] ?? '')) ?: null,
            'public_base_url' => $checkoutConfig['public_base_url'],
            'checkout_success_url' => $checkoutConfig['checkout_success_url'],
            'checkout_cancel_url' => $checkoutConfig['checkout_cancel_url'],
            'support_email' => trim((string) ($request->post['support_email'] ?? '')) ?: null,
            'notification_email' => trim((string) ($request->post['notification_email'] ?? '')) ?: null,
            'is_active' => (int) ($request->post['is_active'] ?? 1) === 1,
        ]);

        Response::redirect(self::u('/admin/apps/' . $appId) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appDetail(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $repo = new AppRepository();
        $app = $repo->findById($id);
        if (!$app) {
            Response::html(self::layout('Not Found', '<div class="card"><h1>Application not found</h1></div>'), 404);
            return;
        }

        $keys = (new AppKeyRepository())->listForApp($id);
        $notificationProfile = (new AppNotificationRepository())->profileForApp($id) ?? [
            'is_enabled' => 1,
            'sender_name' => '',
            'reply_to_email' => '',
            'sales_email' => '',
            'ops_email' => '',
            'support_url' => '',
            'brand_color' => '#51cbce',
        ];
        $emailOverrides = (new AppNotificationRepository())->templateOverridesForApp($id);
        $emailOverrideMap = [];
        foreach ($emailOverrides as $override) {
            $emailOverrideMap[(string) $override['template_key']] = $override;
        }
        $boundProducts = (new AppProductRepository())->listForApp($id);
        $boundProductIds = array_map(static fn (array $row): int => (int) $row['id'], $boundProducts);
        $availableProducts = (new ProductRepository())->listUnboundForApp($boundProductIds);
        $flashKey = self::consumeFlash('generated_app_key');
        $message = [];
        if ((string) ($request->query['saved'] ?? '') === '1') {
            $message[] = '<div class="success">Application saved.</div>';
        }
        $flashError = self::consumeFlash('app_form_error');
        if (is_array($flashError) && !empty($flashError['message'])) {
            $message[] = '<div class="alert">' . self::e((string) $flashError['message']) . '</div>';
        }
        if (is_array($flashKey)) {
            $value = isset($flashKey['secret']) ? (string) $flashKey['secret'] : (string) ($flashKey['public_key'] ?? '');
            if ($value !== '') {
                $message[] = '<div class="success"><strong>Generated key:</strong> <code style="font-size:13px">' . self::e($value) . '</code><br><small>Copy it now. Secret values are only shown once.</small></div>';
            }
        }

        $keyRows = '';
        foreach ($keys as $key) {
            $displayValue = (string) $key['key_id'];
            if ((string) $key['key_type'] === 'client_public' && !empty($key['public_key'])) {
                $displayValue = (string) $key['public_key'];
            } elseif (!empty($key['last4'])) {
                $displayValue .= ' ••••' . (string) $key['last4'];
            }

            $keyRows .= '<tr>'
                . '<td>' . self::e((string) $key['key_name']) . '</td>'
                . '<td>' . self::e((string) $key['key_type']) . '</td>'
                . '<td style="font-size:12px">' . self::e($displayValue) . '</td>'
                . '<td>' . self::e((int) ($key['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>'
                . '<td>' . self::e((string) ($key['created_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($keyRows === '') {
            $keyRows = '<tr><td colspan="5">No keys yet</td></tr>';
        }

        $productRows = '';
        foreach ($boundProducts as $product) {
            $productRows .= '<tr>'
                . '<td>' . self::e((string) $product['name']) . '</td>'
                . '<td>' . self::e((string) $product['slug']) . '</td>'
                . '<td>' . self::e((int) ($product['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive') . '</td>'
                . '<td>' . self::e((int) ($product['binding_active'] ?? 0) === 1 ? 'Bound' : 'Disabled') . '</td>'
                . '</tr>';
        }
        if ($productRows === '') {
            $productRows = '<tr><td colspan="4">No products assigned</td></tr>';
        }

        $productOptions = '';
        foreach ($availableProducts as $product) {
            $productOptions .= '<option value="' . self::e((string) $product['id']) . '">' . self::e((string) $product['name']) . ' (' . self::e((string) $product['slug']) . ')</option>';
        }
        if ($productOptions === '') {
            $productOptions = '<option value="">No unassigned products available</option>';
        }

        $templateDefinitions = [
            'mail_license' => [
                'label' => 'License Created',
                'subject_placeholder' => 'Vas licencni klic - {{type}}',
                'body_placeholder' => '<p>Dobry den {{name}}, zde je vas licencni klic: {{key}}</p>',
            ],
            'expiring_soon' => [
                'label' => 'Expiring Soon',
                'subject_placeholder' => 'Licence brzy vyprsi - {product_name}',
                'body_placeholder' => 'Vase licence {license_key} vyprsi dne {expires_at}.',
            ],
            'expired' => [
                'label' => 'Expired',
                'subject_placeholder' => 'Licence vyprsela - {product_name}',
                'body_placeholder' => 'Vase licence {license_key} je nyni expirovana.',
            ],
            'renewal_success' => [
                'label' => 'Renewal Success',
                'subject_placeholder' => 'Obnova licence probehla uspesne',
                'body_placeholder' => 'Platnost licence {license_key} byla prodlouzena do {expires_at}.',
            ],
        ];
        $templateCards = '';
        foreach ($templateDefinitions as $templateKey => $definition) {
            $override = $emailOverrideMap[$templateKey] ?? [
                'is_enabled' => 0,
                'subject' => '',
                'body' => '',
            ];
            $templateCards .= '
                <div class="card">
                    <h3>' . self::e($definition['label']) . '</h3>
                    <form method="post" action="' . self::e(self::u('/admin/apps/' . $id . '/email-templates')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <input type="hidden" name="template_key" value="' . self::e($templateKey) . '">
                        <label>Override Enabled</label>
                        <select name="is_enabled">
                            <option value="1"' . ((int) ($override['is_enabled'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . ((int) ($override['is_enabled'] ?? 0) === 0 ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Subject Override</label>
                        <input type="text" name="subject" value="' . self::e((string) ($override['subject'] ?? '')) . '" placeholder="' . self::e($definition['subject_placeholder']) . '">
                        <label>Body Override</label>
                        <textarea name="body" rows="5" placeholder="' . self::e($definition['body_placeholder']) . '">' . self::e((string) ($override['body'] ?? '')) . '</textarea>
                        <button type="submit" style="margin-top:12px">Save Template Override</button>
                    </form>
                </div>
            ';
        }

        $integrationGuide = self::integrationGuide((string) $app['slug'], (string) ($app['platform_type'] ?? ''));

        $content = '
            <div class="topbar">
                <h1>App: ' . self::e((string) $app['name']) . '</h1>
                <a href="' . self::e(self::u('/admin/apps')) . '" class="btn-secondary">Back</a>
            </div>
            ' . implode('', $message) . '
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>App Settings</h3>
                    <form method="post" action="' . self::e(self::u('/admin/apps/' . $id)) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Name</label>
                        <input type="text" name="name" value="' . self::e((string) $app['name']) . '" required>
                        <label>Slug</label>
                        <input type="text" name="slug" value="' . self::e((string) $app['slug']) . '" required pattern="[a-z0-9-]+">
                        <label>Platform Type</label>
                        <input type="text" name="platform_type" value="' . self::e((string) ($app['platform_type'] ?? '')) . '" placeholder="desktop, web, mobile, unity, unreal">
                        <label>Base URL</label>
                        <input type="url" name="base_url" value="' . self::e((string) ($app['base_url'] ?? '')) . '">
                        <label>Public Website URL</label>
                        <input type="url" name="public_base_url" value="' . self::e((string) ($app['public_base_url'] ?? '')) . '" placeholder="https://app.example.invalid">
                        <label>Checkout Success URL</label>
                        <input type="url" name="checkout_success_url" value="' . self::e((string) ($app['checkout_success_url'] ?? '')) . '" placeholder="https://app.example.invalid/checkout/success?session_id={CHECKOUT_SESSION_ID}">
                        <label>Checkout Cancel URL</label>
                        <input type="url" name="checkout_cancel_url" value="' . self::e((string) ($app['checkout_cancel_url'] ?? '')) . '" placeholder="https://app.example.invalid/checkout/cancel">
                        <label>Support Email</label>
                        <input type="email" name="support_email" value="' . self::e((string) ($app['support_email'] ?? '')) . '">
                        <label>Notification Email</label>
                        <input type="email" name="notification_email" value="' . self::e((string) ($app['notification_email'] ?? '')) . '">
                        <label>Description</label>
                        <textarea name="description" rows="3">' . self::e((string) ($app['description'] ?? '')) . '</textarea>
                        <label>Active</label>
                        <select name="is_active">
                            <option value="1"' . ((int) ($app['is_active'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . ((int) ($app['is_active'] ?? 0) === 0 ? ' selected' : '') . '>No</option>
                        </select>
                        <button type="submit" style="margin-top:12px">Save App</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Create Key</h3>
                    <form method="post" action="' . self::e(self::u('/admin/apps/' . $id . '/keys')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Key Name</label>
                        <input type="text" name="key_name" required placeholder="e.g. Production Web">
                        <label>Key Type</label>
                        <select name="key_type">
                            <option value="client_public">Client Public Key</option>
                            <option value="server_secret">Server Secret Key</option>
                        </select>
                        <button type="submit" style="margin-top:12px">Generate Key</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Notification Profile</h3>
                    <form method="post" action="' . self::e(self::u('/admin/apps/' . $id . '/notifications')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Notifications Enabled</label>
                        <select name="is_enabled">
                            <option value="1"' . ((int) ($notificationProfile['is_enabled'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . ((int) ($notificationProfile['is_enabled'] ?? 0) === 0 ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Sender Name</label>
                        <input type="text" name="sender_name" value="' . self::e((string) ($notificationProfile['sender_name'] ?? '')) . '" placeholder="e.g. LoFTP Team">
                        <label>Reply-To Email</label>
                        <input type="email" name="reply_to_email" value="' . self::e((string) ($notificationProfile['reply_to_email'] ?? '')) . '">
                        <label>Sales Email</label>
                        <input type="email" name="sales_email" value="' . self::e((string) ($notificationProfile['sales_email'] ?? '')) . '">
                        <label>Ops Email</label>
                        <input type="email" name="ops_email" value="' . self::e((string) ($notificationProfile['ops_email'] ?? '')) . '">
                        <label>Support URL</label>
                        <input type="url" name="support_url" value="' . self::e((string) ($notificationProfile['support_url'] ?? '')) . '">
                        <label>Brand Color</label>
                        <input type="text" name="brand_color" value="' . self::e((string) ($notificationProfile['brand_color'] ?? '#51cbce')) . '" placeholder="#51cbce">
                        <button type="submit" style="margin-top:12px">Save Notification Profile</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <h3>Keys</h3>
                <table>
                    <thead><tr><th>Name</th><th>Type</th><th>Identifier</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>' . $keyRows . '</tbody>
                </table>
            </div>
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>Assigned Products</h3>
                    <table>
                        <thead><tr><th>Name</th><th>Slug</th><th>Product Active</th><th>Binding</th></tr></thead>
                        <tbody>' . $productRows . '</tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Assign Product</h3>
                    <form method="post" action="' . self::e(self::u('/admin/apps/' . $id . '/products')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Product</label>
                        <select name="product_id">' . $productOptions . '</select>
                        <button type="submit" style="margin-top:12px">Assign Product</button>
                    </form>
                </div>
            </div>
            <div class="grid" style="align-items:start">
                ' . $templateCards . '
            </div>
            <div class="card">
                <h3>Integration Guide</h3>
                ' . $integrationGuide . '
            </div>
        ';

        Response::html(self::layout('App Detail', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function appUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $checkoutConfig = AppCheckoutUrlConfig::normalize(
            (string) ($request->post['public_base_url'] ?? ''),
            (string) ($request->post['checkout_success_url'] ?? ''),
            (string) ($request->post['checkout_cancel_url'] ?? '')
        );
        $checkoutError = AppCheckoutUrlConfig::validate(
            $checkoutConfig['checkout_success_url'],
            $checkoutConfig['checkout_cancel_url']
        );
        if ($checkoutError !== null) {
            self::setFlash('app_form_error', ['message' => $checkoutError]);
            Response::redirect(self::u('/admin/apps/' . $id));
            return;
        }

        (new AppRepository())->update($id, [
            'name' => trim((string) ($request->post['name'] ?? '')),
            'slug' => trim((string) ($request->post['slug'] ?? '')),
            'platform_type' => trim((string) ($request->post['platform_type'] ?? '')) ?: null,
            'base_url' => trim((string) ($request->post['base_url'] ?? '')) ?: null,
            'public_base_url' => $checkoutConfig['public_base_url'],
            'checkout_success_url' => $checkoutConfig['checkout_success_url'],
            'checkout_cancel_url' => $checkoutConfig['checkout_cancel_url'],
            'support_email' => trim((string) ($request->post['support_email'] ?? '')) ?: null,
            'notification_email' => trim((string) ($request->post['notification_email'] ?? '')) ?: null,
            'description' => trim((string) ($request->post['description'] ?? '')) ?: null,
            'is_active' => (int) ($request->post['is_active'] ?? 1),
        ]);

        Response::redirect(self::u('/admin/apps/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appKeyCreate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $keyName = trim((string) ($request->post['key_name'] ?? ''));
        $keyType = trim((string) ($request->post['key_type'] ?? 'client_public'));
        if ($id <= 0 || $keyName === '') {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $service = new AppKeyService();
        $generated = $keyType === 'server_secret'
            ? $service->createServerSecret($id, $keyName)
            : $service->createClientKey($id, $keyName);

        self::setFlash('generated_app_key', $generated);
        Response::redirect(self::u('/admin/apps/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appProductAssign(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $productId = (int) ($request->post['product_id'] ?? 0);
        if ($id <= 0 || $productId <= 0) {
            Response::redirect(self::u('/admin/apps/' . $id));
            return;
        }

        (new AppProductService())->sync(
            $id,
            array_merge((new AppProductRepository())->listProductIdsForApp($id), [$productId])
        );

        Response::redirect(self::u('/admin/apps/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appNotificationsUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        if ($id <= 0) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        (new AppNotificationRepository())->upsertProfile($id, [
            'is_enabled' => (int) ($request->post['is_enabled'] ?? 1) === 1,
            'sender_name' => trim((string) ($request->post['sender_name'] ?? '')) ?: null,
            'reply_to_email' => trim((string) ($request->post['reply_to_email'] ?? '')) ?: null,
            'sales_email' => trim((string) ($request->post['sales_email'] ?? '')) ?: null,
            'ops_email' => trim((string) ($request->post['ops_email'] ?? '')) ?: null,
            'support_url' => trim((string) ($request->post['support_url'] ?? '')) ?: null,
            'brand_color' => trim((string) ($request->post['brand_color'] ?? '')) ?: null,
        ]);

        Response::redirect(self::u('/admin/apps/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function appEmailTemplatesUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        $id = (int) ($routeParams['id'] ?? 0);
        $templateKey = trim((string) ($request->post['template_key'] ?? ''));
        if ($id <= 0 || $templateKey === '') {
            Response::redirect(self::u('/admin/apps'));
            return;
        }

        (new AppNotificationRepository())->upsertTemplateOverride(
            $id,
            $templateKey,
            trim((string) ($request->post['subject'] ?? '')) ?: null,
            trim((string) ($request->post['body'] ?? '')) ?: null,
            (int) ($request->post['is_enabled'] ?? 0) === 1
        );

        Response::redirect(self::u('/admin/apps/' . $id) . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function operationsPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $appId = (int) ($request->query['app_id'] ?? 0);
        $selectedAppId = $appId > 0 ? $appId : null;
        $ops = new OperationsRepository();
        $apps = (new AppRepository())->listAll();
        $overview = $ops->appOverview($selectedAppId);
        $verifications = $ops->verificationSummary($selectedAppId);
        $activations = $ops->activationSummary($selectedAppId);
        $health = $ops->healthSummary();
        $healthChecks = $ops->recentHealthChecks(12);

        $appOptions = '<option value="">All apps</option>';
        foreach ($apps as $app) {
            $selected = $selectedAppId === (int) $app['id'] ? ' selected' : '';
            $appOptions .= '<option value="' . self::e((string) $app['id']) . '"' . $selected . '>' . self::e((string) $app['name']) . '</option>';
        }

        $healthRows = '';
        foreach ($healthChecks as $check) {
            $healthRows .= '<tr>'
                . '<td>' . self::e((string) $check['check_type']) . '</td>'
                . '<td>' . self::e((string) $check['status']) . '</td>'
                . '<td style="font-size:12px">' . self::e((string) ($check['details_json'] ?? '')) . '</td>'
                . '<td>' . self::e((string) $check['checked_at']) . '</td>'
                . '</tr>';
        }
        if ($healthRows === '') {
            $healthRows = '<tr><td colspan="4">No health checks recorded yet</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>Operations</h1>
            </div>
            <div class="card">
                <form method="get" action="' . self::e(self::u('/admin/operations')) . '" class="filters" style="grid-template-columns:2fr auto">
                    <select name="app_id">' . $appOptions . '</select>
                    <button type="submit">Filter</button>
                </form>
            </div>
            <div class="grid">
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Licenses</div><div class="stat-value">' . self::e((string) ($overview['total_licenses'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Active Licenses</div><div class="stat-value">' . self::e((string) ($overview['active_licenses'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Verifications 24h</div><div class="stat-value">' . self::e((string) ($verifications['last_24h'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Activations 7d</div><div class="stat-value">' . self::e((string) ($activations['last_7d'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Pending Jobs</div><div class="stat-value">' . self::e((string) ($health['pending_jobs'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Failed Jobs 24h</div><div class="stat-value">' . self::e((string) ($health['failed_jobs_24h'] ?? 0)) . '</div></div></div></div>
                <div class="stat-card"><div class="stat-card-body"><div class="stat-info"><div class="stat-label">Webhook Failures 24h</div><div class="stat-value">' . self::e((string) ($health['webhook_failures_24h'] ?? 0)) . '</div></div></div></div>
            </div>
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>Verification Summary</h3>
                    <p>Total: <strong>' . self::e((string) ($verifications['total'] ?? 0)) . '</strong></p>
                    <p>Valid: <strong>' . self::e((string) ($verifications['valid_total'] ?? 0)) . '</strong></p>
                    <p>Last 7 days: <strong>' . self::e((string) ($verifications['last_7d'] ?? 0)) . '</strong></p>
                    <p>Last 30 days: <strong>' . self::e((string) ($verifications['last_30d'] ?? 0)) . '</strong></p>
                </div>
                <div class="card">
                    <h3>Activation Summary</h3>
                    <p>Total: <strong>' . self::e((string) ($activations['total'] ?? 0)) . '</strong></p>
                    <p>Active: <strong>' . self::e((string) ($activations['active_total'] ?? 0)) . '</strong></p>
                    <p>Last 24 hours: <strong>' . self::e((string) ($activations['last_24h'] ?? 0)) . '</strong></p>
                    <p>Last 7 days: <strong>' . self::e((string) ($activations['last_7d'] ?? 0)) . '</strong></p>
                </div>
            </div>
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>System Health</h3>
                    <p>Reserved jobs: <strong>' . self::e((string) ($health['reserved_jobs'] ?? 0)) . '</strong></p>
                    <p>Last audit log: <strong>' . self::e((string) ($health['last_audit_at'] ?? 'n/a')) . '</strong></p>
                    <p>Last health snapshot: <strong>' . self::e((string) ($health['last_health_at'] ?? 'n/a')) . '</strong></p>
                </div>
                <div class="card">
                    <h3>Recent Health Checks</h3>
                    <table>
                        <thead><tr><th>Check</th><th>Status</th><th>Details</th><th>Checked</th></tr></thead>
                        <tbody>' . $healthRows . '</tbody>
                    </table>
                </div>
            </div>
        ';

        Response::html(self::layout('Operations', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function policiesPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $repo = new PolicyRepository();
        $apps = (new AppRepository())->listAll();
        $systemRows = [];
        foreach ($repo->listSystemPolicies() as $row) {
            $systemRows[(string) $row['policy_key']] = $row;
        }
        $appRows = [];
        foreach ($repo->listAppPolicies() as $row) {
            $appRows[(int) $row['app_id'] . ':' . (string) $row['policy_key']] = $row;
        }

        $policyDefinitions = self::policyDefinitions();
        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Policy saved.</div>' : '';

        $systemForms = '';
        foreach ($policyDefinitions as $policyKey => $definition) {
            $policy = $systemRows[$policyKey] ?? ['is_enabled' => 0, 'policy_value' => ''];
            $systemForms .= self::renderPolicyFormCard(
                'System / ' . $definition['label'],
                self::u('/admin/policies/system'),
                [
                    'scope_id' => '',
                    'policy_key' => $policyKey,
                    'is_enabled' => (int) ($policy['is_enabled'] ?? 0),
                    'policy_value' => self::policyValueForForm($policy['policy_value'] ?? ''),
                    'description' => $definition['description'],
                    'placeholder' => $definition['placeholder'],
                ]
            );
        }

        $appSections = '';
        foreach ($apps as $app) {
            $forms = '';
            foreach ($policyDefinitions as $policyKey => $definition) {
                $mapKey = (int) $app['id'] . ':' . $policyKey;
                $policy = $appRows[$mapKey] ?? ['is_enabled' => 0, 'policy_value' => ''];
                $forms .= self::renderPolicyFormCard(
                    $definition['label'],
                    self::u('/admin/policies/app'),
                    [
                        'scope_id' => (string) $app['id'],
                        'policy_key' => $policyKey,
                        'is_enabled' => (int) ($policy['is_enabled'] ?? 0),
                        'policy_value' => self::policyValueForForm($policy['policy_value'] ?? ''),
                        'description' => $definition['description'],
                        'placeholder' => $definition['placeholder'],
                    ]
                );
            }

            $appSections .= '
                <div class="card">
                    <h2 style="margin-bottom:16px">' . self::e((string) $app['name']) . ' <span style="font-size:12px;color:var(--text-muted)">(' . self::e((string) $app['slug']) . ')</span></h2>
                    <div class="grid" style="align-items:start">' . $forms . '</div>
                </div>
            ';
        }

        $content = '
            <div class="topbar">
                <h1>Policies</h1>
            </div>
            ' . $message . '
            <div class="card">
                <h2>Global Policies</h2>
                <div class="grid" style="align-items:start">' . $systemForms . '</div>
            </div>
            ' . $appSections . '
        ';

        Response::html(self::layout('Policies', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function policySystemUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/policies'));
            return;
        }

        $policyKey = trim((string) ($request->post['policy_key'] ?? ''));
        if ($policyKey === '') {
            Response::redirect(self::u('/admin/policies'));
            return;
        }

        (new PolicyRepository())->upsertSystemPolicy(
            $policyKey,
            self::decodePolicyValue((string) ($request->post['policy_value'] ?? '')),
            (int) ($request->post['is_enabled'] ?? 0) === 1
        );

        Response::redirect(self::u('/admin/policies') . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function policyAppUpdate(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/policies'));
            return;
        }

        $appId = (int) ($request->post['scope_id'] ?? 0);
        $policyKey = trim((string) ($request->post['policy_key'] ?? ''));
        if ($appId <= 0 || $policyKey === '') {
            Response::redirect(self::u('/admin/policies'));
            return;
        }

        (new PolicyRepository())->upsertAppPolicy(
            $appId,
            $policyKey,
            self::decodePolicyValue((string) ($request->post['policy_value'] ?? '')),
            (int) ($request->post['is_enabled'] ?? 0) === 1
        );

        Response::redirect(self::u('/admin/policies') . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function webhooksPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $appId = (int) ($request->query['app_id'] ?? 0);
        $selectedAppId = $appId > 0 ? $appId : null;
        $ops = new OperationsRepository();
        $apps = (new AppRepository())->listAll();
        $rows = $ops->recentWebhooks($selectedAppId, 50);

        $appOptions = '<option value="">All apps</option>';
        foreach ($apps as $app) {
            $selected = $selectedAppId === (int) $app['id'] ? ' selected' : '';
            $appOptions .= '<option value="' . self::e((string) $app['id']) . '"' . $selected . '>' . self::e((string) $app['name']) . '</option>';
        }

        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . '<td>' . self::e((string) $row['event_type']) . '</td>'
                . '<td>' . self::e((string) $row['status']) . '</td>'
                . '<td style="font-size:12px">' . self::e((string) ($row['error_message'] ?? '')) . '</td>'
                . '<td>' . self::e((string) $row['processed_at']) . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No webhook logs found</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>Webhooks</h1>
            </div>
            <div class="card">
                <form method="get" action="' . self::e(self::u('/admin/webhooks')) . '" class="filters" style="grid-template-columns:2fr auto">
                    <select name="app_id">' . $appOptions . '</select>
                    <button type="submit">Filter</button>
                </form>
            </div>
            <div class="card">
                <table>
                    <thead><tr><th>Event</th><th>Status</th><th>Error</th><th>Processed</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('Webhooks', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $apps = (new AppRepository())->listAll();
        $selectedAppId = (int) ($request->query['app_id'] ?? 0);
        if ($selectedAppId <= 0 && $apps !== []) {
            $selectedAppId = (int) $apps[0]['id'];
        }

        $repo = new GoogleAnalyticsRepository();
        $service = new GoogleAnalyticsService();
        $current = $selectedAppId > 0 ? $repo->findByAppId($selectedAppId) : null;
        $rows = $repo->listAll();

        $message = '';
        if ((string) ($request->query['saved'] ?? '') === '1') {
            $message = '<div class="success">Google Analytics integration saved.</div>';
        } elseif ((string) ($request->query['error'] ?? '') === 'invalid_measurement') {
            $message = '<div class="alert">Measurement ID format is invalid. Use a value such as `G-XXXXXXX`.</div>';
        }

        $appOptions = self::appOptionsHtml($apps, $selectedAppId, true);
        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/integrations/google-analytics?app_id=' . (string) $row['app_id'])) . '">' . self::e((string) $row['app_name']) . '</a></td>'
                . '<td>' . self::e((string) ($row['measurement_id'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($row['website_url'] ?? '')) . '</td>'
                . '<td>' . self::e((int) ($row['is_enabled'] ?? 0) === 1 ? 'Enabled' : 'Disabled') . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No Google Analytics integrations configured yet</td></tr>';
        }

        $checklist = '';
        foreach ($service->integrationChecklist() as $item) {
            $checklist .= '<li>' . self::e($item) . '</li>';
        }

        $content = '
            <div class="topbar">
                <h1>Google Analytics</h1>
            </div>
            ' . $message . '
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>Configuration</h3>
                    <form method="post" action="' . self::e(self::u('/admin/integrations/google-analytics')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Application</label>
                        <select name="app_id">' . $appOptions . '</select>
                        <label>Enabled</label>
                        <select name="is_enabled">
                            <option value="1"' . ((int) ($current['is_enabled'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . ((int) ($current['is_enabled'] ?? 0) === 0 ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Measurement ID</label>
                        <input type="text" name="measurement_id" value="' . self::e((string) ($current['measurement_id'] ?? '')) . '" placeholder="G-XXXXXXXXXX">
                        <label>Website URL</label>
                        <input type="url" name="website_url" value="' . self::e((string) ($current['website_url'] ?? '')) . '" placeholder="https://www.vase-domena.com">
                        <label>Property Name</label>
                        <input type="text" name="property_name" value="' . self::e((string) ($current['property_name'] ?? '')) . '" placeholder="LoFTP Website">
                        <label>Google Analytics Admin Link</label>
                        <input type="url" name="admin_link" value="' . self::e((string) ($current['admin_link'] ?? '')) . '" placeholder="https://analytics.google.com/...">
                        <label>Notes</label>
                        <textarea name="notes" rows="4">' . self::e((string) ($current['notes'] ?? '')) . '</textarea>
                        <button type="submit" style="margin-top:12px">Save Integration</button>
                    </form>
                </div>
                <div class="card">
                    <h3>How It Works</h3>
                    <p>L-CMD License Server stores only the configuration of the Google Analytics connection. Traffic, conversion and campaign data are viewed directly in Google Analytics.</p>
                    <ol class="about-steps">' . $checklist . '</ol>
                </div>
            </div>
            <div class="card">
                <h3>Configured Integrations</h3>
                <table>
                    <thead><tr><th>App</th><th>Measurement ID</th><th>Website</th><th>Status</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('Google Analytics', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function googleAnalyticsSave(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/integrations/google-analytics'));
            return;
        }

        $appId = (int) ($request->post['app_id'] ?? 0);
        if ($appId <= 0) {
            Response::redirect(self::u('/admin/integrations/google-analytics'));
            return;
        }

        $service = new GoogleAnalyticsService();
        $measurementId = $service->normalizeMeasurementId((string) ($request->post['measurement_id'] ?? ''));
        if (!$service->isValidMeasurementId($measurementId)) {
            Response::redirect(self::u('/admin/integrations/google-analytics?app_id=' . $appId . '&error=invalid_measurement'));
            return;
        }

        (new GoogleAnalyticsRepository())->upsertForApp($appId, [
            'is_enabled' => (int) ($request->post['is_enabled'] ?? 0) === 1,
            'measurement_id' => $measurementId,
            'website_url' => trim((string) ($request->post['website_url'] ?? '')) ?: null,
            'property_name' => trim((string) ($request->post['property_name'] ?? '')) ?: null,
            'admin_link' => trim((string) ($request->post['admin_link'] ?? '')) ?: null,
            'notes' => trim((string) ($request->post['notes'] ?? '')) ?: null,
        ]);

        Response::redirect(self::u('/admin/integrations/google-analytics?app_id=' . $appId . '&saved=1'));
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $apps = (new AppRepository())->listAll();
        $selectedAppId = (int) ($request->query['app_id'] ?? 0);
        $settingsRepo = new AISettingsRepository();
        $runsRepo = new AIInsightsRepository();
        $settings = self::moduleSettingsDefaults($settingsRepo->findByModuleKey('ai_insights'));
        $rows = $runsRepo->recent($selectedAppId > 0 ? $selectedAppId : null, 20);

        $message = '';
        if ((string) ($request->query['saved'] ?? '') === '1') {
            $message = '<div class="success">AI Insights settings saved.</div>';
        } elseif ((string) ($request->query['ran'] ?? '') === '1') {
            $message = '<div class="success">AI insight generated.</div>';
        }

        $appOptions = self::appOptionsHtml($apps, $selectedAppId, true);
        $appCheckboxes = self::renderAppCheckboxes($apps, $settings['enabled_app_ids']);

        $typeOptions = '';
        foreach (AIInsightsService::insightTypes() as $value => $label) {
            $typeOptions .= '<option value="' . self::e($value) . '">' . self::e($label) . '</option>';
        }

        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . '<td>' . self::e((string) ($row['app_name'] ?? 'All apps')) . '</td>'
                . '<td>' . self::e((string) $row['insight_type']) . '</td>'
                . '<td style="font-size:12px;white-space:pre-wrap">' . self::e(self::preview((string) ($row['output_text'] ?? ''), 220)) . '</td>'
                . '<td>' . self::e((string) $row['created_at']) . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No insight runs yet</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>AI Insights</h1>
            </div>
            ' . $message . '
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>Module Settings</h3>
                    <form method="post" action="' . self::e(self::u('/admin/integrations/ai-insights/settings')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Enabled</label>
                        <select name="is_enabled">
                            <option value="1"' . ($settings['is_enabled'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['is_enabled'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Provider</label>
                        <input type="text" name="provider_name" value="' . self::e($settings['provider_name']) . '" placeholder="OpenAI">
                        <label>Model</label>
                        <input type="text" name="model_name" value="' . self::e($settings['model_name']) . '" placeholder="gpt-5.4-mini">
                        <label>API Key</label>
                        <input type="password" name="api_key" placeholder="' . ($settings['api_key'] !== '' ? '(stored, leave blank to keep)' : '') . '">
                        <label>Daily Request Limit</label>
                        <input type="number" name="daily_request_limit" value="' . self::e((string) $settings['daily_request_limit']) . '" min="1" max="5000">
                        <label>Store Prompts</label>
                        <select name="store_prompts">
                            <option value="1"' . ($settings['store_prompts'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['store_prompts'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Redact Sensitive Data</label>
                        <select name="redact_sensitive_data">
                            <option value="1"' . ($settings['redact_sensitive_data'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['redact_sensitive_data'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Enabled Apps</label>
                        <div style="display:grid;gap:8px;margin-top:8px">' . $appCheckboxes . '</div>
                        <button type="submit" style="margin-top:12px">Save Settings</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Run New Insight</h3>
                    <p>This first version generates a safe internal summary from existing LCMS data. It does not call an external AI provider yet.</p>
                    <form method="post" action="' . self::e(self::u('/admin/integrations/ai-insights/run')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>App Scope</label>
                        <select name="app_id">' . $appOptions . '</select>
                        <label>Insight Type</label>
                        <select name="insight_type">' . $typeOptions . '</select>
                        <button type="submit" style="margin-top:12px">Generate Insight</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <h3>Recent Insight Runs</h3>
                <table>
                    <thead><tr><th>App</th><th>Type</th><th>Summary</th><th>Created</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('AI Insights', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsSettingsSave(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/integrations/ai-insights'));
            return;
        }

        $repo = new AISettingsRepository();
        $current = self::moduleSettingsDefaults($repo->findByModuleKey('ai_insights'));
        $apiKey = trim((string) ($request->post['api_key'] ?? ''));
        $repo->upsertByModuleKey('ai_insights', [
            'is_enabled' => (int) ($request->post['is_enabled'] ?? 0) === 1,
            'provider_name' => trim((string) ($request->post['provider_name'] ?? '')) ?: null,
            'model_name' => trim((string) ($request->post['model_name'] ?? '')) ?: null,
            'api_key' => $apiKey !== '' ? $apiKey : $current['api_key'],
            'daily_request_limit' => (int) ($request->post['daily_request_limit'] ?? 50),
            'store_prompts' => (int) ($request->post['store_prompts'] ?? 0) === 1,
            'redact_sensitive_data' => (int) ($request->post['redact_sensitive_data'] ?? 1) === 1,
            'enabled_app_ids' => self::intListFromPost($request->post['enabled_app_ids'] ?? []),
        ]);

        Response::redirect(self::u('/admin/integrations/ai-insights?saved=1'));
    }

    /** @param array<string,string> $routeParams */
    public static function aiInsightsRun(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/integrations/ai-insights'));
            return;
        }

        $appId = (int) ($request->post['app_id'] ?? 0);
        $insightType = trim((string) ($request->post['insight_type'] ?? 'ops_summary'));
        (new AIInsightsService())->run($appId > 0 ? $appId : null, $insightType);

        Response::redirect(self::u('/admin/integrations/ai-insights?ran=1'));
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $apps = (new AppRepository())->listAll();
        $selectedAppId = (int) ($request->query['app_id'] ?? 0);
        $settingsRepo = new AISettingsRepository();
        $runsRepo = new AIAssistantRepository();
        $settings = self::moduleSettingsDefaults($settingsRepo->findByModuleKey('ai_assistant'));
        $rows = $runsRepo->recent($selectedAppId > 0 ? $selectedAppId : null, 20);

        $message = '';
        if ((string) ($request->query['saved'] ?? '') === '1') {
            $message = '<div class="success">AI Assistant settings saved.</div>';
        } elseif ((string) ($request->query['ran'] ?? '') === '1') {
            $message = '<div class="success">Draft generated.</div>';
        }

        $appOptions = self::appOptionsHtml($apps, $selectedAppId, true);
        $appCheckboxes = self::renderAppCheckboxes($apps, $settings['enabled_app_ids']);

        $taskOptions = '';
        foreach (AIAssistantService::taskTypes() as $value => $label) {
            $taskOptions .= '<option value="' . self::e($value) . '">' . self::e($label) . '</option>';
        }

        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . '<td>' . self::e((string) ($row['app_name'] ?? 'All apps')) . '</td>'
                . '<td>' . self::e((string) $row['task_type']) . '</td>'
                . '<td style="font-size:12px;white-space:pre-wrap">' . self::e(self::preview((string) ($row['output_text'] ?? ''), 220)) . '</td>'
                . '<td>' . self::e((string) $row['created_at']) . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No assistant drafts yet</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>AI Assistant</h1>
            </div>
            ' . $message . '
            <div class="grid" style="align-items:start">
                <div class="card">
                    <h3>Module Settings</h3>
                    <form method="post" action="' . self::e(self::u('/admin/integrations/ai-assistant/settings')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>Enabled</label>
                        <select name="is_enabled">
                            <option value="1"' . ($settings['is_enabled'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['is_enabled'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Provider</label>
                        <input type="text" name="provider_name" value="' . self::e($settings['provider_name']) . '" placeholder="OpenAI">
                        <label>Model</label>
                        <input type="text" name="model_name" value="' . self::e($settings['model_name']) . '" placeholder="gpt-5.4-mini">
                        <label>API Key</label>
                        <input type="password" name="api_key" placeholder="' . ($settings['api_key'] !== '' ? '(stored, leave blank to keep)' : '') . '">
                        <label>Daily Request Limit</label>
                        <input type="number" name="daily_request_limit" value="' . self::e((string) $settings['daily_request_limit']) . '" min="1" max="5000">
                        <label>Store Prompts</label>
                        <select name="store_prompts">
                            <option value="1"' . ($settings['store_prompts'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['store_prompts'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Redact Sensitive Data</label>
                        <select name="redact_sensitive_data">
                            <option value="1"' . ($settings['redact_sensitive_data'] ? ' selected' : '') . '>Yes</option>
                            <option value="0"' . (!$settings['redact_sensitive_data'] ? ' selected' : '') . '>No</option>
                        </select>
                        <label>Enabled Apps</label>
                        <div style="display:grid;gap:8px;margin-top:8px">' . $appCheckboxes . '</div>
                        <button type="submit" style="margin-top:12px">Save Settings</button>
                    </form>
                </div>
                <div class="card">
                    <h3>Generate Draft</h3>
                    <p>This first version creates deterministic internal drafts for support, content and operations work.</p>
                    <form method="post" action="' . self::e(self::u('/admin/integrations/ai-assistant/run')) . '">
                        <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                        <label>App Scope</label>
                        <select name="app_id">' . $appOptions . '</select>
                        <label>Task Type</label>
                        <select name="task_type">' . $taskOptions . '</select>
                        <label>Input Context</label>
                        <textarea name="input_text" rows="6" placeholder="Describe the support case, product context or content request."></textarea>
                        <button type="submit" style="margin-top:12px">Generate Draft</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <h3>Recent Drafts</h3>
                <table>
                    <thead><tr><th>App</th><th>Task</th><th>Draft</th><th>Created</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('AI Assistant', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantSettingsSave(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/integrations/ai-assistant'));
            return;
        }

        $repo = new AISettingsRepository();
        $current = self::moduleSettingsDefaults($repo->findByModuleKey('ai_assistant'));
        $apiKey = trim((string) ($request->post['api_key'] ?? ''));
        $repo->upsertByModuleKey('ai_assistant', [
            'is_enabled' => (int) ($request->post['is_enabled'] ?? 0) === 1,
            'provider_name' => trim((string) ($request->post['provider_name'] ?? '')) ?: null,
            'model_name' => trim((string) ($request->post['model_name'] ?? '')) ?: null,
            'api_key' => $apiKey !== '' ? $apiKey : $current['api_key'],
            'daily_request_limit' => (int) ($request->post['daily_request_limit'] ?? 50),
            'store_prompts' => (int) ($request->post['store_prompts'] ?? 0) === 1,
            'redact_sensitive_data' => (int) ($request->post['redact_sensitive_data'] ?? 1) === 1,
            'enabled_app_ids' => self::intListFromPost($request->post['enabled_app_ids'] ?? []),
        ]);

        Response::redirect(self::u('/admin/integrations/ai-assistant?saved=1'));
    }

    /** @param array<string,string> $routeParams */
    public static function aiAssistantRun(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/integrations/ai-assistant'));
            return;
        }

        $appId = (int) ($request->post['app_id'] ?? 0);
        $taskType = trim((string) ($request->post['task_type'] ?? 'support_reply'));
        $inputText = trim((string) ($request->post['input_text'] ?? ''));
        (new AIAssistantService())->run($appId > 0 ? $appId : null, $taskType, $inputText);

        Response::redirect(self::u('/admin/integrations/ai-assistant?ran=1'));
    }

    /** @param array<string,string> $routeParams */
    public static function smtpSettings(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $settings = new SettingsRepository();
        $keys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
        $values = $settings->getMultiple($keys);
        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Saved</div>' : '';
        $testResult = (string) ($request->query['test'] ?? '');

        $testMsg = '';
        if ($testResult === 'ok') {
            $testMsg = '<div class="success">Test email sent</div>';
        } elseif ($testResult === 'fail') {
            $testMsg = '<div class="alert">Test email failed</div>';
        }

        $encOptions = '';
        foreach (['tls', 'ssl', 'none'] as $enc) {
            $sel = ($values['smtp_encryption'] ?? 'tls') === $enc ? ' selected' : '';
            $encOptions .= '<option value="' . $enc . '"' . $sel . '>' . $enc . '</option>';
        }

        $content = '
            <div class="topbar">
                <h1>SMTP Settings</h1>
            </div>
            ' . $message . $testMsg . '
            <div class="card">
                <form method="post" action="' . self::e(self::u('/admin/settings/smtp')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="_action" value="save">
                    <label>Host</label>
                    <input type="text" name="smtp_host" value="' . self::e((string) ($values['smtp_host'] ?? '')) . '">
                    <label>Port</label>
                    <input type="number" name="smtp_port" value="' . self::e((string) ($values['smtp_port'] ?? '587')) . '">
                    <label>Username</label>
                    <input type="text" name="smtp_username" value="' . self::e((string) ($values['smtp_username'] ?? '')) . '">
                    <label>Password</label>
                    <input type="password" name="smtp_password" placeholder="' . ($values['smtp_password'] ? '(unchanged)' : '') . '">
                    <label>Encryption</label>
                    <select name="smtp_encryption">' . $encOptions . '</select>
                    <label>From Email</label>
                    <input type="email" name="smtp_from_email" value="' . self::e((string) ($values['smtp_from_email'] ?? '')) . '">
                    <label>From Name</label>
                    <input type="text" name="smtp_from_name" value="' . self::e((string) ($values['smtp_from_name'] ?? '')) . '">
                    <button type="submit" style="margin-top:12px">Save</button>
                </form>
            </div>
            <div class="card">
                <h3>Test Email</h3>
                <form method="post" action="' . self::e(self::u('/admin/settings/smtp')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="_action" value="test">
                    <label>Recipient</label>
                    <input type="email" name="test_email" required>
                    <button type="submit" style="margin-top:12px">Send Test</button>
                </form>
            </div>
        ';

        Response::html(self::layout('SMTP Settings', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function smtpSettingsSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/settings/smtp'));
            return;
        }

        $action = (string) ($request->post['_action'] ?? 'save');
        $settings = new SettingsRepository();

        if ($action === 'test') {
            $email = trim((string) ($request->post['test_email'] ?? ''));
            if ($email !== '') {
                $mail = new \App\Services\MailService();
                $result = $mail->sendTestEmail($email);
                $flag = ($result['success'] ?? false) ? 'ok' : 'fail';
                Response::redirect(self::u('/admin/settings/smtp') . '?test=' . $flag);
                return;
            }
        }

        $fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $request->post)) {
                $value = trim((string) $request->post[$f]) ?: null;
                $settings->set($f, $value);
                $mirrorMap = [
                    'smtp_host' => 'mail_host',
                    'smtp_port' => 'mail_port',
                    'smtp_username' => 'mail_username',
                    'smtp_encryption' => 'mail_encryption',
                    'smtp_from_email' => 'mail_from_address',
                    'smtp_from_name' => 'mail_from_name',
                ];
                if (isset($mirrorMap[$f])) {
                    $settings->set($mirrorMap[$f], $value);
                }
            }
        }

        $password = trim((string) ($request->post['smtp_password'] ?? ''));
        if ($password !== '') {
            $settings->set('smtp_password', $password);
            $settings->set('mail_password', $password);
        }

        Response::redirect(self::u('/admin/settings/smtp') . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function emailTemplatesPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $pdo = Database::pdo();
        $templates = $pdo->query('SELECT * FROM email_templates ORDER BY id ASC')->fetchAll() ?: [];
        $message = (string) ($request->query['saved'] ?? '') === '1' ? '<div class="success">Saved</div>' : '';

        $forms = '';
        foreach ($templates as $t) {
            $forms .= '
            <div class="card">
                <h3>' . self::e((string) $t['template_key']) . '</h3>
                <p style="font-size:12px;color:var(--text-muted)">Placeholders: ' . self::e((string) ($t['available_placeholders'] ?? '')) . '</p>
                <form method="post" action="' . self::e(self::u('/admin/settings/email-templates')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="template_key" value="' . self::e((string) $t['template_key']) . '">
                    <label>Subject</label>
                    <input type="text" name="subject" value="' . self::e((string) $t['subject']) . '">
                    <label>Body</label>
                    <textarea name="body" rows="6">' . self::e((string) $t['body']) . '</textarea>
                    <button type="submit" style="margin-top:12px">Save</button>
                </form>
            </div>';
        }

        if ($forms === '') {
            $forms = '<div class="card"><p>No email templates found. Import the database schema first.</p></div>';
        }

        $content = '
            <div class="topbar">
                <h1>Email Templates</h1>
            </div>
            ' . $message . $forms . '
        ';

        Response::html(self::layout('Email Templates', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function emailTemplatesSubmit(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/settings/email-templates'));
            return;
        }

        $key = trim((string) ($request->post['template_key'] ?? ''));
        $subject = trim((string) ($request->post['subject'] ?? ''));
        $body = trim((string) ($request->post['body'] ?? ''));

        if ($key !== '') {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('UPDATE email_templates SET subject = :subject, body = :body WHERE template_key = :key');
            $stmt->execute(['subject' => $subject, 'body' => $body, 'key' => $key]);
        }

        Response::redirect(self::u('/admin/settings/email-templates') . '?saved=1');
    }

    /** @param array<string,string> $routeParams */
    public static function auditLogPage(Request $request, array $routeParams): void
    {
        unset($routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = 50;
        $actionFilter = trim((string) ($request->query['action'] ?? ''));

        $pdo = Database::pdo();
        $where = '';
        $params = [];

        if ($actionFilter !== '') {
            $where = 'WHERE action = :action';
            $params['action'] = $actionFilter;
        }

        $stmt = $pdo->prepare("SELECT * FROM audit_log {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $tableRows = '';
        foreach ($rows as $r) {
            $details = (string) ($r['new_values'] ?? '{}');
            if (strlen($details) > 100) {
                $details = substr($details, 0, 100) . '...';
            }
            $tableRows .= '<tr>'
                . '<td>' . self::e((string) $r['action']) . '</td>'
                . '<td>' . self::e((string) ($r['entity_type'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($r['entity_id'] ?? '')) . '</td>'
                . '<td style="font-size:12px">' . self::e($details) . '</td>'
                . '<td>' . self::e((string) $r['created_at']) . '</td>'
                . '</tr>';
        }
        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="5">No records</td></tr>';
        }

        $base = self::u('/admin/audit-log') . '?action=' . rawurlencode($actionFilter);

        $content = '
            <div class="topbar">
                <h1>Audit Log</h1>
            </div>
            <div class="card">
                <form method="get" action="' . self::e(self::u('/admin/audit-log')) . '" class="filters" style="grid-template-columns:2fr auto">
                    <input type="text" name="action" placeholder="Filter by action" value="' . self::e($actionFilter) . '">
                    <button type="submit">Filter</button>
                </form>
            </div>
            <div class="card">
                <table>
                    <thead><tr><th>Action</th><th>Entity</th><th>ID</th><th>Details</th><th>Date</th></tr></thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
                <div class="pager">
                    <a class="btn-secondary" href="' . self::e($base . '&page=' . max(1, $page - 1)) . '">Prev</a>
                    <span>Page ' . $page . '</span>
                    <a class="btn-secondary" href="' . self::e($base . '&page=' . ($page + 1)) . '">Next</a>
                </div>
            </div>
        ';

        Response::html(self::layout('Audit Log', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function tenantsPage(Request $request, array $routeParams): void
    {
        unset($request, $routeParams);
        if (!self::requireLogin()) {
            return;
        }

        $tenants = (new TenantRepository())->listAll();
        $rows = '';
        foreach ($tenants as $tenant) {
            $rows .= '<tr>'
                . '<td><a href="' . self::e(self::u('/admin/tenants/' . (int) $tenant['id'])) . '">' . self::e((string) $tenant['name']) . '</a></td>'
                . '<td>' . self::e((string) $tenant['slug']) . '</td>'
                . '<td>' . self::e((string) $tenant['status']) . '</td>'
                . '<td>' . self::e((string) $tenant['plan_key']) . '</td>'
                . '<td>' . self::e((string) $tenant['default_currency']) . '</td>'
                . '<td>' . self::e((string) ($tenant['app_count'] ?? 0)) . '</td>'
                . '<td>' . self::e((string) ($tenant['product_count'] ?? 0)) . '</td>'
                . '<td>' . self::e((string) ($tenant['license_count'] ?? 0)) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No tenants</td></tr>';
        }

        $content = '
            <div class="topbar">
                <h1>Tenants</h1>
            </div>
            <div class="card">
                <table>
                    <thead><tr><th>Name</th><th>Slug</th><th>Status</th><th>Plan</th><th>Default Currency</th><th>Apps</th><th>Products</th><th>Licenses</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>
        ';

        Response::html(self::layout('Tenants', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function tenantDetailPage(Request $request, array $routeParams): void
    {
        unset($request);
        if (!self::requireLogin()) {
            return;
        }

        $tenant = (new TenantRepository())->findById((int) ($routeParams['id'] ?? 0));
        if ($tenant === null) {
            Response::html(self::layout('Tenant', '<div class="card"><p>Tenant not found.</p></div>'));
            return;
        }

        $apps = (new AppRepository())->listAllForTenant((int) $tenant['id']);
        $products = (new ProductRepository())->listAllForTenant((int) $tenant['id']);
        $licenses = (new LicenseRepository())->listForTenant((int) $tenant['id'], ['limit' => 10, 'offset' => 0]);
        $accessKeys = (new TenantAccessKeyRepository())->listForTenant((int) $tenant['id']);
        $billing = (new TenantBillingService())->summaryForTenant((int) $tenant['id']);
        $issuedAccessKey = $_SESSION['tenant_access_key_flash'][(int) $tenant['id']] ?? null;
        unset($_SESSION['tenant_access_key_flash'][(int) $tenant['id']]);

        $appRows = '';
        foreach ($apps as $app) {
            $appRows .= '<tr><td>' . self::e((string) $app['name']) . '</td><td>' . self::e((string) $app['slug']) . '</td><td>' . self::e((string) ($app['product_count'] ?? 0)) . '</td><td>' . self::e((string) ($app['key_count'] ?? 0)) . '</td></tr>';
        }
        if ($appRows === '') {
            $appRows = '<tr><td colspan="4">No apps</td></tr>';
        }

        $productRows = '';
        foreach ($products as $product) {
            $productRows .= '<tr><td>' . self::e((string) $product['name']) . '</td><td>' . self::e((string) $product['slug']) . '</td><td>' . self::e((string) ($product['active_tariff_count'] ?? 0)) . '</td></tr>';
        }
        if ($productRows === '') {
            $productRows = '<tr><td colspan="3">No products</td></tr>';
        }

        $licenseRows = '';
        foreach ($licenses as $license) {
            $licenseRows .= '<tr><td>' . self::e((string) $license['license_key']) . '</td><td>' . self::e((string) ($license['app_slug'] ?? '')) . '</td><td>' . self::e((string) $license['status']) . '</td><td>' . self::e((string) $license['customer_email']) . '</td></tr>';
        }
        if ($licenseRows === '') {
            $licenseRows = '<tr><td colspan="4">No licenses</td></tr>';
        }

        $accessKeyRows = '';
        foreach ($accessKeys as $accessKey) {
            $accessKeyRows .= '<tr>'
                . '<td>' . self::e((string) $accessKey['email']) . '</td>'
                . '<td>' . self::e((string) $accessKey['status']) . '</td>'
                . '<td>' . self::e((string) ($accessKey['expires_at'] ?? '')) . '</td>'
                . '<td>' . self::e((string) ($accessKey['used_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($accessKeyRows === '') {
            $accessKeyRows = '<tr><td colspan="4">No onboarding keys</td></tr>';
        }

        $planOptions = '';
        foreach (TenantPlanCatalog::selectablePlanKeys() as $planKey) {
            $definition = TenantPlanCatalog::definition($planKey);
            $selected = $planKey === (string) $billing['plan']['key'] ? ' selected' : '';
            $planOptions .= '<option value="' . self::e($planKey) . '"' . $selected . '>'
                . self::e((string) $definition['label']) . '</option>';
        }

        $statusOptions = '';
        foreach (['free', 'active', 'trialing', 'past_due', 'canceled'] as $status) {
            $selected = $status === (string) ($billing['billing']['status'] ?? '') ? ' selected' : '';
            $statusOptions .= '<option value="' . self::e($status) . '"' . $selected . '>' . self::e($status) . '</option>';
        }

        $content = '
            <div class="topbar">
                <h1>Tenant: ' . self::e((string) $tenant['name']) . '</h1>
                <a href="' . self::e(self::u('/admin/tenants')) . '" class="btn-secondary">Back</a>
            </div>
            ' . (is_array($issuedAccessKey) ? '
            <div class="card">
                <h3>Issued Onboarding Key</h3>
                <p>Email: <strong>' . self::e((string) ($issuedAccessKey['email'] ?? '')) . '</strong></p>
                <p>Activation key: <strong>' . self::e((string) ($issuedAccessKey['activation_key'] ?? '')) . '</strong></p>
                <p>Expires at: ' . self::e((string) ($issuedAccessKey['expires_at'] ?? '')) . '</p>
                <p>Email sent: ' . self::e(((bool) ($issuedAccessKey['email_sent'] ?? false)) ? 'yes' : 'no') . '</p>
            </div>' : '') . '
            <div class="grid">
                <div class="card"><h3>Status</h3><p>' . self::e((string) $tenant['status']) . '</p></div>
                <div class="card"><h3>Plan</h3><p>' . self::e((string) $tenant['plan_key']) . '</p></div>
                <div class="card"><h3>Timezone</h3><p>' . self::e((string) $tenant['timezone']) . '</p></div>
                <div class="card"><h3>Default Currency</h3><p>' . self::e((string) $tenant['default_currency']) . '</p></div>
            </div>
            <div class="grid">
                <div class="card"><h3>Active Licenses</h3><p>' . self::e((string) $billing['usage']['active_licenses']) . '</p></div>
                <div class="card"><h3>Included Licenses</h3><p>' . self::e((string) ($billing['usage']['included_active_licenses'] ?? 'Unlimited')) . '</p></div>
                <div class="card"><h3>Projected Overage</h3><p>' . self::e(number_format((float) ($billing['usage']['projected_overage_amount'] ?? 0), 2, '.', '')) . ' ' . self::e((string) ($billing['usage']['projected_overage_currency'] ?? 'EUR')) . '</p></div>
                <div class="card"><h3>Projects</h3><p>' . self::e((string) $billing['usage']['project_count']) . '</p></div>
            </div>
            <div class="card">
                <h3>Billing Plan</h3>
                <form method="post" action="' . self::e(self::u('/admin/tenants/' . (int) $tenant['id'] . '/billing')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Plan</label>
                    <select name="plan_key">' . $planOptions . '</select>
                    <label>Billing Status</label>
                    <select name="billing_status">' . $statusOptions . '</select>
                    <label>Stripe Customer ID</label>
                    <input type="text" name="stripe_customer_id" value="' . self::e((string) ($billing['billing']['stripe_customer_id'] ?? '')) . '">
                    <label>Stripe Subscription ID</label>
                    <input type="text" name="stripe_subscription_id" value="' . self::e((string) ($billing['billing']['stripe_subscription_id'] ?? '')) . '">
                    <label>Stripe Price ID</label>
                    <input type="text" name="stripe_price_id" value="' . self::e((string) ($billing['billing']['stripe_price_id'] ?? '')) . '">
                    <label>Current Period Starts At</label>
                    <input type="text" name="current_period_starts_at" value="' . self::e((string) ($billing['billing']['current_period_starts_at'] ?? '')) . '" placeholder="2026-03-30 00:00:00">
                    <label>Current Period Ends At</label>
                    <input type="text" name="current_period_ends_at" value="' . self::e((string) ($billing['billing']['current_period_ends_at'] ?? '')) . '" placeholder="2026-04-30 00:00:00">
                    <button type="submit">Save Billing Plan</button>
                </form>
            </div>
            <div class="card">
                <h3>Issue Onboarding Key</h3>
                <form method="post" action="' . self::e(self::u('/admin/tenants/' . (int) $tenant['id'] . '/access-keys')) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <label>Customer Email</label>
                    <input type="email" name="email" required>
                    <button type="submit">Generate And Send Key</button>
                </form>
            </div>
            <div class="card">
                <h3>Recent Onboarding Keys</h3>
                <table><thead><tr><th>Email</th><th>Status</th><th>Expires</th><th>Used At</th></tr></thead><tbody>' . $accessKeyRows . '</tbody></table>
            </div>
            <div class="card">
                <h3>Apps</h3>
                <table><thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Keys</th></tr></thead><tbody>' . $appRows . '</tbody></table>
            </div>
            <div class="card">
                <h3>Products</h3>
                <table><thead><tr><th>Name</th><th>Slug</th><th>Active Tariffs</th></tr></thead><tbody>' . $productRows . '</tbody></table>
            </div>
            <div class="card">
                <h3>Recent Licenses</h3>
                <table><thead><tr><th>Key</th><th>App</th><th>Status</th><th>Customer</th></tr></thead><tbody>' . $licenseRows . '</tbody></table>
            </div>
        ';

        Response::html(self::layout('Tenant Detail', $content));
    }

    /** @param array<string,string> $routeParams */
    public static function tenantBillingUpdate(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        $tenantId = (int) ($routeParams['id'] ?? 0);
        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/tenants/' . $tenantId));
            return;
        }

        $tenant = (new TenantRepository())->findById($tenantId);
        if ($tenant === null) {
            Response::redirect(self::u('/admin/tenants'));
            return;
        }

        (new TenantBillingService())->updateTenantBilling($tenantId, [
            'plan_key' => (string) ($request->post['plan_key'] ?? 'free'),
            'billing_status' => (string) ($request->post['billing_status'] ?? 'free'),
            'stripe_customer_id' => (string) ($request->post['stripe_customer_id'] ?? ''),
            'stripe_subscription_id' => (string) ($request->post['stripe_subscription_id'] ?? ''),
            'stripe_price_id' => (string) ($request->post['stripe_price_id'] ?? ''),
            'current_period_starts_at' => (string) ($request->post['current_period_starts_at'] ?? ''),
            'current_period_ends_at' => (string) ($request->post['current_period_ends_at'] ?? ''),
        ]);
        try {
            (new TenantOverageBillingService())->syncForTenant($tenantId);
        } catch (\Throwable $e) {
            error_log('Overage sync failed after tenantBillingUpdate: ' . $e->getMessage());
        }

        Response::redirect(self::u('/admin/tenants/' . $tenantId));
    }

    /** @param array<string,string> $routeParams */
    public static function tenantAccessKeyIssue(Request $request, array $routeParams): void
    {
        if (!self::requireLogin()) {
            return;
        }

        if (!CsrfService::verify((string) ($request->post['csrf_token'] ?? ''))) {
            Response::redirect(self::u('/admin/tenants/' . (int) ($routeParams['id'] ?? 0)));
            return;
        }

        $tenantId = (int) ($routeParams['id'] ?? 0);
        $tenant = (new TenantRepository())->findById($tenantId);
        if ($tenant === null) {
            Response::redirect(self::u('/admin/tenants'));
            return;
        }

        $email = mb_strtolower(trim((string) ($request->post['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::redirect(self::u('/admin/tenants/' . $tenantId));
            return;
        }

        $_SESSION['tenant_access_key_flash'][$tenantId] = (new CustomerAuthService())->issueAccessKey(
            $tenantId,
            $email,
            null,
            true
        );

        Response::redirect(self::u('/admin/tenants/' . $tenantId));
    }

    private static function requireLogin(): bool
    {
        if (self::isLoggedIn()) {
            return true;
        }

        Response::redirect(self::u('/admin/login'));
        return false;
    }

    private static function isLoggedIn(): bool
    {
        if (empty($_SESSION['admin_logged_in'])) {
            return false;
        }

        $maxLifetime = 60 * 60 * 8;
        $loginAt = (int) ($_SESSION['admin_login_at'] ?? 0);
        if ($loginAt <= 0 || (time() - $loginAt) > $maxLifetime) {
            $_SESSION = [];
            return false;
        }

        return true;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function u(string $path): string
    {
        return Url::path($path);
    }

    private static function safeAdminRedirectPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '://')) {
            return '';
        }

        return str_starts_with($path, '/admin') ? $path : '';
    }

    private static function layout(string $title, string $body, int $status = 200): string
    {
        http_response_code($status);

        $isLogin = $title === 'Admin Login';

        $navItems = [
            ['Dashboard', 'dashboard', self::u('/admin'), 'L-CMD License Server'],
            ['Tenants', 'domain', self::u('/admin/tenants'), 'Tenants'],
            ['Licenses', 'key', self::u('/admin/licenses'), 'Licenses'],
            ['Products', 'inventory_2', self::u('/admin/products'), 'Products'],
            ['Apps', 'apps', self::u('/admin/apps'), 'Apps'],
            ['Policies', 'tune', self::u('/admin/policies'), 'Policies'],
            ['Operations', 'monitoring', self::u('/admin/operations'), 'Operations'],
            ['Webhooks', 'sync', self::u('/admin/webhooks'), 'Webhooks'],
            ['Google Analytics', 'analytics', self::u('/admin/integrations/google-analytics'), 'Google Analytics'],
            ['AI Insights', 'insights', self::u('/admin/integrations/ai-insights'), 'AI Insights'],
            ['AI Assistant', 'smart_toy', self::u('/admin/integrations/ai-assistant'), 'AI Assistant'],
            ['About', 'info', self::u('/admin/about'), 'About'],
            ['SMTP Settings', 'mail', self::u('/admin/settings/smtp'), 'SMTP Settings'],
            ['Email Templates', 'draft', self::u('/admin/settings/email-templates'), 'Email Templates'],
            ['Audit Log', 'history', self::u('/admin/audit-log'), 'Audit Log'],
        ];

        $sidebarNav = '';
        foreach ($navItems as $item) {
            $isActive = $title === $item[3] || $title === $item[0] ? ' active' : '';
            $sidebarNav .= '<li><a href="' . self::e($item[2]) . '" class="nav-link' . $isActive . '"><span class="material-symbols-rounded">' . $item[1] . '</span><span>' . self::e($item[0]) . '</span></a></li>';
        }

        $sidebar = '';
        $mainPanelClass = 'main-panel';
        if (!$isLogin) {
            $sidebar = '
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <span class="sidebar-logo material-symbols-rounded">shield</span>
                    <span class="sidebar-title">L-CMD License Server</span>
                </div>
                <ul class="nav">' . $sidebarNav . '
                    <li class="nav-divider"></li>
                    <li>
                        <form method="post" action="' . self::e(self::u('/admin/logout')) . '" style="margin:0">
                            <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                            <button type="submit" class="nav-link nav-logout"><span class="material-symbols-rounded">logout</span><span>Sign Out</span></button>
                        </form>
                    </li>
                </ul>
            </div>';
            $mainPanelClass .= ' with-sidebar';
        }

        $pageTitle = $title;
        if ($title === 'L-CMD License Server') {
            $pageTitle = 'Dashboard';
        }

        $navbar = '';
        if (!$isLogin) {
            $navbar = '
            <nav class="navbar">
                <div class="navbar-left">
                    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                        <span class="material-symbols-rounded">menu</span>
                    </button>
                    <h2 class="navbar-title">' . self::e($pageTitle) . '</h2>
                </div>
                <div class="navbar-right">
                    <button type="button" class="theme-toggle btn-icon" id="themeToggle" aria-label="Toggle theme">
                        <span class="material-symbols-rounded" id="themeIcon">dark_mode</span>
                    </button>
                </div>
            </nav>';
        }

        $bodyContent = $isLogin
            ? '<div class="login-wrapper">' . $body . '
                <button type="button" class="theme-toggle-login btn-icon" id="themeToggle" aria-label="Toggle theme">
                    <span class="material-symbols-rounded" id="themeIcon">dark_mode</span>
                </button>
               </div>'
            : '<div class="content">' . $body . '<footer class="app-footer">© Localio Labs s.r.o. • <a href="https://example.invalid" target="_blank" rel="noopener noreferrer">example.invalid</a></footer></div>';

        return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . self::e($title) . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,0,0" rel="stylesheet">
<style>
:root{
    --bg: #f4f3ef;
    --surface: #ffffff;
    --surface-solid: #ffffff;
    --surface-border: #e3e3e3;
    --text: #252422;
    --text-muted: #9a9a9a;
    --primary: #51cbce;
    --primary-strong: #34b5b8;
    --primary-contrast: #ffffff;
    --secondary: #f4f3ef;
    --secondary-text: #252422;
    --success-bg: #daf5df;
    --success-text: #135426;
    --error-bg: #fce1e3;
    --error-text: #7c1b23;
    --focus-ring: rgba(81,203,206,0.25);
    --shadow: 0 1px 15px rgba(0,0,0,0.04), 0 1px 6px rgba(0,0,0,0.04);
    --sidebar-bg: linear-gradient(180deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    --sidebar-text: rgba(255,255,255,0.8);
    --sidebar-active-bg: rgba(255,255,255,0.12);
    --sidebar-hover-bg: rgba(255,255,255,0.08);
    --navbar-bg: #ffffff;
    --card-radius: 12px;
}
[data-theme="dark"]{
    --bg: #1a1a2e;
    --surface: #1e293b;
    --surface-solid: #1e293b;
    --surface-border: #334155;
    --text: #e2e8f0;
    --text-muted: #94a3b8;
    --primary: #51cbce;
    --primary-strong: #34b5b8;
    --primary-contrast: #0f172a;
    --secondary: #334155;
    --secondary-text: #e2e8f0;
    --success-bg: #183826;
    --success-text: #9de2ad;
    --error-bg: #3d1a1f;
    --error-text: #ffb7be;
    --focus-ring: rgba(81,203,206,0.3);
    --shadow: 0 1px 15px rgba(0,0,0,0.15), 0 1px 6px rgba(0,0,0,0.1);
    --sidebar-bg: linear-gradient(180deg, #0d0d1a 0%, #0f1629 50%, #0a2340 100%);
    --navbar-bg: #1e293b;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
    background: var(--bg);
    font-family: "Montserrat", sans-serif;
    color: var(--text);
    transition: background .2s ease, color .2s ease;
}

/* ---- Sidebar ---- */
.sidebar{
    position: fixed;
    left: 0; top: 0; bottom: 0;
    width: 260px;
    background: var(--sidebar-bg);
    z-index: 100;
    display: flex;
    flex-direction: column;
    transition: transform .3s ease;
}
.sidebar-header{
    padding: 24px 20px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sidebar-logo{
    color: var(--primary);
    font-size: 32px;
}
.sidebar-title{
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.nav{
    list-style: none;
    padding: 12px 0;
    flex: 1;
    overflow-y: auto;
}
.nav li{margin: 2px 12px}
.nav-link{
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 16px;
    border-radius: 10px;
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: background .15s ease, color .15s ease;
    border: none;
    background: none;
    width: 100%;
    cursor: pointer;
    font-family: inherit;
}
.nav-link:hover{background: var(--sidebar-hover-bg); color: #fff; text-decoration: none}
.nav-link.active{background: var(--sidebar-active-bg); color: #fff; font-weight: 600}
.nav-link .material-symbols-rounded{font-size: 20px; opacity: 0.7}
.nav-link.active .material-symbols-rounded{opacity: 1}
.nav-logout{color: rgba(255,255,255,0.5)}
.nav-logout:hover{color: #f5593d; background: rgba(245,89,61,0.1)}
.nav-divider{
    height: 1px;
    background: rgba(255,255,255,0.1);
    margin: 12px 4px !important;
}

/* ---- Main Panel ---- */
.main-panel{
    min-height: 100vh;
    transition: margin-left .3s ease;
}
.main-panel.with-sidebar{
    margin-left: 260px;
}

/* ---- Navbar ---- */
.navbar{
    background: var(--navbar-bg);
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--surface-border);
    position: sticky;
    top: 0;
    z-index: 50;
}
.navbar-left{display: flex; align-items: center; gap: 12px}
.navbar-title{font-size: 16px; font-weight: 600; color: var(--text); margin: 0}
.navbar-right{display: flex; align-items: center; gap: 8px}
.hamburger{
    display: none;
    background: none;
    border: none;
    color: var(--text);
    cursor: pointer;
    padding: 4px;
    border-radius: 8px;
}
.hamburger:hover{background: var(--secondary)}
.btn-icon{
    background: var(--secondary);
    color: var(--secondary-text);
    border: none;
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .15s ease;
}
.btn-icon:hover{background: var(--surface-border)}
.btn-icon .material-symbols-rounded{font-size: 20px}

/* ---- Content ---- */
.content{
    padding: 24px;
    max-width: 1200px;
}
.app-footer{
    margin-top: 28px;
    padding: 16px 4px 8px;
    color: var(--text-muted);
    font-size: 12px;
    text-align: center;
}
.app-footer a{
    color: var(--primary-strong);
    text-decoration: none;
}
.app-footer a:hover{text-decoration: underline}

/* ---- Login ---- */
.login-wrapper{
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    position: relative;
}
.theme-toggle-login{
    position: fixed;
    top: 16px;
    right: 16px;
}

/* ---- Cards ---- */
.card{
    background: var(--surface);
    border-radius: var(--card-radius);
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow);
    border: 1px solid var(--surface-border);
}
.auth-card{
    max-width: 460px;
    width: 100%;
    padding: 40px;
    border-radius: var(--card-radius);
}
h1{margin:0 0 12px 0;font-size:24px;font-weight:700;letter-spacing:.2px}
h3{margin:0 0 10px 0;font-size:17px;font-weight:600}
p{color:var(--text-muted)}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px}
.metric{font-size:28px;font-weight:700;margin:4px 0;color:var(--text)}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.info-panel{
    border: 1px solid var(--surface-border);
    border-radius: 12px;
    padding: 16px;
    background: color-mix(in srgb, var(--surface) 85%, var(--bg));
}
.info-panel h4{margin:0 0 10px;font-size:15px}
.about-list,.about-steps{
    margin: 12px 0 0 18px;
    color: var(--text);
}
.about-list li,.about-steps li{
    margin-bottom: 8px;
    color: var(--text);
}
.doc-links{
    margin-top: 14px;
    font-size: 13px;
}

/* ---- Paper Dashboard Stat Cards ---- */
.stat-card{
    background: var(--surface);
    border-radius: var(--card-radius);
    padding: 16px 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--surface-border);
    position: relative;
}
.stat-card-body{
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.stat-icon{
    width: 46px; height: 46px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.stat-icon .material-symbols-rounded{font-size: 22px}
.stat-info{text-align: right}
.stat-label{font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 500}
.stat-value{font-size: 28px; font-weight: 700; color: var(--text); line-height: 1.2; margin-top: 2px}
.stat-footer{
    border-top: 1px solid var(--surface-border);
    margin-top: 12px;
    padding-top: 10px;
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 4px;
}
.stat-footer .material-symbols-rounded{font-size: 14px}

/* ---- Forms & Tables ---- */
label{display:block;margin:12px 0 6px;font-weight:500;color:var(--text)}
input,select,textarea{
    width:100%;
    padding:10px 12px;
    border:1px solid var(--surface-border);
    border-radius:8px;
    background: var(--surface-solid);
    color: var(--text);
    font: inherit;
    font-size: 14px;
}
input:focus,select:focus,textarea:focus{
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--focus-ring);
}
button,.button-link{
    background: var(--primary);
    color: var(--primary-contrast);
    border:0;
    border-radius: 8px;
    padding:10px 18px;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    cursor:pointer;
    font-weight:600;
    font-size: 13px;
    transition: background .16s ease, transform .16s ease;
}
button:hover,.button-link:hover{background:var(--primary-strong);transform:translateY(-1px)}
.btn-secondary{
    background: var(--secondary);
    color: var(--secondary-text);
    border:0;
    border-radius:8px;
    padding:10px 14px;
    text-decoration:none;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    font-weight:500;
    font-size: 13px;
}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}
table{width:100%;border-collapse:collapse}
th,td{padding:11px 10px;border-bottom:1px solid var(--surface-border);text-align:left;font-size:13px}
th{font-weight:600;color:var(--text-muted);text-transform:uppercase;font-size:11px;letter-spacing:0.5px}
tbody tr:hover{background:color-mix(in srgb, var(--primary) 8%, transparent)}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
.alert{background:var(--error-bg);border:1px solid color-mix(in srgb, var(--error-bg) 60%, #000);color:var(--error-text);padding:10px;border-radius:8px;margin:10px 0}
.success{background:var(--success-bg);border:1px solid color-mix(in srgb, var(--success-bg) 60%, #000);color:var(--success-text);padding:10px;border-radius:8px;margin:10px 0}
.pager{margin-top:12px;display:flex;gap:10px;align-items:center}

/* ---- Overlay ---- */
.sidebar-overlay{
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    z-index: 99;
}

/* ---- Responsive ---- */
@media (max-width: 991px){
    .sidebar{transform: translateX(-260px)}
    .sidebar.open{transform: translateX(0)}
    .sidebar-overlay.open{display: block}
    .main-panel.with-sidebar{margin-left: 0}
    .hamburger{display: flex}
    .content{padding: 16px}
}
@media (max-width: 600px){
    .filters{grid-template-columns:1fr}
    .stat-card-body{flex-direction: column; gap: 8px}
    .stat-info{text-align: left}
}
</style>
</head>
<body>
' . $sidebar . '
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="' . $mainPanelClass . '">
' . $navbar . '
' . $bodyContent . '
</div>
<script>
(() => {
    const storageKey = "license-admin-theme";
    const root = document.documentElement;
    const toggle = document.getElementById("themeToggle");
    const icon = document.getElementById("themeIcon");

    const setTheme = (theme) => {
        root.setAttribute("data-theme", theme);
        const isDark = theme === "dark";
        if (icon) icon.textContent = isDark ? "light_mode" : "dark_mode";
    };

    const preferredDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
    const saved = localStorage.getItem(storageKey);
    const initial = saved === "light" || saved === "dark" ? saved : (preferredDark ? "dark" : "light");
    setTheme(initial);

    if (toggle) {
        toggle.addEventListener("click", () => {
            const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
            setTheme(next);
            localStorage.setItem(storageKey, next);
        });
    }

    const hamburger = document.getElementById("hamburgerBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("sidebarOverlay");
    if (hamburger && sidebar && overlay) {
        const toggleSidebar = () => {
            sidebar.classList.toggle("open");
            overlay.classList.toggle("open");
        };
        hamburger.addEventListener("click", toggleSidebar);
        overlay.addEventListener("click", toggleSidebar);
    }
})();
</script>
</body>
</html>';
    }

    private static function statusOptions(string $selected): string
    {
        $statuses = ['pending', 'active', 'expired', 'revoked', 'refunded'];
        $html = '';
        foreach ($statuses as $status) {
            $isSelected = $selected === $status ? ' selected' : '';
            $html .= '<option value="' . self::e($status) . '"' . $isSelected . '>' . self::e($status) . '</option>';
        }

        return $html;
    }

    private static function typeOptions(string $selected): string
    {
        $types = ['lifetime', 'annual', 'monthly', 'trial', 'whitelabel'];
        $html = '';
        foreach ($types as $type) {
            $isSelected = $selected === $type ? ' selected' : '';
            $html .= '<option value="' . self::e($type) . '"' . $isSelected . '>' . self::e($type) . '</option>';
        }

        return $html;
    }

    private static function setFlash(string $key, array $value): void
    {
        $_SESSION['admin_flash'][$key] = $value;
    }

    /** @return array<string,mixed>|null */
    private static function consumeFlash(string $key): ?array
    {
        $value = $_SESSION['admin_flash'][$key] ?? null;
        unset($_SESSION['admin_flash'][$key]);

        return is_array($value) ? $value : null;
    }

    /** @return array<string,array{label:string,description:string,placeholder:string}> */
    private static function policyDefinitions(): array
    {
        return PolicyCatalog::definitions();
    }

    /** @param array<string,string|int> $form */
    private static function renderPolicyFormCard(string $title, string $action, array $form): string
    {
        return '
            <div class="card">
                <h3>' . self::e($title) . '</h3>
                <p style="font-size:12px;color:var(--text-muted)">' . self::e((string) ($form['description'] ?? '')) . '</p>
                <form method="post" action="' . self::e($action) . '">
                    <input type="hidden" name="csrf_token" value="' . self::e(CsrfService::token()) . '">
                    <input type="hidden" name="policy_key" value="' . self::e((string) ($form['policy_key'] ?? '')) . '">
                    <input type="hidden" name="scope_id" value="' . self::e((string) ($form['scope_id'] ?? '')) . '">
                    <label>Enabled</label>
                    <select name="is_enabled">
                        <option value="1"' . ((int) ($form['is_enabled'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option>
                        <option value="0"' . ((int) ($form['is_enabled'] ?? 0) === 0 ? ' selected' : '') . '>No</option>
                    </select>
                    <label>Policy Value</label>
                    <textarea name="policy_value" rows="4" placeholder="' . self::e((string) ($form['placeholder'] ?? '')) . '">' . self::e((string) ($form['policy_value'] ?? '')) . '</textarea>
                    <button type="submit" style="margin-top:12px">Save Policy</button>
                </form>
            </div>
        ';
    }

    private static function policyValueForForm(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
            }

            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    }

    private static function decodePolicyValue(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $trimmed;
    }

    /** @param array<int,array<string,mixed>> $apps */
    private static function appOptionsHtml(array $apps, int $selectedAppId = 0, bool $includeAll = false): string
    {
        $options = $includeAll ? '<option value="">All apps</option>' : '';
        foreach ($apps as $app) {
            $selected = $selectedAppId === (int) $app['id'] ? ' selected' : '';
            $options .= '<option value="' . self::e((string) $app['id']) . '"' . $selected . '>' . self::e((string) $app['name']) . '</option>';
        }

        return $options;
    }

    /** @param array<int,array<string,mixed>> $apps */
    private static function renderAppCheckboxes(array $apps, array $selectedIds): string
    {
        if ($apps === []) {
            return '<div style="font-size:13px;color:var(--text-muted)">No apps available.</div>';
        }

        $html = '';
        foreach ($apps as $app) {
            $checked = in_array((int) $app['id'], $selectedIds, true) ? ' checked' : '';
            $html .= '<label style="display:flex;align-items:center;gap:8px;margin:0">'
                . '<input type="checkbox" name="enabled_app_ids[]" value="' . self::e((string) $app['id']) . '"' . $checked . ' style="width:auto">'
                . '<span>' . self::e((string) $app['name']) . ' <span style="color:var(--text-muted)">(' . self::e((string) $app['slug']) . ')</span></span>'
                . '</label>';
        }

        return $html;
    }

    /** @param mixed $raw */
    private static function intListFromPost(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [];
        $ints = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ints[] = $id;
            }
        }

        return array_values(array_unique($ints));
    }

    /** @param array<string,mixed>|null $settings */
    private static function moduleSettingsDefaults(?array $settings): array
    {
        return [
            'is_enabled' => (int) ($settings['is_enabled'] ?? 0) === 1,
            'provider_name' => (string) ($settings['provider_name'] ?? ''),
            'model_name' => (string) ($settings['model_name'] ?? ''),
            'api_key' => (string) ($settings['api_key'] ?? ''),
            'daily_request_limit' => (int) ($settings['daily_request_limit'] ?? 50),
            'store_prompts' => (int) ($settings['store_prompts'] ?? 0) === 1,
            'redact_sensitive_data' => !array_key_exists('redact_sensitive_data', $settings ?? []) || (int) ($settings['redact_sensitive_data'] ?? 1) === 1,
            'enabled_app_ids' => array_values(array_filter(array_map('intval', (array) ($settings['enabled_app_ids'] ?? [])))),
        ];
    }

    private static function preview(string $text, int $maxLength): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return substr($normalized, 0, $maxLength) . '...';
    }

    private static function renderCurrencyOptions(?string $selected = null): string
    {
        $selectedCurrency = CurrencyCatalog::normalize($selected) ?? 'EUR';
        $options = '';

        foreach (CurrencyCatalog::all() as $currency) {
            $options .= '<option value="' . self::e($currency) . '"'
                . ($currency === $selectedCurrency ? ' selected' : '')
                . '>' . self::e($currency) . '</option>';
        }

        return $options;
    }

    private static function integrationGuide(string $appSlug, ?string $appType = null): string
    {
        $guide = IntegrationGuideBuilder::build(
            $appSlug,
            $appType,
            rtrim((string) \App\Support\Env::get('APP_URL', ''), '/') . '/customer-api/apps/{id}/offline-public-key'
        );
        $productsUrl = (string) $guide['website']['endpoints']['products'];
        $checkoutUrl = (string) $guide['website']['endpoints']['checkout'];
        $verifyUrl = (string) $guide['application']['endpoints']['verify'];
        $activateUrl = (string) $guide['application']['endpoints']['activate'];
        $deactivateUrl = (string) $guide['application']['endpoints']['deactivate'];
        $applicationTitle = (string) $guide['application']['title'];
        $websiteItems = '';
        foreach ((array) ($guide['website']['items'] ?? []) as $item) {
            $websiteItems .= '<li>' . self::e((string) $item) . '</li>';
        }
        $applicationItems = '';
        foreach ((array) ($guide['application']['items'] ?? []) as $item) {
            $applicationItems .= '<li>' . self::e((string) $item) . '</li>';
        }
        $metadataRows = '';
        foreach ((array) ($guide['application']['metadata_example'] ?? []) as $key => $value) {
            $metadataRows .= '<li><code>' . self::e((string) $key) . '</code>: ' . self::e((string) $value) . '</li>';
        }
        $offlineItems = '';
        foreach ((array) ($guide['offline']['items'] ?? []) as $item) {
            $offlineItems .= '<li>' . self::e((string) $item) . '</li>';
        }
        $checklistItems = '';
        foreach ((array) ($guide['checklist'] ?? []) as $item) {
            $checklistItems .= '<li>' . self::e((string) $item) . '</li>';
        }

        return '
            <div class="info-grid">
                <div class="info-panel">
                    <h4>Website Setup</h4>
                    <ul class="about-list">' . $websiteItems . '</ul>
                </div>
                <div class="info-panel">
                    <h4>' . self::e($applicationTitle) . '</h4>
                    <ul class="about-list">' . $applicationItems . '</ul>
                    <h4 style="margin-top:16px">Metadata Example</h4>
                    <ul class="about-list">' . $metadataRows . '</ul>
                </div>
            </div>
            <div class="card" style="margin-top:16px">
                <h3>Offline Verification</h3>
                <ul class="about-list">' . $offlineItems . '</ul>
            </div>
            <div class="card" style="margin-top:16px">
                <h3>Launch Checklist</h3>
                <ul class="about-list">' . $checklistItems . '</ul>
            </div>
        ';
    }
}
