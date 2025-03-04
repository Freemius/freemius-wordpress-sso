<?php
/**
 * Plugin Name: Freemius SSO (Single Sign-On)
 * Plugin URI:  https://freemius.com/
 * Description: SSO for Freemius powered shops.
 * Version:     1.1.0
 * Author:      Freemius
 * Author URI:  https://freemius.com
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FS_SSO {
	#region Properties

	/**
	 * @var number
	 */
	private $_store_id;
	/**
	 * @var number
	 */
	private $_developer_id;
	/**
	 * @var string
	 */
	private $_developer_secret_key;
	/**
	 * @var bool
	 */
	private $_use_localhost_api;

	#endregion

	#region Singleton

	/**
	 * @var FS_SSO
	 */
	private static $instance;

	/**
	 * @return FS_SSO
	 */
	static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	#endregion
	private function __construct() {
		add_filter( 'authenticate', array( &$this, 'authenticate' ), 30, 3 );

		// Clean up the stored access token upon logout.
		add_action( 'clear_auth_cookie', array( &$this, 'clear_access_token' ), 30, 3 );
	}

	/**
	 * @param number $store_id
	 * @param number $developer_id
	 * @param string $developer_secret_key
	 * @param bool   $use_localhost_api
	 */
	public function init(
		$store_id,
		$developer_id,
		$developer_secret_key,
		$use_localhost_api = false
	) {
		$this->_store_id             = $store_id;
		$this->_developer_id         = $developer_id;
		$this->_developer_secret_key = $developer_secret_key;
		$this->_use_localhost_api    = $use_localhost_api;
	}

	/**
	 * This logic assumes that if a user exists in WP, there's a matching user (based on email) in Freemius.
	 *
	 * @param WP_User|null|WP_Error $user
	 * @param string                $username Username or email.
	 * @param string                $password Plain text password.
	 *
	 * @return WP_User|null|WP_Error
	 */
	public function authenticate( $user, $username, $password ) {
		$is_login_by_email = strpos( $username, '@' );
		$wp_user_found     = ( $user instanceof WP_User );

		/**
		 * If there's no matching user in WP and the login is by a username and not an email address, there's no way for us to fetch an access token for the user.
		 */
		if ( ! $wp_user_found &&
		     ! $is_login_by_email
		) {
			return $user;
		}

		if (
			is_wp_error( $user ) &&
			! in_array( $user->get_error_code(), array(
				'authentication_failed',
				'invalid_email',
				'invalid_password',
				'incorrect_password',
			) )
		) {
			return $user;
		}

		$email = $is_login_by_email
			?
			$username
			:
			$user->user_email;

		/**
		 *
		 */
		$fs_user_token = null;
		$fs_user_id    = null;

		$fetch_access_token = true;
		if ( $wp_user_found ) {
			$fs_user_id = get_user_meta( $user->ID, 'fs_user_id', true );

			if ( is_numeric( $fs_user_id ) ) {
				$fs_user_token = get_user_meta( $user->ID, 'fs_token', true );

				if ( ! empty( $fs_user_token ) ) {
					// Validate access token didn't yet to expire.
					if ( $fs_user_token->expires > time() ) {
						// No need to get a new access token for now, we can use the cached token.
						$fetch_access_token = false;
					}
				}
			}
		}

		if ( $fetch_access_token ) {
			// Fetch user's info and access token from Freemius.
			$result = $this->fetch_user_access_token(
				$email,
				( $wp_user_found ? '' : $password )
			);

			if ( is_wp_error( $result ) ) {
				return $user;
			}

			$result = json_decode( $result['body'] );

			if ( isset( $result->error ) ) {
				if ( $wp_user_found ) {
					return $user;
				} else {
					return new WP_Error( $result->error->code, __( '<strong>ERROR</strong>: ' . $result->error->message ) );
				}
			}

			$fs_user       = $result->user_token->person;
			$fs_user_id    = $fs_user->id;
			$fs_user_token = $result->user_token->token;

			if ( ! $wp_user_found ) {
				// Check if there's a user with a matching email address.
				$user_by_email = get_user_by( 'email', $email );

				if ( is_object( $user_by_email ) ) {
					$user = $user_by_email;
				} else {
					/**
					 * No user in WP with a matching email address. Therefore, create the user.
					 */
					$username = strtolower( $fs_user->first . ( empty( $fs_user->last ) ? '' : '.' . $fs_user->last ) );

					if ( empty( $username ) ) {
						$username = substr( $fs_user->email, 0, strpos( $fs_user->email, '@' ) );
					}

					$username = $this->generate_unique_username( $username );

					$user_id = wp_create_user( $username, $password, $email );

					if ( is_wp_error( $user_id ) ) {
						return $user;
					}

					$user = get_user_by( 'ID', $user_id );

					do_action( 'fs_sso_after_user_creation', $user );
				}
			}

			/**
			 * Store the token and user ID locally.
			 */
			update_user_meta( $user->ID, 'fs_token', $fs_user_token );
			update_user_meta( $user->ID, 'fs_user_id', $fs_user_id );
		}

		if ( $user instanceof WP_User ) {
			$has_any_active_licenses = 'no';

			if ( $this->get_freemius_has_any_license( $user->ID ) ) {
				$has_any_licenses = 'yes';
			} else {
				$has_any_licenses = 'no';

				$result = $this->fetch_user_store_licenses( $fs_user_id, $fs_user_token->access );

				if ( ! is_wp_error( $result ) ) {
					$result = json_decode( $result['body'] );

					if (
						is_object( $result ) &&
						! isset( $result->error ) &&
						! empty( $result->licenses )
					) {
						$has_any_licenses = 'yes';

						/**
						 * Check if license is active to save an API call.
						 */
						$license = $result->licenses[0];
						if (
							false === $license->is_cancelled &&
							! $this->has_license_expired( $license )
						) {
							$has_any_active_licenses = 'yes';
						}
					}
				}

				update_user_meta( $user->ID, 'fs_has_licenses', $has_any_licenses );
			}

			if ( 'yes' !== $has_any_active_licenses && 'yes' === $has_any_licenses ) {
				$result = $this->fetch_user_store_licenses(
					$fs_user_id,
					$fs_user_token->access,
					'active'
				);

				if ( ! is_wp_error( $result ) ) {
					$result = json_decode( $result['body'] );

					if (
						is_object( $result ) &&
						! isset( $result->error ) &&
						! empty( $result->licenses )
					) {
						$has_any_active_licenses = 'yes';
					}
				}
			}

			update_user_meta( $user->ID, 'fs_has_active_licenses', $has_any_active_licenses );
		}

		do_action( 'fs_sso_after_successful_login', $user );

		return $user;
	}

	/**
	 * Clean up the stored user access token.
	 */
	public function clear_access_token( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		delete_user_meta( $user_id, 'fs_token' );
	}

	/**
	 * Get user's Freemius user ID from meta-entry.
	 *
	 * @return number
	 */
	public function get_freemius_user_id( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		return get_user_meta( $user_id, 'fs_user_id', true );
	}

	/**
	 * Get user's Freemius access token from meta-entry.
	 *
	 * @return object
	 */
	public function get_freemius_access_token( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		return get_user_meta( $user_id, 'fs_token', true );
	}

	/**
	 * Check if the user has any licenses, reading value from the meta-entry.
	 *
	 * @param int|null $user_id
	 *
	 * @return bool
	 */
	public function get_freemius_has_any_license( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		return ( 'yes' === get_user_meta( $user_id, 'fs_has_licenses', true ) );
	}

	/**
	 * Check if the user has active licenses, reading value from the meta-entry.
	 *
	 * @param int|null $user_id
	 *
	 * @return bool
	 */
	public function get_freemius_has_any_active_license( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		return ( 'yes' === get_user_meta( $user_id, 'fs_has_active_licenses', true ) );
	}

	/**
	 * Get user active licenses, reading value from the meta-entry.
	 *
	 * @param int|null $user_id
	 *
	 * @return array
	 */
	public function get_freemius_active_licenses( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$licenses = get_user_meta( $user_id, 'fs_active_licenses', true );

		if ( is_array( $licenses ) ) {
			return $licenses;
		}

		return null;
	}

	public function refresh_user_access_token( $user_id = null, $force = false ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$fetch_access_token = false;

		if ( $user_id > 0 ) {
			$fetch_access_token = true;

			if ( ! $force ) {
				$fs_user_token = get_user_meta( $user_id, 'fs_token', true );

				if ( ! empty( $fs_user_token ) ) {
					if ( $fs_user_token->expires > time() ) {
						$fetch_access_token = false;
					}
				}
			}

			if ( $fetch_access_token ) {
				$user = get_userdata( $user_id );

				if ( $user ) {
					$result = $this->fetch_user_access_token( $user->user_email );

					if ( ! is_wp_error( $result ) ) {
						$result = json_decode( $result['body'] );

						if ( isset( $result->error ) && $result->error->http == 401 ) {
							update_user_meta( $user_id, 'fs_error', $result->error );
							update_user_meta( $user_id, 'fs_token', (object) array(
								'access'  => '',
								'refresh' => '',
								'expires' => time() + DAY_IN_SECONDS,
							) );
						} else {
							if ( isset( $result->user_token ) ) {
								$fs_user       = $result->user_token->person;
								$fs_user_id    = $fs_user->id;
								$fs_user_token = $result->user_token->token;

								delete_user_meta( $user_id, 'fs_error' );
								update_user_meta( $user_id, 'fs_token', $fs_user_token );
								update_user_meta( $user_id, 'fs_user_id', $fs_user_id );
							}
						}
					}
				}
			}
		}

		return $fetch_access_token;
	}

	public function refresh_any_user_licenses( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$has_any_licenses = 'no';

		$fs_user_id    = $this->get_freemius_user_id( $user_id );
		$fs_user_token = $this->get_freemius_access_token( $user_id );

		$result = $this->fetch_user_store_licenses( $fs_user_id, $fs_user_token->access );

		if ( ! is_wp_error( $result ) ) {
			$result = json_decode( $result['body'] );

			if ( is_object( $result ) && ! isset( $result->error ) && ! empty( $result->licenses ) ) {
				$has_any_licenses = 'yes';
			}
		}

		update_user_meta( $user_id, 'fs_has_licenses', $has_any_licenses );
	}

	public function refresh_active_user_licenses( $user_id = null ) {
		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$fs_user_id    = $this->get_freemius_user_id( $user_id );
		$fs_user_token = $this->get_freemius_access_token( $user_id );

		$result = $this->fetch_user_store_licenses( $fs_user_id, $fs_user_token->access, 'active', PHP_INT_MAX );

		if ( ! is_wp_error( $result ) ) {
			$result = json_decode( $result['body'] );

			if ( is_object( $result ) && ! isset( $result->error ) && is_array( $result->licenses ) && ! empty( $result->licenses ) ) {
				update_user_meta( $user_id, 'fs_active_licenses', $result->licenses );
			}
		}
	}

	#region Helper Methods

	/**
	 * @param string $email
	 * @param string $password
	 *
	 * @return array|WP_Error
	 */
	private function fetch_user_access_token( $email, $password = '' ) {
		$api_root = $this->get_api_root();

		// Fetch user's info and access token from Freemius.
		return wp_remote_post(
			"{$api_root}/v1/users/login.json",
			array(
				'method'   => 'POST',
				'blocking' => true,
				'body'     => array(
					'email'                => $email,
					'password'             => $password,
					'store_id'             => $this->_store_id,
					'developer_id'         => $this->_developer_id,
					'developer_secret_key' => $this->_developer_secret_key,
				)
			)
		);
	}

	/**
	 * @param number $fs_user_id
	 * @param string $access_token
	 * @param string $type
	 * @param int    $count
	 *
	 * @return array|\WP_Error
	 */
	private function fetch_user_store_licenses( $fs_user_id, $access_token, $type = 'all', $count = 1 ) {
		$api_root = $this->get_api_root();

		// Fetch user's info and access token from Freemius.
		return wp_remote_post(
			"{$api_root}/v1/users/{$fs_user_id}/licenses.json?" . http_build_query( array(
				'count'         => $count,
				'store_id'      => $this->_store_id,
				'type'          => $type,
				'authorization' => "FSA {$fs_user_id}:$access_token",
			), '', '&', PHP_QUERY_RFC3986 ),
			array(
				'method'   => 'GET',
				'blocking' => true,
			)
		);
	}

	/**
	 * @return string
	 */
	private function get_api_root() {
		return $this->_use_localhost_api
			?
			'http://api.freemius-local.com:8080'
			:
			'https://fast-api.freemius.com';
	}

	/**
	 * @param string $base_username
	 *
	 * @return string
	 */
	private function generate_unique_username( $base_username ) {
		// Sanitize.
		$base_username = sanitize_user( $base_username );

		$numeric_suffix = 0;

		do {
			$username = ( 0 == $numeric_suffix )
				?
				$base_username
				:
				sprintf( '%s%s', $base_username, $numeric_suffix );

			$numeric_suffix ++;
		} while ( username_exists( $username ) );

		return $username;
	}

	/**
	 * @param object $license
	 *
	 * @return bool
	 */
	private function has_license_expired( $license ) {
		if ( is_null( $license->expiration ) ) {
			// Lifetime license.
			return false;
		}

		return ( time() >= $this->get_timestamp_from_datetime( $license->expiration ) );
	}

	/**
	 * @param string $datetime
	 *
	 * @return int
	 */
	private function get_timestamp_from_datetime( $datetime ) {
		$timezone = date_default_timezone_get();

		if ( 'UTC' !== $timezone ) {
			// Temporary change time zone.
			date_default_timezone_set( 'UTC' );
		}

		$datetime  = is_numeric( $datetime ) ? $datetime : strtotime( $datetime );
		$timestamp = date( 'Y-m-d H:i:s', $datetime );

		if ( 'UTC' !== $timezone ) {
			// Revert timezone.
			date_default_timezone_set( $timezone );
		}

		return $timestamp;
	}

	#endregion
}

FS_SSO::instance()->init(
	FS_SSO_STORE_ID,
	FS_SSO_DEVELOPER_ID,
	FS_SSO_DEVELOPER_SECRET_KEY
);
