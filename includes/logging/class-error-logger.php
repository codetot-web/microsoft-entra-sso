<?php
/**
 * Persistent error logging for the Microsoft Entra SSO plugin.
 *
 * Stores SSO authentication errors in a custom database table so that
 * site administrators can review login failures from the WordPress admin
 * settings page. Log entries include the error code, a human-readable
 * message, the client IP, the user agent, and a timestamp.
 *
 * The log table is created during the v2 database upgrade (see Upgrader)
 * and cleaned up on plugin uninstall.
 *
 * @package SFME\Logging
 */

namespace SFME\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Class Error_Logger
 *
 * All methods are static so the class acts as a simple namespace for
 * logging operations. No state is maintained between calls.
 */
class Error_Logger {

	/**
	 * Name of the custom database table (without $wpdb prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'sfme_error_log';

	/**
	 * Maximum age of log entries in days before they are auto-purged.
	 * Old entries are cleaned up on each new log write to keep the table
	 * size bounded without requiring a separate cron job.
	 *
	 * @var int
	 */
	const LOG_RETENTION_DAYS = 30;

	/**
	 * Maximum number of log entries to keep. When the table exceeds this
	 * count, the oldest entries are trimmed on each new log write.
	 *
	 * @var int
	 */
	const MAX_LOG_ENTRIES = 1000;

	/**
	 * Human-readable labels for known error codes.
	 *
	 * Maps the opaque error codes used in redirect_with_error() to
	 * translatable messages displayed in the admin error log.
	 *
	 * @var array<string, string>
	 */
	const ERROR_LABELS = array(
		'sso_build_url_failed'    => 'Failed to build the SSO authorization URL. The OpenID Connect discovery document could not be fetched or parsed.',
		'invalid_request_method'  => 'The SSO callback received an invalid request method. Only GET requests are accepted.',
		'missing_callback_params' => 'The SSO callback is missing required parameters (authorization code or state).',
		'rate_limited'            => 'Too many SSO login attempts from this IP address. The rate limit has been exceeded.',
		'oidc_callback_failed'    => 'The OpenID Connect callback processing failed. The token exchange or ID token validation returned an error.',
		'user_provision_failed'   => 'Failed to find or create a WordPress user account from the Entra ID identity claims.',
		'user_not_found'          => 'The WordPress user account associated with the Entra ID identity could not be found or created.',
		'state_invalid'           => 'The OAuth state parameter is invalid or has expired. The login session may have timed out.',
		'pkce_verifier_missing'   => 'The PKCE code verifier could not be found. The login session may have timed out.',
		'id_token_missing'        => 'No ID token was returned by the Microsoft Entra token endpoint.',
		'nonce_invalid'           => 'The ID token nonce is invalid or has already been used. Possible token replay attack.',
		'token_request_failed'    => 'The token request to Microsoft Entra failed due to a network error.',
		'credentials_missing'     => 'The plugin client credentials (Client ID or Client Secret) are not configured.',
		'discovery_fetch_failed'  => 'Failed to fetch the OpenID Connect discovery document from Microsoft Entra.',
		'discovery_parse_failed'  => 'The OpenID Connect discovery document could not be parsed.',
		'discovery_incomplete'    => 'The OpenID Connect discovery document is missing one or more required fields.',
		'tenant_id_missing'       => 'The Microsoft Entra tenant ID is not configured.',
		'jwt_signature_invalid'   => 'The ID token JWT signature verification failed. The token may have been tampered with.',
		'jwt_decode_failed'       => 'The ID token could not be decoded. The token format may be invalid.',
		'jwt_expired'             => 'The ID token has expired. The token was issued too long ago.',
		'jwt_issuer_mismatch'     => 'The ID token issuer does not match the expected Microsoft Entra issuer.',
		'jwt_audience_mismatch'   => 'The ID token audience does not match the expected Client ID.',
	);

