<?php
/**
 * Plugin Name: GitHub Releases
 * Plugin URI: http://wordpress.org/plugins/github-releases/
 * Description:
 * Version: 0.2
 * Author: X-Team
 * Author URI: http://x-team.com/wordpress/
 * License: GPLv2+
 */
class X_GitHub_Releases {

	const URL = 'https://api.github.com';

	public $repo, $owner, $token;

	function __construct() {
		// Webhook handlers
		add_action( 'wp_ajax_nopriv_github-releases', array( $this, 'webhook' ) );
		add_action( 'wp_ajax_github-releases', array( $this, 'webhook' ) ); // only for debugging

		// GitHub Authentication
		add_action( 'wp_ajax_github-releases-authenticate', array( $this, 'authenticate' ) );
		add_action( 'wp_ajax_github-releases-token', array( $this, 'token' ) );
	}

	function webhook() {
		$_POST = apply_filter( 'github-releases-webhook-data', $_POST );
		$payload = json_decode( $_POST['payload'] );

		// Accept only tag / release calls
		if ( preg_match( '#refs/tags/(.*)#', $payload->ref, $match ) <= 0 ) {
			return;
		}

		// Get version from tag name
		$tag   = $match[1];
		$name  = $payload->repository->name;
		$owner = $payload->repository->owner->name;

		// Match with existing registered repos
		$repos = apply_filters( 'github-releases-repos', array() );
		if ( ! isset( $repos[$name] ) || $repos[$name] != $owner ) {
			die( 'This repo is not registered in GitHub Releases!' );
		}

		// Do we have a token ?
		if ( ! get_option( 'github-releases-token' ) ) {
			die( 'No haz token!' );
		}

		$releases = $this->api( 'repos/' . $owner . '/' . $name . '/releases' );

		if ( ! $releases ) {
			die( 'No haz rlz!' );
		}
		$release = reset( $releases );

		// Check if no new releases are found.
		$last_release = null;
		$release_query = array(
			'post_type' => 'github-release',
			'posts_per_page' => 1,
			'post_status' => 'any',
			'meta_query' => array(
				array( 'key' => 'repo', 'value' => $name ),
				),
			);
		if ( $stored_releases = get_posts( $release_query ) ) {
			$last_release = $stored_releases[0];
		}
		if ( $last_release && $last_release->tag == $tag ) {
			die( 'No haz nyo releez!' ); // Nothing new here!
		}

		// Create release post
		$postarr = array(
			'post_type' => 'github-release',
			'post_title' => $tag,
			'post_name' => sanitize_title_with_dashes( $tag ),
			);
		$post_id = wp_insert_post( $postarr );

		$metaarr = array(
			'repo' => $name,
			'tag' => $tag,
			);
		foreach ( $metaarr as $k => $v ) {
			add_post_meta( $post_id, $k, $v );
		}

		// Download the zipball
		$download_link = $release->zipball_url;
		$file = $this->api( $download_link, 'GET', array( 'download' => true ) );

		// Store locally
		$filename = sanitize_title_with_dashes( $name ) . '_' . $tag . '.zip';
		$path = apply_filters( 'github-releases-directory', getenv( 'DOCUMENT_ROOT' ) . '/../github-releases/' );

		// TODO: Check if folder is writable

		$filename = $path . $filename;
		file_put_contents( $filename, $file );
		add_post_meta( $post_id, 'zipball', $filename );

		// Rename the folder within, assumes the prefix of `wp-`
		$this->rename_zip_root( $filename, str_replace( 'wp-', '', $name ) );

		die( 'Got it!' );
	}

	/**
	 * Rename the root folder inside a zip file
	 *
	 * Assumes the following:
	 * - There is always a single root folder
	 * 
	 * @param  string $filename      Path of the zip file
	 * @param  string $new_root_name New name for the root folder
	 * @return void
	 */
	function rename_zip_root( $filename, $new_root_name ) {
		$zip = new ZipArchive();
		$r = $zip->open( $filename );
		$info = $zip->statIndex( 0 );
		$name = $info['name'];
		$newname = $new_root_name . '/';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$zip->renameIndex( $i, str_replace( $name, $newname, $zip->getNameIndex( $i ) ) );
		}
		$zip->close();
	}

	function authenticate() {
		$client_id     = apply_filters( 'github-releases-client_id', filter_input( INPUT_GET, 'client_id' ) );
		$client_secret = apply_filters( 'github-releases-client_secret', filter_input( INPUT_GET, 'client_secret' ) );

		$url = 'https://github.com/login/oauth/authorize?scope=user,repo&client_id=%s&redirect_uri=%s';
		$redirect = admin_url( 'admin-ajax.php?action=github-releases-token' );
		$url = sprintf( $url, $client_id, urlencode( $redirect ) );
		header( 'Location: ' . $url );
		die;
	}

	function token() {
		$client_id     = apply_filters( 'github-releases-client_id', filter_input( INPUT_GET, 'client_id' ) );
		$client_secret = apply_filters( 'github-releases-client_secret', filter_input( INPUT_GET, 'client_secret' ) );

		$code  = filter_input( INPUT_GET, 'code' );

		$postdata = compact( 'client_id', 'client_secret', 'code' );
		$response = $this->api( 'https://github.com/login/oauth/access_token', 'post', array( 'body' => $postdata, 'noauth' => true, 'download' => 1 ) );
		$response = wp_parse_args( $response );

		if ( ! isset( $response['access_token'] ) ) {
			die;
		}
		update_option( 'github-releases-token', $response['access_token'] );
		die( 'Took Token!' );
	}

	function api( $path, $method = 'GET', array $options = array() ) {
		$function = "wp_remote_$method";
		$args = array();

		$token = get_option( 'github-releases-token' );
		if ( ! $token && empty( $options['noauth'] ) ) {
			throw new Exception( 'No valid token found!' );
			return false;
		}

		// Authorization
		if ( empty( $options['noauth'] ) ) {
			$args['headers'] = array(
				'Authorization' => 'token ' . $token,
				);
		}

		if ( strtolower( $method ) == 'post' && isset( $options['body'] ) ) {
			$args['body'] = $options['body'];
		}

		if ( false === strpos( $path, 'http' ) ) {
			$url = implode( '/', array( self::URL, $path ) );
		} else {
			$url = $path;
		}

		$response = call_user_func( $function, $url, $args );

		if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
			do_action( 'github-releases-error', $response );
			throw new Exception( 'Something happened!' . PHP_EOL . wp_remote_retrieve_response_code( $response ) );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! isset( $options['download'] ) || ! $options['download'] ) {
			$body = json_decode( $body );
		}
		return $body;
	}

}

$GLOBALS['X_GitHub_Releases'] = new X_GitHub_Releases;
