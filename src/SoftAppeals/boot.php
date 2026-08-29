<?php
declare(strict_types=1);

/**
 * The entry point.
 *
 * Every page in public_html starts with:
 *
 *     $app = require __DIR__ . '/src/SoftAppeals/boot.php';
 *
 * Kept separate from Bootstrap.php so that the autoloader resolving the
 * Bootstrap class can never have the side effect of booting the application.
 * Requiring this file more than once hands back the same instance rather than
 * building a second one, because a second Bootstrap would mean a second
 * database connection and a second correlation reference for one request.
 */

require_once __DIR__ . '/Bootstrap.php';

return \SoftAppeals\Bootstrap::instance();
