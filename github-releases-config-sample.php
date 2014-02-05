<?php

add_filter(
	'github-releases-repos',
	function( $repos ) {
		$repos['repo-name'] = 'repo-owner'; // << CHANGE THIS
		return $repos;
	}
); # DEBUG
add_filter(
	'github-releases-client_id',
	function() {
		return 'CLIENT_ID_HERE'; // << CHANGE THIS
	}
); # DEBUG
add_filter(
	'github-releases-client_secret',
	function() {
		return 'CLIENT_SECRET_HERE'; // << CHANGE THIS
	}
); # DEBUG
