<?php
/*
Plugin Name: Save Export to server
Description: New export behavior to save the export file on the server.
*/

add_action( 'admin_init', 'wordpress_export_to_server', 9 );
add_action( 'admin_bar_menu', 'wordpress_export_to_server_admin_bar_menu', 999 );
add_action( 'admin_notices', 'wordpress_export_to_server_admin_notice' );

/**
 * Configuration via `wp_options` (not filters).
 *
 * This plugin is designed to run inside WordPress Playground, where the
 * environment is bootstrapped from a declarative JSON blueprint. Playground's
 * `setSiteOptions` step can set option values *before any PHP code runs* —
 * there is no equivalent mechanism for filters.
 *
 * Filters require a PHP file to be loaded and to call `add_filter()` at
 * runtime. In Playground, that would mean either:
 *   1. An additional `writeFile` + `runPHP` step to create a file that
 *      registers filters — adding fragile ordering dependencies, or
 *   2. Hardcoding values in the plugin itself — defeating the purpose of
 *      per-blueprint configuration.
 *
 * Options solve both problems: they are set atomically in the blueprint,
 * available immediately via `get_option()`, and survive Playground restarts
 * within the same session.
 *
 * Option keys:
 * @see 'wordpress_export_to_server__file'              — Export filename (e.g. 'export.xml').
 * @see 'wordpress_export_to_server__path'              — Absolute server path to save directory.
 * @see 'wordpress_export_to_server__owner_repo_branch' — GitHub 'owner/repo/branch' for URL rewriting.
 * @see 'wordpress_export_to_server__export_home'       — Replacement home URL for portability.
 * @see 'wordpress_export_to_server__export_path'       — Appended to the raw GitHub URL.
 */

/**
 * Intercepts the export page request and writes a WXR export file
 * directly to the Playground server's filesystem.
 *
 * This function is hooked early on `admin_init` (priority 9) so it runs
 * before WordPress's built-in export handler. It checks for the
 * `wordpress-export-to-server` query parameter, generates the WXR XML
 * via `export_wp()`, applies URL replacements configured through options,
 * writes the result to disk, and redirects.
 *
 * Configuration is read from wp_options rather than apply_filters() intentionally.
 *
 * In a WordPress Playground context, options can be set declaratively in a
 * blueprint.json via the `setSiteOptions` step *before* any PHP executes.
 * Filters would require a separate PHP file to be loaded first (another
 * mu-plugin or a `runPHP` step), adding unnecessary complexity and ordering
 * dependencies to the blueprint. Options make the configuration self-contained
 * within the blueprint JSON.
 *
 * Available options:
 *   - wordpress_export_to_server__file              (string) Export filename.
 *   - wordpress_export_to_server__path              (string) Absolute server path for the export.
 *   - wordpress_export_to_server__owner_repo_branch (string) GitHub owner/repo/branch for URL rewriting.
 *   - wordpress_export_to_server__export_home       (string) Replacement home URL for portability.
 *   - wordpress_export_to_server__export_path       (string) Path appended to the raw GitHub URL.
 *
 * @since 0.1.0
 *
 * @see export_wp() For the core export function this wraps.
 * @see https://developer.wordpress.org/reference/functions/export_wp/
 *
 * @return void Function returns early if the query parameter is absent.
 *              Otherwise it redirects and calls `exit` (never returns).
 */
function wordpress_export_to_server(): void {
	if ( ! isset( $_GET['wordpress-export-to-server'] ) ) {
		return;
	}

	// Disable "WordPress Importer (v2)" because it hooks into the export
	// and makes it unusable for the "WordPress Importer" (v1).
	// deactivate_plugins( 'WordPress-Importer-master/plugin.php', true );
	// 
	// deactivation seems to be not enough to get rid of that.
	remove_action( 'admin_init', 'wpimportv2_init' );
	
	/** Load WordPress export API */
	require_once ABSPATH . 'wp-admin/includes/export.php';

	// could be provided via option or filter
	$args     = array();
	$defaults = array(
		'content' => 'all',
	);
	$args     = wp_parse_args( $args, $defaults );

	// Generate the export data.
	ob_start();
	export_wp( $args );
	$export_data = ob_get_clean();

	// // Replace attachment URLs
	// // from:
	// // 'https://playground.wordpress.net/scope:0.0718053567460342/wp-content/uploads'
	// // to:
	// // 'https://raw.githubusercontent.com/owner/repo/branch'
	$owner_repo_branch = get_option( 'wordpress_export_to_server__owner_repo_branch', false );
	$repo_branch       = explode( '/', $owner_repo_branch );
	$repo_branch       = join( '-', array( $repo_branch[1], $repo_branch[2] ) );
	$export_path       = get_option( 'wordpress_export_to_server__export_path', '/wp-content/uploads' );
	if ( $owner_repo_branch ) {
		$export_data = str_replace(
			// 'https://playground.wordpress.net/scope:0.0718053567460342/wp-content/uploads',
			wp_get_upload_dir()['baseurl'],
			// 'https://raw.githubusercontent.com/carstingaxion/gatherpress-demo-data/save-export-to-server',
			'https://raw.githubusercontent.com/' . $owner_repo_branch . $export_path,
			$export_data
		);
	}

	// prevent constant updating of existing posts & attachments
	$export_home = get_option( 'wordpress_export_to_server__export_home', false );
	if ( $export_home ) {
		$export_data = str_replace(
			// 'https://playground.wordpress.net/scope:0.0718053567460342/',
			home_url(),
			// 'https://gatherpress.test', // !! Without trailing slash
			$export_home,
			$export_data
		);
	}

	// Save the export data to a file on the server.
	// $path = get_option( 'wordpress_export_to_server__path', WP_CONTENT_DIR . '/uploads' );
	$path = get_option( 'wordpress_export_to_server__path', WP_CONTENT_DIR . '/' . $repo_branch );
	mkdir( $path );
	$file_path = $path . '/' . get_option( 'wordpress_export_to_server__file', 'export.xml' );
	file_put_contents( $file_path, $export_data );

	// Redirect to success page.
	wp_redirect( admin_url( 'export.php?wordpress-export-to-server-success=' . rawurlencode( $file_path ) ) );
	exit;
}

/**
 * Adds a "Export to server" link to the Toolbar.
 *
 * @param WP_Admin_Bar $wp_admin_bar Toolbar instance.
 * @see   https://developer.wordpress.org/reference/classes/wp_admin_bar/add_node/
 */
function wordpress_export_to_server_admin_bar_menu( $wp_admin_bar ) {
	$args = array(
		'id'    => 'wordpress_export_to_server',
		'title' => __( ' 💾 Save Export to server 🤖 ', 'wordpress-export-to-server' ),
		'href'  => admin_url( 'export.php?wordpress-export-to-server=1' ),
		'meta'  => array(
			'class' => 'wordpress-export-to-server',
		),
	);
	$wp_admin_bar->add_node( $args );
}

function wordpress_export_to_server_admin_notice() {
	if ( ! isset( $_GET['wordpress-export-to-server-success'] ) || ! file_exists( rawurldecode( $_GET['wordpress-export-to-server-success'] ) ) ) {
		return;
	}
	printf(
		'<div class="notice notice-success"><p>Export saved successfully to <code>%s</code>!</p></div>',
		$_GET['wordpress-export-to-server-success']
	);
}
