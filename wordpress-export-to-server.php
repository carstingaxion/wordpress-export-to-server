<?php
/*
Plugin Name: Save Export to server
Description: New export behavior to save the export file on the server.
*/


/**
 * Decide whether or not the importer should attempt to download attachment files.
 * Default is true, can be filtered via import_allow_fetch_attachments. The choice
 * made at the import options screen must also be true, false here hides that checkbox.
 *
 * @return bool True if downloading attachments is allowed
 */
add_filter( 'import_allow_fetch_attachments', '__return_false', 99999 );

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
add_action( 'admin_bar_menu', 'wordpress_export_to_server_admin_bar_menu', 999 );




/* 
// add_filter('upload_dir', 'set_upload_folder', 999);

function set_upload_folder( $upload_data ) { 
	$owner_repo_branch   = get_option( 'wordpress_export_to_server__owner_repo_branch', false );
	$repo_branch         = explode( '/', $owner_repo_branch );
	$repo_branch         = join( '-', array( $repo_branch[1], $repo_branch[2] ) );
	$uploads_replacement = 
	// absolute dir path, must be writable by WordPress 
	$upload_data['basedir'] = trailingslashit( ABSPATH ) . '/files';
	$upload_data['baseurl'] = home_url( '/files' );
	$subdir                 = $upload_data['subdir'];
	$upload_data['path']    = $upload_data['basedir'] . $subdir;
	$upload_data['url']     = $upload_data['baseurl'] . $subdir;
	return wp_parse_args( $upload_data, $upload_data );
} */

/* 
function wpse_77960_upload_url() {
	$owner_repo_branch = get_option( 'wordpress_export_to_server__owner_repo_branch', false );
	return 'https://raw.githubusercontent.com/' . $owner_repo_branch;
} */



// Hook into the export_wp function
add_action( 'admin_init', 'wordpress_export_to_server', 9 );

function wordpress_export_to_server( $args = array() ) {
	if ( ! isset( $_GET['wordpress-export-to-server'] ) ) {
		return;
	}

	// Disable "WordPress Importer (v2)" because it hooks into the export
	// and makes it unusable for the "WordPress Importer" (v1).
	// deactivate_plugins( 'WordPress-Importer-master/plugin.php', true );
	// 
	// deactivation seems to be not enough to get rid of that.
	// remove_all_filters('') // !! would also remove the needed GatherPress Export stuff.
	remove_action( 'admin_init', 'wpimportv2_init' );

	// add_filter( 'pre_option_upload_url_path', 'wpse_77960_upload_url' );


	
	/** Load WordPress export API */
	require_once ABSPATH . 'wp-admin/includes/export.php';

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
	if ( $owner_repo_branch ) {
		$export_data = str_replace(
			// 'https://playground.wordpress.net/scope:0.0718053567460342/wp-content/uploads',
			// WP_CONTENT_URL . '/uploads',
			// WP_CONTENT_URL . '/' . $repo_branch,
			home_url( '/wp-content/' . $repo_branch ),
			// 'https://raw.githubusercontent.com/carstingaxion/gatherpress-demo-data/save-export-to-server',
			'https://raw.githubusercontent.com/' . $owner_repo_branch,
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




add_action( 'admin_notices', 'wordpress_export_to_server_admin_notice' );
function wordpress_export_to_server_admin_notice() {
	if ( ! isset( $_GET['wordpress-export-to-server-success'] ) || ! file_exists( rawurldecode( $_GET['wordpress-export-to-server-success'] ) ) ) {
		return;
	}
	printf(
		'<div class="notice notice-success"><p>Export saved successfully to <code>%s</code>!</p></div>',
		$_GET['wordpress-export-to-server-success']
	);
}
