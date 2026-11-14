<?php
/**
 * Loader for authors who bundle the library without Composer:
 *
 *     require_once __DIR__ . '/vendor/polar-wp-licensing/polar-wp-licensing.php';
 *
 * With Composer, the PSR-4 autoloader in composer.json is used instead.
 *
 * If several plugins on one site bundle this library, the first one loaded wins.
 * To avoid version clashes, prefix the namespace in your build (Strauss or PHP-Scoper);
 * see the README.
 *
 * @package PolarWP\Licensing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\PolarWP\Licensing\Licensing', false ) ) {
	require_once __DIR__ . '/src/ApiResponse.php';
	require_once __DIR__ . '/src/ApiClient.php';
	require_once __DIR__ . '/src/License.php';
	require_once __DIR__ . '/src/Updater.php';
	require_once __DIR__ . '/src/AdminPage.php';
	require_once __DIR__ . '/src/Licensing.php';
}
