<?php
/* Add to wp-config.php BEFORE "That's all, stop editing".
   The proxy (cloudflared) terminates HTTPS and forwards http + X-Forwarded-Proto.
   Without this, WP generates http:// URLs and ends up in a redirect loop. */

if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}
define('WP_HOME',    'https://SUBDOMAIN.example.com');
define('WP_SITEURL', 'https://SUBDOMAIN.example.com');

/* DB = container (service name from docker-compose) */
// define('DB_HOST', 'SITE-db:3306');
