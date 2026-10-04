<?php
/**
 * Constants for PHPStan (phpstan.neon.dist). WordPress's functions and
 * classes come from szepeviktor/phpstan-wordpress; this adds the constants
 * WordPress defines while it loads (wp-settings.php, wp-config.php) and
 * those seoprostack.php defines.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

define('WPINC', 'wp-includes');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('DB_NAME', 'wordpress');
define('SEOPROSTACK_VERSION', '0.0.0');
define('SEOPROSTACK_FILE', dirname(__DIR__) . '/seoprostack.php');
define('SEOPROSTACK_DIR', dirname(__DIR__) . '/');
define('SEOPROSTACK_URL', 'https://example.com/wp-content/plugins/seoprostack/');
