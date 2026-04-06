<?php

namespace FoundationZeroMass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Github_Updater {
	/**
	 * Singleton instance.
	 *
	 * @var Github_Updater|null
	 */
	private static $instance = null;

	/**
	 * Underlying update checker instance.
	 *
	 * @var object|null
	 */
	private $checker = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Github_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register updater bootstrap.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 5 );
	}

	/**
	 * Boot the Plugin Update Checker-backed updater.
	 *
	 * @return void
	 */
	public function boot() {
		if ( null !== $this->checker ) {
			return;
		}

		$loader = ZMM_PATH . 'plugin-update-checker/plugin-update-checker.php';

		if ( ! file_exists( $loader ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Foundation Zero Mass updater: plugin-update-checker library missing at ' . $loader ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return;
		}

		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Foundation Zero Mass updater: PUC factory class is unavailable.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return;
		}

		$repository_url = $this->get_repo_html_url();
		$plugin_file    = ZMM_FILE;
		$plugin_slug    = $this->get_plugin_slug();

		try {
			$this->checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$repository_url,
				$plugin_file,
				$plugin_slug
			);

			$token = $this->get_auth_token();

			if ( '' !== $token && method_exists( $this->checker, 'setAuthentication' ) ) {
				$this->checker->setAuthentication( $token );
			}
		} catch ( \Exception $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Foundation Zero Mass updater error: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Get the configured GitHub auth token.
	 *
	 * @return string
	 */
	private function get_auth_token() {
		$token = '';

		if ( defined( 'FOUNDATION_ZERO_MASS_GITHUB_TOKEN' ) && is_string( FOUNDATION_ZERO_MASS_GITHUB_TOKEN ) ) {
			$token = trim( FOUNDATION_ZERO_MASS_GITHUB_TOKEN );
		}

		$token = apply_filters( 'foundation_zero_mass_github_token', $token );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Get the configured repository slug.
	 *
	 * @return string
	 */
	private function get_repository() {
		$repository = apply_filters( 'foundation_zero_mass_github_repository', 'hawks010/foundation-zero-mass' );

		return is_string( $repository ) ? trim( $repository ) : 'hawks010/foundation-zero-mass';
	}

	/**
	 * Get the GitHub repository homepage URL.
	 *
	 * @return string
	 */
	private function get_repo_html_url() {
		return 'https://github.com/' . $this->get_repository();
	}

	/**
	 * Get the plugin slug.
	 *
	 * @return string
	 */
	private function get_plugin_slug() {
		return dirname( plugin_basename( ZMM_FILE ) );
	}
}