	/**
	 * Return the full table name with the WordPress prefix.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string Prefixed table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Return the DDL for the error log table.
	 *
	 * Called by the upgrader during the v2 migration step.
	 *
	 * @return string CREATE TABLE statement.
	 */
	public static function get_schema_ddl(): string {
		global $wpdb;
		$table_name = self::get_table_name();
		$charset    = $wpdb->get_charset_collate();

		return "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			error_code VARCHAR(64) NOT NULL DEFAULT '',
			error_message TEXT NOT NULL DEFAULT '',
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent TEXT NOT NULL DEFAULT '',
			context VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY error_code (error_code),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * Install or update the error log table.
	 *
	 * Called by the upgrader during the v2 migration step. Uses
	 * dbDelta() so it is safe to call on every upgrade — it will
	 * only create the table if it does not already exist.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::get_schema_ddl() );
	}

	/**
	 * Record an SSO error in the persistent log.
	 *
	 * Automatically trims old entries (beyond LOG_RETENTION_DAYS) and
	 * enforces MAX_LOG_ENTRIES on each write so the table does not grow
	 * unbounded.
	 *
	 * @param string $error_code  Machine-readable error code (no spaces).
	 * @param string $message     Human-readable error description.
	 * @param string $context     Optional context string (e.g. the current action or stage).
	 *
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public static function log( string $error_code, string $message, string $context = '' ) {
		global $wpdb;

		$data = array(
			'error_code'    => sanitize_key( $error_code ),
			'error_message' => sanitize_text_field( $message ),
			'ip_address'    => self::get_client_ip(),
			'user_agent'    => self::get_user_agent(),
			'context'       => sanitize_text_field( $context ),
			'created_at'    => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$result = $wpdb->insert( self::get_table_name(), $data );

		// Trim old entries after each write to keep the table bounded.
		self::trim_old_entries();

		return $result;
	}

	/**
	 * Retrieve error log entries, newest first.
	 *
	 * @param int $limit  Maximum number of entries to return (default: 50).
	 * @param int $offset Offset for pagination (default: 0).
	 *
	 * @return array[] Array of log entry objects (each is a stdClass).
	 */
	public static function get_logs( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no caching needed.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe identifier, cannot be used as a prepare placeholder.
				"SELECT * FROM {$table_name}
				ORDER BY created_at DESC, id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Return the total number of log entries.
	 *
	 * @return int Total log entry count.
	 */
	public static function get_log_count(): int {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no caching needed.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe identifier, cannot be used as a prepare placeholder.
				"SELECT COUNT(*) FROM {$table_name}"
			)
		);
	}

	/**
	 * Delete all entries from the error log table.
	 *
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clear_logs() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Truncate on custom table; no caching needed.
		return $wpdb->query(
			"TRUNCATE TABLE {$wpdb->prefix}sfme_error_log"
		);
	}

	/**
	 * Drop the error log table entirely.
	 *
	 * Called during plugin uninstall.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe identifier, cannot be used as a prepare placeholder.
				"DROP TABLE IF EXISTS {$table_name}"
			)
		);
	}

	/**
	 * Get a human-readable label for an error code.
	 *
	 * Falls back to a generic message when the error code is not recognised.
	 *
	 * @param string $error_code Machine-readable error code.
	 *
	 * @return string Human-readable error description.
	 */
	public static function get_error_label( string $error_code ): string {
		if ( isset( self::ERROR_LABELS[ $error_code ] ) ) {
			return self::ERROR_LABELS[ $error_code ];
		}

		// Handle IdP error codes (idp_error_*).
		if ( str_starts_with( $error_code, 'idp_error_' ) ) {
			return sprintf(
				/* translators: %s: the error code returned by the identity provider */
				__( 'The identity provider returned an error: %s', 'sso-for-microsoft-entra' ),
				sanitize_key( substr( $error_code, 10 ) )
			);
		}

		return __( 'An unknown SSO error occurred.', 'sso-for-microsoft-entra' );
	}

	/**
	 * Remove log entries older than LOG_RETENTION_DAYS and enforce
	 * MAX_LOG_ENTRIES by deleting the oldest rows when the count
	 * exceeds the limit.
	 *
	 * @return void
	 */
	private static function trim_old_entries(): void {
		global $wpdb;

		$table_name = self::get_table_name();

		// Remove entries older than the retention period.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance query on custom table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe identifier, cannot be used as a prepare placeholder.
				"DELETE FROM {$table_name}
				WHERE created_at < %s",
				current_time( 'mysql' ) - ( self::LOG_RETENTION_DAYS * DAY_IN_SECONDS )
			)
		);

		// Enforce the maximum entry count by deleting the oldest rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance query on custom table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a safe identifier, cannot be used as a prepare placeholder.
				"DELETE FROM {$table_name}
				WHERE id NOT IN (
					SELECT id FROM (
						SELECT id FROM {$table_name}
						ORDER BY id DESC
						LIMIT %d
					) AS keep_ids
				)",
				// phpcs:enable
				self::MAX_LOG_ENTRIES
			)
		);
	}

	/**
	 * Obtain the client IP address for logging purposes.
	 *
	 * Uses REMOTE_ADDR as the authoritative value. Proxy headers are
	 * intentionally ignored because they are trivially spoofed.
	 *
	 * @return string IP address string, or an empty string when unavailable.
	 */
	private static function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Obtain the User-Agent string for logging purposes.
	 *
	 * @return string User-Agent string, or empty string when unavailable.
	 */
	private static function get_user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}
}
