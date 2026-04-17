<?php
/**
 * Admin settings page template.
 *
 * Rendered by Settings_Page::render_page(). All output is escaped.
 *
 * @package SFME
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap sfme-settings-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'sfme_settings' ); ?>

	<form method="post" action="options.php" novalidate="novalidate">

		<?php settings_fields( \SFME\Admin\Settings_Page::OPTION_GROUP ); ?>

		<?php do_settings_sections( \SFME\Admin\Settings_Page::PAGE_SLUG ); ?>

		<?php submit_button( __( 'Save Settings', 'sso-for-microsoft-entra' ) ); ?>

	</form>

</div>
