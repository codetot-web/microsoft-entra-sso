<?php
/**
 * Admin settings page template.
 *
 * Rendered by Settings_Page::render_page(). All output is escaped.
 *
 * @package SFME
 */

defined( 'ABSPATH' ) || exit;

use SFME\Admin\Settings_Page;

$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap sfme-settings-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php Settings_Page::render_tabs( $current_tab ); ?>

	<?php settings_errors( 'sfme_settings' ); ?>

	<?php if ( 'logs' === $current_tab ) : ?>

		<?php Settings_Page::render_logs_tab(); ?>

	<?php else : ?>

		<form method="post" action="options.php" novalidate="novalidate">

			<?php settings_fields( Settings_Page::OPTION_GROUP ); ?>

			<?php do_settings_sections( Settings_Page::PAGE_SLUG ); ?>

			<?php submit_button( __( 'Save Settings', 'sso-for-microsoft-entra' ) ); ?>

		</form>

	<?php endif; ?>

</div>