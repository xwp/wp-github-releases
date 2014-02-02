<?php
/**
 * Plugin Name: GitHub Releases
 * Plugin URI: http://wordpress.org/plugins/github-releases/
 * Description: 
 * Version: 0.1
 * Author: X-Team
 * Author URI: http://x-team.com/wordpress/
 * License: GPLv2+
 * Text Domain: github-releases
 * Domain Path: /languages
 */
class X_GitHub_Releases {

	const URL = 'https://api.github.com';

	public $token;

	function __construct() {

		add_action( 'wp_ajax_nopriv_github-releases', array( $this, 'webhook' ) );
		add_action( 'wp_ajax_github-releases', array( $this, 'webhook' ) ); // only for debugging

		add_action( 'wp_ajax_github-releases-authenticate', array( $this, 'authenticate' ) );
		add_action( 'wp_ajax_github-releases-token', array( $this, 'token' ) );

		add_filter(
			'github-releases-repos',
			function( $repos ) {
				$repos['test'] = array(
					'name' => 'test',
					'owner' => 'shadyvb',
					'token' => '8af03a2e95abc074012d',
				);
				return $repos;
			}
		); # DEBUG
		add_filter(
			'github-releases-client_id',
			function() {
				return '45d8af2921ce94576093';
			}
		); # DEBUG
		add_filter(
			'github-releases-client_secret',
			function() {
				return 'cac1edfe208daf958b5ca7dd0321a355918399d1';
			}
		); # DEBUG
		add_filter(
			'github-releases-token',
			function( $token ) {
				{echo '<pre>';var_dump( $token );echo '</pre>';die();}
			}
		); # DEBUG
	}

