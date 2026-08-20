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
		add_filter( 'preprocess_comment', array( $this, 'preprocess_comment' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ) );
	}

	public function blog_privacy_selector() {
		?>
		<br />
		<input id="blog-private" type="radio" name="blog_public" value="-1" <?php checked( get_option( 'blog_public' ), '-1' ); ?> />
		<label for="blog-private"><?php esc_html_e( 'Private, visible only to administrators, editors, and wiki users', 'family-wiki' ); ?></label>
		<?php
	}

	/**
	 * Whether the blog currently switched to (get_current_blog_id(), which
	 * on a multisite network a caller can move with switch_to_blog() before
	 * asking) is private to the current user.
	 *
	 * Cached per blog id, since Cross_Wiki asks this about more than one
	 * blog within a single request.
	 */
	public static function is_private() {
		static $is_private = array();

		$blog_id = get_current_blog_id();
		if ( isset( $is_private[ $blog_id ] ) ) {
			return $is_private[ $blog_id ];
		}

		if ( get_option( 'blog_public' ) >= 0 ) {
			$is_private[ $blog_id ] = false;
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$is_private[ $blog_id ] = false;
			return false;
		}

		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			$is_private[ $blog_id ] = false;
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			$is_private[ $blog_id ] = true;
			return true;
		}
		if ( ! $blog_id ) {
			$is_private[ $blog_id ] = true;
			return true;
		}

		$the_user = clone( $user );
		$the_user->for_site( $blog_id );
		if ( ! $the_user->has_cap( self::MINIMUM_CAPABILITY ) ) {
			$is_private[ $blog_id ] = true;
			return true;
		}

		$is_private[ $blog_id ] = false;
		return false;
	}

	public function xmlrpc_methods( $methods ) {
		if ( ! self::is_private() ) {
			return $methods;
		}

		return array();
	}

	public function parse_request() {
		if ( ! self::is_private() ) {
			return;
		}

		$full_request_url = sanitize_url( ( ( empty( $_SERVER['HTTPS'] ) || 'off' === $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

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
						<a href="<?php echo esc_url( wp_login_url( $_SERVER['REQUEST_URI'] ) ); ?>"><?php esc_html_e( 'Login' ); ?></a>
		</body>
		</html>
		<?php

		exit;
	}

	public function rest_dispatch_request( $dispatch_result, $request, $route ) {
		if ( null !== $dispatch_result ) {
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

		if ( self::is_private() ) {
			return new \WP_Error( 'private_site', __( 'This site is private.', 'family-wiki' ), array( 'status' => 403 ) );
		}

		return null;
	}

	/**
	 * Blocking a comment post is parse_request()'s job, and it exits on its
	 * own when the site is private. Otherwise this filter must hand
	 * $commentdata back untouched, so it cannot just hook parse_request()
	 * directly the way the other no-argument hooks above do.
	 */
	public function preprocess_comment( $commentdata ) {
		if ( self::is_private() ) {
			$this->parse_request();
		}

		return $commentdata;
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

	public function bloginfo( $value, $what ) {
		if ( ( 'name' === $what || 'title' === $what ) && self::is_private() ) {
			return __( 'This site is private.', 'family-wiki' );
		}

		return $value;
	}
	public function robots_txt() {
		return "User-agent: *\nDisallow: /\n";
	}
}
