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
		add_action( 'wp_ajax_nopriv_github-releases', array( $this, 'webhook' ) );
		add_action( 'wp_ajax_github-releases', array( $this, 'webhook' ) ); // only for debugging

		add_action( 'wp_ajax_github-releases-authenticate', array( $this, 'authenticate' ) );
		add_action( 'wp_ajax_github-releases-token', array( $this, 'token' ) );
		add_action( 'wp_ajax_github-releases-store', array( $this, 'store' ) );
	}

	function webhook() {
		$_POST = $this->sample();
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
		$filename = sanitize_title_with_dashes( $release->name ) . '_' . substr( md5( time() ), 0, 10 ) . '.zip';
		$path = apply_filters( 'github-releases-directory', getenv( 'DOCUMENT_ROOT' ) . '/../github-releases/' );
		$filename = $path . $filename;
		file_put_contents( $filename, $file );
		add_post_meta( $post_id, 'zipball', $filename );

		die( 'Got it!' );
	}

	function sample() {
		return wp_parse_args( urldecode( 'payload=%7B%22ref%22%3A%22refs%2Ftags%2F1.2%22%2C%22after%22%3A%2297bfb5e9eb928c28d4e9f79c485ae389c3d95803%22%2C%22before%22%3A%220000000000000000000000000000000000000000%22%2C%22created%22%3Atrue%2C%22deleted%22%3Afalse%2C%22forced%22%3Atrue%2C%22base_ref%22%3A%22refs%2Fheads%2Fmaster%22%2C%22compare%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%2Fcompare%2F1.2%22%2C%22commits%22%3A%5B%5D%2C%22head_commit%22%3A%7B%22id%22%3A%2297bfb5e9eb928c28d4e9f79c485ae389c3d95803%22%2C%22distinct%22%3Atrue%2C%22message%22%3A%22Update+README.md%22%2C%22timestamp%22%3A%222014-02-03T19%3A43%3A50-08%3A00%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%2Fcommit%2F97bfb5e9eb928c28d4e9f79c485ae389c3d95803%22%2C%22author%22%3A%7B%22name%22%3A%22Shady+Sharaf%22%2C%22email%22%3A%22shady%40sharaf.me%22%2C%22username%22%3A%22shadyvb%22%7D%2C%22committer%22%3A%7B%22name%22%3A%22Shady+Sharaf%22%2C%22email%22%3A%22shady%40sharaf.me%22%2C%22username%22%3A%22shadyvb%22%7D%2C%22added%22%3A%5B%5D%2C%22removed%22%3A%5B%5D%2C%22modified%22%3A%5B%22README.md%22%5D%7D%2C%22repository%22%3A%7B%22id%22%3A14350099%2C%22name%22%3A%22test%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%22%2C%22description%22%3A%22Test%22%2C%22watchers%22%3A0%2C%22stargazers%22%3A0%2C%22forks%22%3A0%2C%22fork%22%3Afalse%2C%22size%22%3A128%2C%22owner%22%3A%7B%22name%22%3A%22shadyvb%22%2C%22email%22%3A%22shady%40sharaf.me%22%7D%2C%22private%22%3Afalse%2C%22open_issues%22%3A0%2C%22has_issues%22%3Atrue%2C%22has_downloads%22%3Atrue%2C%22has_wiki%22%3Atrue%2C%22created_at%22%3A1384304941%2C%22pushed_at%22%3A1391485452%2C%22master_branch%22%3A%22master%22%7D%2C%22pusher%22%3A%7B%22name%22%3A%22shadyvb%22%2C%22email%22%3A%22shady%40sharaf.me%22%7D%7D' ) );
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
