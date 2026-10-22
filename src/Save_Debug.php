<?php
namespace Family_Wiki;

/** Temporary, site-scoped timing for saves; enabled by a short-lived transient. */
class Save_Debug {
	const TRANSIENT = 'family_wiki_save_debug';

	private $started;
	private $queries;
	private $request_id;
	private $wrapped = array();
	private $timings = array();
	private $http_started = array();
	private $rest_started = array();

	public static function maybe_start() {
		if ( ! get_transient( self::TRANSIENT ) || ! self::is_save_request() ) {
			return;
		}
		new self();
	}

	private static function is_save_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return false;
		}
		if ( isset( $_POST['familypedia_action'] ) && in_array( $_POST['familypedia_action'], array( Editor::ACTION, Wiki_Page::ACTION, Related_Page::ACTION ), true ) ) {
			return true;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';
		if ( preg_match( '#/wp/v2/(familypedia_person|familypedia_front)(/|$)#', (string) $path ) || preg_match( '#^/wp/v2/(familypedia_person|familypedia_front)(/|$)#', $route ) ) {
			return true;
		}
		$post_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		return $post_id && in_array( get_post_type( $post_id ), array( Person::POST_TYPE, Front_Page::POST_TYPE ), true );
	}

	public static function log_path() {
		return trailingslashit( sys_get_temp_dir() ) . 'family-wiki-save-' . get_current_blog_id() . '.log';
	}

	private function __construct() {
		global $wpdb;
		$this->started = microtime( true );
		$this->queries = $wpdb->num_queries;
		$this->request_id = wp_generate_password( 8, false, false );
		$this->write( 'request_start', array( 'startup_ms' => round( 1000 * ( $this->started - ( $_SERVER['REQUEST_TIME_FLOAT'] ?? $this->started ) ), 2 ) ) );
		add_action( 'plugins_loaded', array( $this, 'wrap_hooks' ), PHP_INT_MAX );
		add_action( 'init', array( $this, 'wrap_hooks' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( $this, 'wrap_hooks' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_start' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_end' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_redirect', array( $this, 'redirect' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', array( $this, 'http_start' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( $this, 'http_end' ), 10, 5 );
		register_shutdown_function( array( $this, 'finish' ) );
	}

	public function wrap_hooks() {
		global $wp_filter;
		$hooks = array( 'init', 'wp_loaded', 'transition_post_status', 'save_post', 'save_post_' . Person::POST_TYPE, 'save_post_' . Front_Page::POST_TYPE, 'post_updated', 'wp_insert_post', 'wp_after_insert_post', 'rest_after_insert_' . Person::POST_TYPE, 'rest_after_insert_' . Front_Page::POST_TYPE, 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'shutdown' );
		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $entry ) {
					$callback = $entry['function'];
					if ( isset( $this->wrapped[ $hook ][ $priority ][ $id ] ) || ( is_array( $callback ) && $callback[0] === $this ) ) {
						continue;
					}
					$this->wrapped[ $hook ][ $priority ][ $id ] = true;
					$name = self::callback_name( $callback );
					$wp_filter[ $hook ]->callbacks[ $priority ][ $id ]['function'] = function ( ...$args ) use ( $callback, $hook, $name ) {
						$start = microtime( true );
						$live = ! in_array( $hook, array( 'init', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ), true );
						if ( $live ) {
							$this->write(
								'callback_start',
								array(
									'hook'     => $hook,
									'callback' => $name,
								)
							);
						}
						try {
							return call_user_func_array( $callback, $args );
						} finally {
							$ms = round( ( microtime( true ) - $start ) * 1000, 2 );
							$key = $hook . ': ' . $name;
							if ( ! isset( $this->timings[ $key ] ) ) {
								$this->timings[ $key ] = array(
									'calls'    => 0,
									'total_ms' => 0,
									'max_ms'   => 0,
								);
							}
							++$this->timings[ $key ]['calls'];
							$this->timings[ $key ]['total_ms'] += $ms;
							$this->timings[ $key ]['max_ms'] = max( $ms, $this->timings[ $key ]['max_ms'] );
							if ( $live || $ms >= 50 ) {
								$this->write(
									'callback_end',
									array(
										'hook'     => $hook,
										'callback' => $name,
										'ms'       => $ms,
									)
								);
							}
						}
					};
				}
			}
		}
	}

	private static function callback_name( $callback ) {
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );
			return 'Closure@' . basename( $reflection->getFileName() ) . ':' . $reflection->getStartLine();
		}
		return is_string( $callback ) ? $callback : get_class( $callback ) . '::__invoke';
	}

	public function rest_start( $result, $server, $request ) {
		unset( $server );
		$route = $request->get_route();
		$this->rest_started[ spl_object_hash( $request ) ] = microtime( true );
		$this->write(
			'rest_start',
			array(
				'route'  => $route,
				'method' => $request->get_method(),
			)
		);
		return $result;
	}

	public function rest_end( $result, $server, $request ) {
		unset( $server );
		$key = spl_object_hash( $request );
		$this->write(
			'rest_end',
			array(
				'route'  => $request->get_route(),
				'status' => $result->get_status(),
				'ms'     => isset( $this->rest_started[ $key ] ) ? round( 1000 * ( microtime( true ) - $this->rest_started[ $key ] ), 2 ) : null,
			)
		);
		unset( $this->rest_started[ $key ] );
		return $result;
	}

	public function redirect( $location, $status ) {
		$this->write( 'redirect', array( 'status' => $status ) );
		return $location;
	}

	public function http_start( $result, $args, $url ) {
		if ( false === $result ) {
			$this->http_started[ md5( $url ) ] = microtime( true );
			$this->write(
				'http_start',
				array(
					'host'      => wp_parse_url( $url, PHP_URL_HOST ),
					'timeout_s' => $args['timeout'] ?? null,
				)
			);
		}
		return $result;
	}

	public function http_end( $response, $context, $transport, $args, $url ) {
		unset( $transport, $args );
		if ( 'response' !== $context ) {
			return;
		}
		$key = md5( $url );
		$this->write(
			'http_end',
			array(
				'host'   => wp_parse_url( $url, PHP_URL_HOST ),
				'ms'     => isset( $this->http_started[ $key ] ) ? round( 1000 * ( microtime( true ) - $this->http_started[ $key ] ), 2 ) : null,
				'status' => is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response ),
			)
		);
		unset( $this->http_started[ $key ] );
	}

	public function finish() {
		global $wpdb;
		$error = error_get_last();
		$fatal = $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ? array(
			'type' => $error['type'],
			'file' => basename( $error['file'] ),
			'line' => $error['line'],
		) : null;
		$this->write(
			'request_end',
			array(
				'ms'        => round( 1000 * ( microtime( true ) - $this->started ), 2 ),
				'queries'   => $wpdb->num_queries - $this->queries,
				'peak_mb'   => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'fatal'     => $fatal,
				'callbacks' => $this->timings,
			)
		);
	}

	private function write( $event, $data ) {
		$path = self::log_path();
		if ( ! file_exists( $path ) ) {
			$handle = fopen( $path, 'x' );
			if ( false === $handle ) {
				return;
			}
			chmod( $path, 0640 );
			fclose( $handle );
		}
		if ( ! is_link( $path ) ) {
			chmod( $path, 0640 );
		}
		$line = wp_json_encode(
			array_merge(
				array(
					'time'    => gmdate( 'c' ),
					'request' => $this->request_id,
					'event'   => $event,
				),
				$data
			)
		);
		error_log( $line . PHP_EOL, 3, $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicitly enabled temporary save diagnostics.
	}
}
