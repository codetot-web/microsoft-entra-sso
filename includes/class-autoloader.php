<?php
/**
 * PSR-4-style autoloader for the SFME namespace.
 *
 * Maps class names under the SFME\ namespace to files inside
 * the includes/ directory, following WordPress file-naming conventions:
 *  - Directory segments use kebab-case.
 *  - Class files are prefixed with "class-" and use kebab-case.
 *
 * Example mappings
 * ────────────────
 *  SFME\Plugin
 *      → includes/class-plugin.php
 *
 *  SFME\Auth\OidcClient
 *      → includes/auth/class-oidc-client.php
 *
 *  SFME\Security\Encryption
 *      → includes/security/class-encryption.php
 *
 * @package SFME
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convert a PascalCase or StudlyCaps identifier to kebab-case.
 *
 * Handles consecutive uppercase letters (acronyms) gracefully:
 *  "OidcClient"  → "oidc-client"
 *  "XMLParser"   → "xml-parser"
 *
 * @param string $identifier PascalCase identifier string.
 * @return string kebab-case version.
 */
function sfme_to_kebab_case( string $identifier ): string {
	// Insert a hyphen before each uppercase letter that follows a lowercase
	// letter or digit, then lowercase the whole string.
	$kebab = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $identifier );

	// Also insert a hyphen between a sequence of uppercase letters followed by
	// a lowercase letter (e.g. "XMLParser" → "XML-Parser" first pass).
	$kebab = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $kebab );

	return strtolower( $kebab );
}

/**
 * Register the autoloader with the SPL autoload stack.
 *
 * The closure resolves a fully-qualified class name to an absolute file path
 * and requires the file when it exists. Unknown classes are silently ignored
 * so other autoloaders (e.g. Composer) can handle them.
 */
spl_autoload_register(
	function ( string $class_name ) {
		// Root namespace prefix for this plugin.
		$prefix        = 'SFME\\';
		$prefix_length = strlen( $prefix );

		// Bail early when the class does not belong to our namespace.
		if ( strncmp( $prefix, $class_name, $prefix_length ) !== 0 ) {
			return;
		}

		// Strip the namespace prefix to get the relative class identifier,
		// e.g. "Auth\OidcClient" from "SFME\Auth\OidcClient".
		$relative = substr( $class_name, $prefix_length );

		// Split on namespace separators to produce path segments.
		$parts = explode( '\\', $relative );

		// The last segment is the class name; everything before it is the
		// sub-directory path within includes/.
		$short_name = array_pop( $parts );

		// Convert each directory segment to kebab-case.
		$dir_segments = array_map( 'sfme_to_kebab_case', $parts );

		// Build the file name using WordPress convention: class-{kebab-name}.php
		// Replace underscores with hyphens so WP_Foo → class-wp-foo.php matches
		// the WordPress file-naming standard for underscore-separated class names.
		$file_name = 'class-' . str_replace( '_', '-', sfme_to_kebab_case( $short_name ) ) . '.php';

		// Assemble the absolute path.
		$path_parts = array_merge(
			array( SFME_PLUGIN_DIR . 'includes' ),
			$dir_segments,
			array( $file_name )
		);
		$file       = implode( DIRECTORY_SEPARATOR, $path_parts );

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
