=== Freemius SSO (Single Sign-On) ===
Contributors: freemius, svovaf
Plugin URI: https://freemius.com
License: MIT
License URI: https://opensource.org/licenses/mit
Tags: freemius, sso, single sign-on, users dashboard, members dashboard, customers dashboard, members area
Requires at least: 3.1
Tested up to: 5.2
Stable tag: 1.1.0

An easy solution for SSO allowing users logging into WordPress with their Freemius credentials. If a user logs in with their Freemius credentials and there was no matching user in WordPress, a new user with the same email address and password will be created in WordPress.

When embedding the User Dashboard (https://github.com/Freemius/freemius-users-dashboard), a logged in user will be automatically logged into their Freemius User Dashboard without the need to manually log in.

== Description ==


== Installation ==

1. Download the plugin.
2. Open the main plugin's file `freemius-wordpress-sso.php`, scroll to the bottom, and replace `<STORE_ID>`, `<DEVELOPER_ID>`, and `<DEVELOPER_SECRET_KEY>` with your details. You can get your _developer ID_ and _secret key_ in the **My Profile** page, and your _store ID_ in the **My Store** page. Both pages are accessible from the top-right menu.
3. Upload and activate the plugin.
4. Done! Users will be able to login with their Freemius credentials.

== Changelog ==

=1.1.0=
* Added integration with Freemius User Dashboard plugin.

=1.0.0=
* Initial release.