	function webhook() {
		$_POST = $this->sample();
		$payload = json_decode( $_POST['payload'] );

		// Accept only commits to master branch
		if ( $payload->ref != 'refs/heads/master' ) {
			return;
		}


		$name = $payload->repository->name;
		$owner = $payload->repository->owner->name;

		// Match with existing registered repos
		$repos = apply_filters( 'github-releases-repos', array() );
		foreach ( $repos as $_repo ) {
			if ( $name == $_repo['name'] && $owner == $_repo['owner'] ) {
				$repo = $_repo;
				break;
			}
		}
		if ( ! isset( $repo ) ) {
			die;
		}
		
		// TODO: Authenticate via API
		$token = $repo['token'];

		// Check new release tags
		$this->set_token( $token );
		$releases = $this->api( 'repos/' . $owner . '/' . $name . '/releases' );
		if ( ! $releases ) {
			die;
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
		if ( $last_release && $last_release->release_id == $release->id ) {
			die; // Nothing new here!
		}

		// Handle pre-releases
		$allow_pre_release = apply_filters( 'github-releases-allow-prereleases', false );
		if ( $release->prerelease && ! $allow_pre_release ) {
			die;
		}

		// Create release post
		$postarr = array(
			'post_type' => 'github-release',
			'post_title' => $release->name,
			'post_name' => sanitize_title_with_dashes( $release->name ),
			);
		$post_id = wp_insert_post( $postarr );

		$metaarr = array(
			'repo' => $name,
			'tag' => $release->tag_name,
			'release_id' => $release->id,
			);
		foreach ( $metaarr as $k => $v ) {
			add_post_meta( $post_id, $k, $v );
		}

		// Download the zipball
		$download_link = $release->zipball_url;
		$file = $this->api( $download_link, 'GET', true );

		// Store locally
		$filename = sanitize_title_with_dashes( $release->name ) . '_' . substr( md5( time() ), 0, 10 ) . '.zip';
		$path = apply_filters( 'github-releases-directory', getenv( 'DOCUMENT_ROOT' ) . '/../github-releases/' );
		$filename = $path . $filename;
		file_put_contents( $filename, $file );
		add_post_meta( $post_id, 'zipball', $filename );

		die;
	}

	function sample() {
		return wp_parse_args( urldecode( 'payload=%7B%22ref%22%3A%22refs%2Fheads%2Fmaster%22%2C%22after%22%3A%228fbd85b55df7a1d27534d69f4935344d43432a8b%22%2C%22before%22%3A%228fbd85b55df7a1d27534d69f4935344d43432a8b%22%2C%22created%22%3Afalse%2C%22deleted%22%3Afalse%2C%22forced%22%3Afalse%2C%22compare%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%2Fcompare%2F8fbd85b55df7...8fbd85b55df7%22%2C%22commits%22%3A%5B%5D%2C%22head_commit%22%3A%7B%22id%22%3A%228fbd85b55df7a1d27534d69f4935344d43432a8b%22%2C%22distinct%22%3Atrue%2C%22message%22%3A%22Initial+commit%22%2C%22timestamp%22%3A%222013-11-12T17%3A09%3A01-08%3A00%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%2Fcommit%2F8fbd85b55df7a1d27534d69f4935344d43432a8b%22%2C%22author%22%3A%7B%22name%22%3A%22Shady+Sharaf%22%2C%22email%22%3A%22shady%40sharaf.me%22%2C%22username%22%3A%22shadyvb%22%7D%2C%22committer%22%3A%7B%22name%22%3A%22Shady+Sharaf%22%2C%22email%22%3A%22shady%40sharaf.me%22%2C%22username%22%3A%22shadyvb%22%7D%2C%22added%22%3A%5B%22README.md%22%5D%2C%22removed%22%3A%5B%5D%2C%22modified%22%3A%5B%5D%7D%2C%22repository%22%3A%7B%22id%22%3A14350099%2C%22name%22%3A%22test%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fshadyvb%2Ftest%22%2C%22description%22%3A%22Test%22%2C%22watchers%22%3A0%2C%22stargazers%22%3A0%2C%22forks%22%3A0%2C%22fork%22%3Afalse%2C%22size%22%3A104%2C%22owner%22%3A%7B%22name%22%3A%22shadyvb%22%2C%22email%22%3A%22shady%40sharaf.me%22%7D%2C%22private%22%3Afalse%2C%22open_issues%22%3A0%2C%22has_issues%22%3Atrue%2C%22has_downloads%22%3Atrue%2C%22has_wiki%22%3Atrue%2C%22created_at%22%3A1384304941%2C%22pushed_at%22%3A1384304941%2C%22master_branch%22%3A%22master%22%7D%2C%22pusher%22%3A%7B%22name%22%3A%22none%22%7D%7D' ) );
	}

	function authenticate() {
		$client_id = apply_filters( 'github-releases-client_id', filter_input( INPUT_GET, 'client_id' ) );
		$client_secret = apply_filters( 'github-releases-client_secret', filter_input( INPUT_GET, 'client_secret' ) );

		$url = 'https://github.com/login/oauth/authorize?scope=user:email,repo&client_id=%s&redirect_uri=%s';
		$url = sprintf( $url, $client_id, admin_url( 'admin-ajax.php?action=github-releases-token' ) );
		header( 'Location: ' . $url );
		die;
	}

	function token() {
		$code = filter_input( INPUT_GET, 'code' );
		do_action( 'github-releases-token', $code );
		die;
	}

	function set_token( $token ) {
		$this->token = $token;
	}

	function api( $path, $method = 'GET', $download = false ) {
		$function = "wp_remote_$method";
		$args = array();

		// Authorization
		$args['headers'] = array(
			'Authorization' => 'token ' . $this->token,
			);

		if ( false === strpos( $path, 'http' ) ) {
			$url = implode( '/', array( self::URL, $path ) );
		} else {
			$url = $path;
		}

		$response = call_user_func( $function, $url, $args );

		if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
			do_action( 'github-releases-error', $response );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! $download ) {
			$body = json_decode( $body );
		}
		return $body;
	}

}

$GLOBALS['X_GitHub_Releases'] = new X_GitHub_Releases;
