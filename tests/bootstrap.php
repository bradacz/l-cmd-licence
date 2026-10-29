<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
// Deliberately do not load the application .env in tests.
putenv('APP_KEY=' . bin2hex(random_bytes(32)));
putenv('LICENSE_SALT=' . bin2hex(random_bytes(32)));
putenv('MAIL_ENABLED=0');
