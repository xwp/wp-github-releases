=== GitHub Releases ===
Contributors:      X-team, shadyvb
Tags:              github, updates, release, webhook
Requires at least: 3.6
Stable tag:        trunk
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Receive updates from a GitHub repo, cache zips of new releases

== Description ==

Receive updates from a GitHub repo, cache zips of new releases

== Changelog ==


== Installation ==

1. Create a new GitHub Application from: 
https://github.com/settings/applications/new

2. Fill github-releases-config-sample.php with the new application info, 
and either 
- copy it to mu-plugins
- copy the code within to your theme/plugin, and make sure it gets included

3. visit /wp-admin/admin-ajax.php?action=github-releases-authenticate so you can 
authenticate to your GitHub application you just did

4. Create a directory right beside DOCUMENT_ROOT named github-releases, as that's
where the plugin will save zip files ( or filter github-releases-directory for 
an alternative location )