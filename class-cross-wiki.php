<?php
namespace Family_Wiki;

class Cross_Wiki {
	const OPTION = 'family_wiki_cross_wiki_sites';

	public static function get_sites() {
		$sites = get_option( self::OPTION, array() );
		if ( empty( $sites ) || ! is_array( $sites ) ) {
			return array();
		}

		$return = array();
		foreach ( $sites as $key => $site ) {
			if ( is_string( $site ) ) {
				$site = array(
					'url' => $site,
				);
			} elseif ( is_object( $site ) ) {
				$site = get_object_vars( $site );
			}

			if ( ! is_array( $site ) ) {
				continue;
			}

			if ( empty( $site['url'] ) && is_string( $key ) ) {
				$site['url'] = $key;
			}

			$site['site_id'] = empty( $site['site_id'] ) ? 0 : absint( $site['site_id'] );
			if ( empty( $site['url'] ) && $site['site_id'] ) {
				$site['url'] = get_home_url( $site['site_id'] );
			}

			if ( empty( $site['url'] ) ) {
				continue;
			}

			$site['url'] = untrailingslashit( $site['url'] );
			if ( untrailingslashit( home_url() ) === $site['url'] ) {
				continue;
			}

			if ( empty( $site['label'] ) ) {
				$details       = $site['site_id'] ? get_blog_details( $site['site_id'] ) : false;
				$site['label'] = $details && ! empty( $details->blogname ) ? $details->blogname : preg_replace( '/^https?:\/\//', '', $site['url'] );
			}

			if ( empty( $site['slugs'] ) || ! is_array( $site['slugs'] ) ) {
				$site['slugs'] = array();
			}

			$return[] = $site;
		}

		return $return;
	}

	public static function get_remote_pages( $slug ) {
		$slug  = trim( strtolower( $slug ), '/' );
		$pages = array();

		foreach ( self::get_sites() as $site ) {
			$remote_slug = self::remote_slug_for( $slug, $site );
			$page        = self::get_remote_page_data( $site, $remote_slug );

			if ( ! $page && $remote_slug !== $slug ) {
				$page = self::get_remote_page_data( $site, $slug );
			}

			if ( $page ) {
				$pages[] = array(
					'url'   => $page['url'],
					'title' => $page['title'],
					'label' => $site['label'],
					'slug'  => $remote_slug,
				);
			}
		}

		return $pages;
	}

	public static function get_remote_page( $slug ) {
		$pages = self::get_remote_pages( $slug );
		if ( empty( $pages ) ) {
			return false;
		}

		return reset( $pages );
	}

	private static function remote_slug_for( $slug, $site ) {
		if ( isset( $site['slugs'][ $slug ] ) ) {
			return trim( strtolower( $site['slugs'][ $slug ] ), '/' );
		}

		return $slug;
	}

	private static function get_remote_page_data( $site, $slug ) {
		static $pages = array();

		$blog_id = empty( $site['site_id'] ) ? 0 : absint( $site['site_id'] );
		if ( ! $blog_id && ! empty( $site['url'] ) ) {
			$blog_id = self::get_blog_id_for_url( $site['url'] );
		}

		$cache_key = $blog_id . '|' . trim( strtolower( $slug ), '/' );
		if ( array_key_exists( $cache_key, $pages ) ) {
			return $pages[ $cache_key ];
		}

		if ( ! $blog_id ) {
			$pages[ $cache_key ] = false;
			return false;
		}

		switch_to_blog( $blog_id );

		// A cross-linked site that has turned itself private since being
		// configured here is not this visitor's to see.
		if ( Private_Site::is_private() ) {
			restore_current_blog();
			$pages[ $cache_key ] = false;
			return false;
		}

		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page || 'publish' !== $page->post_status ) {
			restore_current_blog();
			$pages[ $cache_key ] = false;
			return false;
		}

		$pages[ $cache_key ] = array(
			'url'   => get_permalink( $page ),
			'title' => get_the_title( $page ),
		);
		restore_current_blog();

		return $pages[ $cache_key ];
	}

	private static function get_blog_id_for_url( $site_url ) {
		static $blog_ids = array();

		$site_url = untrailingslashit( $site_url );
		if ( isset( $blog_ids[ $site_url ] ) ) {
			return $blog_ids[ $site_url ];
		}

		$blog_ids[ $site_url ] = 0;
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return 0;
		}

		foreach ( get_sites() as $site ) {
			$home = untrailingslashit( get_home_url( $site->blog_id ) );
			if ( $home === $site_url ) {
				$blog_ids[ $site_url ] = (int) $site->blog_id;
				break;
			}
		}

		return $blog_ids[ $site_url ];
	}
}
