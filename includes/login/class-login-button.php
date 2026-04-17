<?php
/**
 * Renders the "Sign in with Microsoft" button on the WordPress login form.
 *
 * @package SFME\Login
 */

namespace SFME\Login;

defined( 'ABSPATH' ) || exit;

use SFME\Plugin;

/**
 * Class Login_Button
 *
 * Adds the SSO button below the standard WordPress login form by hooking into
 * the 'login_form' action. Inline CSS is injected via 'login_enqueue_scripts'
 * — no external stylesheet file is needed, keeping the asset footprint minimal.
 */
class Login_Button {

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Register hooks for the button and its styles.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'login_form', array( __CLASS__, 'render' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Output the SSO button HTML.
	 *
	 * Loads the template file so the markup stays separate from the PHP logic.
	 * Template variables are extracted into scope before the include.
	 *
	 * @return void
	 */
	public static function render(): void {
		$plugin = Plugin::get_instance();

		// Only render the button when at least a tenant ID is set.
		$tenant_id = (string) $plugin->get_option( Plugin::OPTION_TENANT_ID, '' );
		if ( '' === $tenant_id ) {
			return;
		}

		$button_text  = (string) $plugin->get_option(
			'sfme_button_text',
			__( 'Sign in with Microsoft', 'sso-for-microsoft-entra' )
		);
		$button_style = (string) $plugin->get_option( 'sfme_button_style', 'default' );
		$allow_local  = (bool) $plugin->get_option( 'sfme_allow_local_login', false );

		$sso_url   = esc_url( home_url( '/sso/login' ) );
		$local_url = esc_url( add_query_arg( 'local', '1', wp_login_url() ) );

		$template = SFME_PLUGIN_DIR . 'templates/login-button.php';

		if ( file_exists( $template ) ) {
			// Extract variables into template scope.
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract(
				array(
					'button_text'  => $button_text,
					'button_style' => $button_style,
					'allow_local'  => $allow_local,
					'sso_url'      => $sso_url,
					'local_url'    => $local_url,
				)
			);
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Styles
	// -------------------------------------------------------------------------

	/**
	 * Inject inline CSS for the SSO button on the login page.
	 *
	 * Inline styles are used to avoid a separate HTTP request for a tiny
	 * stylesheet. The styles are registered against the existing 'login'
	 * handle that WordPress enqueues on the login page.
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		$css = self::get_inline_css();

		// Attach the inline block to the 'login' stylesheet already loaded by WP.
		wp_add_inline_style( 'login', $css );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the inline CSS string for the login button.
	 *
	 * Three button variants are supported, matching Microsoft's brand guidelines:
	 *  - default : dark text on white background with dark border.
	 *  - dark    : white text on dark (#2F2F2F) background.
	 *  - light   : dark text on white background (alias for default).
	 *
	 * @return string CSS string (not escaped — it is only injected via
	 *                wp_add_inline_style() which handles the context).
	 */
	private static function get_inline_css(): string {
		return '
.sfme-divider {
	display: flex;
	align-items: center;
	text-align: center;
	margin: 16px 0;
	color: #646970;
	font-size: 13px;
}
.sfme-divider::before,
.sfme-divider::after {
	content: "";
	flex: 1;
	border-bottom: 1px solid #dcdcde;
}
.sfme-divider::before { margin-right: 8px; }
.sfme-divider::after  { margin-left:  8px; }

.sfme-btn-wrap {
	margin-bottom: 12px;
}

.sfme-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	padding: 0 12px;
	height: 41px;
	border-radius: 0;
	font-family: "Segoe UI", Helvetica, Arial, sans-serif;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	transition: background 0.15s ease, border-color 0.15s ease;
	box-sizing: border-box;
	border: 1px solid transparent;
	gap: 12px;
}

/* Default / light style */
.sfme-btn,
.sfme-btn-light {
	background: #ffffff;
	color: #2F2F2F;
	border-color: #2F2F2F;
}
.sfme-btn:hover,
.sfme-btn-light:hover {
	background: #f3f2f1;
	color: #2F2F2F;
}
.sfme-btn:focus,
.sfme-btn-light:focus {
	outline: 2px solid #0078d4;
	outline-offset: 2px;
}

/* Dark style */
.sfme-btn-dark {
	background: #2F2F2F;
	color: #ffffff;
	border-color: #2F2F2F;
}
.sfme-btn-dark:hover {
	background: #1a1a1a;
	color: #ffffff;
}
.sfme-btn-dark:focus {
	outline: 2px solid #0078d4;
	outline-offset: 2px;
}

.sfme-btn svg {
	flex-shrink: 0;
}

.sfme-local-login {
	text-align: center;
	margin-top: 8px;
	font-size: 12px;
}
.sfme-local-login a {
	color: #646970;
	text-decoration: underline;
}
.sfme-local-login a:hover {
	color: #1d2327;
}
		';
	}
}
