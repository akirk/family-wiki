<?php
namespace Family_Wiki;

class Private_Site {
	const MINIMUM_CAPABILITY = 'edit_others_pages';

	public function __construct() {
		add_action( 'blog_privacy_selector', array( $this, 'blog_privacy_selector' ) );

		add_filter( 'xmlrpc_methods', array( $this, 'xmlrpc_methods' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ), 100 );
		add_filter( 'admin_init', array( $this, 'parse_request' ) );
		add_filter( 'rest_dispatch_request', array( $this, 'rest_dispatch_request' ), 10, 3 );
		add_action( 'opml_head', array( $this, 'opml_head' ) );
		add_filter( 'bloginfo', array( $this, 'bloginfo' ), 3, 2 );
		add_filter( 'preprocess_comment', array( $this, 'parse_request' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ) );

	}

	public function blog_privacy_selector() {
		?>
		<br />
		<input id="blog-private" type="radio" name="blog_public" value="-1" <?php checked( get_option( 'blog_public' ), '-1' ); ?> />
		<label for="blog-private"><?php esc_html_e( 'Private, visible only to administrators, editors, and wiki users', 'family-wiki' ); ?></label>
		<?php
	}

	private function is_private() {
		static $is_private;

		if ( isset( $is_private ) ) {
			return $is_private;
		}

		if ( get_option( 'blog_public' ) >= 0 ) {
			$is_private = false;
			return $is_private;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$is_private = false;
			return $is_private;
		}

		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			$is_private = false;
			return $is_private;
		}

		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			$is_private = true;
			return $is_private;
		}
		$blog_id = get_current_blog_id();
		if ( ! $blog_id ) {
			$is_private = true;
			return $is_private;
		}

		$the_user = clone( $user );
		$the_user->for_site( $blog_id );
		if ( ! $the_user->has_cap( self::MINIMUM_CAPABILITY ) ) {
			$is_private = true;
			return $is_private;
		}

		$is_private = false;
		return $is_private;
	}

	public function xmlrpc_methods( $methods ) {
		if ( ! $this->is_private() ) {
			return $methods;
		}

		return array();
	}

	public function parse_request() {
		if ( ! $this->is_private() ) {
			return;
		}

		$full_request_url = sanitize_url( ( ( empty( $_SERVER['HTTPS'] ) || $_SERVER['HTTPS'] === 'off' ) ? 'http' : 'https' ) . '://'. $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		if ( untrailingslashit( $full_request_url ) === site_url( '/robots.txt' ) ) {
			do_action( 'do_robots' );
			exit;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			wp_send_json_error(
				array(
					'code'    => 'private_site',
					'message' => __(
						'This site is private.',
						'family-wiki'
					),
				)
			);
		}

		?>
		<html>
		<head>
		<title><?php esc_html_e( 'This site is private.', 'family-wiki' ); ?></title>
		</head>
		<body>
			<?php esc_html_e( 'This site is private.', 'family-wiki' ); ?>
		</body>
		</html>
		<?php

		exit;
	}

	public function rest_dispatch_request( $dispatch_result, $request, $route ) {
		if ( $dispatch_result !== null ) {
			return $dispatch_result;
		}
		$allowed_routes = array(
			'2fa/', // https://wordpress.org/plugins/application-passwords/
			'jwt-auth/', // https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/
			'oauth1/', // https://wordpress.org/plugins/rest-api-oauth1/
		);

		if ( preg_match( '#^/(2fa|jwt-auth)/#', $route ) ) {
			return null;
		}

		if ( get_option( 'blog_public' ) === -1 ) {
			return new \WP_Error( 'private_site', __( 'This site is private.', 'family-wiki' ), array( 'status' => 403 ) );
		}

		return null;
	}

	public function opml_head() {
		status_header( 403 );
		?>
		<error><?php esc_html_e( 'This site is private.', 'family-wiki' ); ?></error>
	</head>
</opml>
		<?php
		exit;
	}

	function bloginfo($value, $what ) {
		if ( ( $what === 'name' || $what === 'title' ) && $this->is_private() ) {
			return __( 'This site is private.', 'family-wiki' );
		}

		return $value;
	}
	function robots_txt() {
		return "User-agent: *nDisallow: /n";
	}
}
