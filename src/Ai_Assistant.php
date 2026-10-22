<?php
/**
 * WordPress Abilities API integration, so AI Assistant and other Ability API
 * clients can browse and edit the family wiki without falling back to raw
 * database access.
 *
 * Person meta is deliberately kept out of REST (see Person::register_meta()):
 * this is private data about a family, often living people. Abilities are a
 * separate, permissioned channel that mirrors what a logged-in wiki member
 * already sees in the app itself, gated by the same capabilities the app's
 * own forms use.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Ai_Assistant {
	const CATEGORY = 'family-wiki';

	public function __construct() {
		if ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_categories_init' ) ) {
			$this->register_ability_category();
		} else {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
		}

		if ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_init' ) ) {
			$this->register_abilities();
		} else {
			add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		}

		add_filter( 'ai_assistant_ability_domains', array( $this, 'ai_assistant_ability_domains' ) );
		add_filter( 'ai_assistant_ability_instructions', array( $this, 'ai_assistant_ability_instructions' ), 10, 4 );
		add_filter( 'ai_assistant_welcome_tips', array( $this, 'ai_assistant_welcome_tips' ), 10, 2 );
	}

	/**
	 * Register domain terms so AI Assistant reaches for these abilities.
	 *
	 * @param array $domains Existing ability domains.
	 * @return array Updated ability domains.
	 */
	public function ai_assistant_ability_domains( $domains ) {
		if ( ! is_array( $domains ) ) {
			$domains = array();
		}

		$domains[ self::CATEGORY ] = 'family wiki, family tree, genealogy, ancestors, descendants, relatives, siblings, parents, children, spouse, marriage, family history, birthdays, family calendar, wiki pages';

		return $domains;
	}

	/**
	 * Provide result-specific instructions after ability execution.
	 *
	 * @param string $instructions Current instructions.
	 * @param string $ability_id   Ability ID.
	 * @param array  $args         Ability arguments.
	 * @param mixed  $result       Ability result.
	 * @return string Instructions for AI Assistant.
	 */
	public function ai_assistant_ability_instructions( $instructions, $ability_id, $args, $result ) {
		switch ( $ability_id ) {
			case 'familypedia/list-people':
				return __( 'Link each person to their url. Use familypedia/get-person for full details, and pass its id back to other abilities rather than a typed name.', 'family-wiki' );

			case 'familypedia/get-person':
				return __( 'Present the bio line, then dates and places. Link relatives to their url when present; a relative with no id is only recorded by name and has no page yet.', 'family-wiki' );

			case 'familypedia/create-person':
			case 'familypedia/update-person':
				return __( 'Report unmatched_names if not empty: those children were not linked to a person because no name matched, and were left off. Link to the person url when confirming.', 'family-wiki' );

			case 'familypedia/list-pages':
				return __( 'These are content pages such as chronologies or houses, not people. Use familypedia/create-page to add one.', 'family-wiki' );

			case 'familypedia/get-family-tree':
				return __( 'tree_html is ready-to-display markup for a descendant outline; do not try to re-derive it from parents/children/partners.', 'family-wiki' );

			case 'familypedia/list-upcoming-events':
				return __( 'occurs_on is the next calendar date the event falls on; original_date is the year it actually happened. Group by month when presenting more than a few.', 'family-wiki' );
		}

		return $instructions;
	}

	/**
	 * Contextual starting points for the app's own welcome message.
	 *
	 * @param array $tips    Existing welcome tips.
	 * @param array $context Route context.
	 * @return array Updated welcome tips.
	 */
	public function ai_assistant_welcome_tips( $tips, $context ) {
		unset( $context );

		if ( ! is_array( $tips ) ) {
			$tips = array();
		}

		$tips[ App::URL_PATH ] = array(
			__( 'Ask me to find a relative, show how two people are related, or look up someone\'s parents, children, or siblings.', 'family-wiki' ),
			__( 'Ask me to show the family tree from someone, or what birthdays and anniversaries are coming up.', 'family-wiki' ),
		);

		return $tips;
	}

	/**
	 * Register the Familypedia ability category.
	 */
	public function register_ability_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Familypedia', 'family-wiki' ),
				'description' => __( 'Read and manage family wiki people, related pages, and the family tree.', 'family-wiki' ),
			)
		);
	}

	/**
	 * Register Familypedia abilities.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_ability(
			'familypedia/list-people',
			array(
				'label'               => __( 'List People', 'family-wiki' ),
				'description'         => __( 'Lists people on the family wiki with id, name, sex, alive status, birth and death dates and places, and their profile url. Supports a text search and filtering by alive status.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against name and page text.', 'family-wiki' ),
						),
						'alive'  => array(
							'type'        => 'boolean',
							'description' => __( 'When present, only return people recorded as alive (true) or not alive (false).', 'family-wiki' ),
						),
						'limit'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 50,
							'description' => __( 'Maximum number of people to return.', 'family-wiki' ),
						),
						'offset' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => __( 'Number of matching people to skip.', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'people' => array(
							'type'  => 'array',
							'items' => self::person_summary_schema(),
						),
						'total'  => array( 'type' => 'integer' ),
						'limit'  => array( 'type' => 'integer' ),
						'offset' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_list_people' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => self::ability_meta(
					true,
					false,
					true,
					__( 'Use this first when a person is only known by name, to get their id. Wiki pages hung under a person (chronologies, houses) are not returned here; use familypedia/list-pages.', 'family-wiki' )
				),
			)
		);

		$this->register_ability(
			'familypedia/get-person',
			array(
				'label'               => __( 'Get Person', 'family-wiki' ),
				'description'         => __( 'Returns full details for one person: recorded facts, a one-line biography, parents, siblings, children, spouses (in marriages), and any related pages hung under them.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'person_id' ),
					'properties'           => array(
						'person_id' => array(
							'type'        => 'integer',
							'description' => __( 'Person id from familypedia/list-people.', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'person' => self::person_detail_schema(),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_person' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => self::ability_meta(
					true,
					false,
					true,
					__( 'Use familypedia/list-people first if you only have a name. Spouses are recorded in marriages: use familypedia/update-person with marriages to add a husband or wife, preserving existing entries. Wedding details are optional. Use familypedia/get-family-tree for a multi-generation view.', 'family-wiki' )
				),
			)
		);

		$this->register_ability(
			'familypedia/create-person',
			array(
				'label'               => __( 'Create Person', 'family-wiki' ),
				'description'         => __( 'Creates a new person on the family wiki with a name and, optionally, their recorded facts, parents, children, and spouses. Record a husband or wife in marriages; wedding dates and places are optional. For unmarried partners use marriages with relationship_type partnership.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::person_facts_input_schema( true ),
				'output_schema'       => self::person_write_output_schema(),
				'execute_callback'    => array( $this, 'ability_create_person' ),
				'permission_callback' => array( $this, 'can_create_person' ),
				'meta'                => self::ability_meta(
					false,
					false,
					false,
					__( 'father, mother, children entries, and a marriage spouse may be an existing person id or a plain name; a name that matches nobody is kept as text where the field allows it (father, mother, spouse), or reported in unmatched_names (children).', 'family-wiki' )
				),
			)
		);

		$this->register_ability(
			'familypedia/update-person',
			array(
				'label'               => __( 'Update Person', 'family-wiki' ),
				'description'         => __( "Updates an existing person's recorded facts, parents, children, and spouses. To record a husband or wife, use marriages with a spouse entry; wedding details are optional. For unmarried partners use marriages with relationship_type partnership. Only the fields provided are changed; children and marriages, when provided, replace the full list rather than appending to it.", 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::person_facts_input_schema( false ),
				'output_schema'       => self::person_write_output_schema(),
				'execute_callback'    => array( $this, 'ability_update_person' ),
				'permission_callback' => array( $this, 'can_update_person' ),
				'meta'                => self::ability_meta(
					false,
					false,
					true,
					__( 'Use familypedia/get-person first to see the current children or marriages before replacing them, unless the user is deliberately clearing a list.', 'family-wiki' )
				),
			)
		);

		$this->register_ability(
			'familypedia/list-pages',
			array(
				'label'               => __( 'List Wiki Pages', 'family-wiki' ),
				'description'         => __( 'Lists content pages that are not people in their own right: standalone pages, and pages hung under a person such as a chronology or a house.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'parent_id' => array(
							'type'        => 'integer',
							'description' => __( 'Restrict to pages under this person id. Pass 0 for standalone pages only. Omit to return all such pages.', 'family-wiki' ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 50,
							'description' => __( 'Maximum number of pages to return.', 'family-wiki' ),
						),
						'offset'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => __( 'Number of matching pages to skip.', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pages' => array(
							'type'  => 'array',
							'items' => self::page_schema(),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_list_pages' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => self::ability_meta( true, false, true, '' ),
			)
		);

		$this->register_ability(
			'familypedia/create-page',
			array(
				'label'               => __( 'Create Wiki Page', 'family-wiki' ),
				'description'         => __( 'Creates a content page with a title, either standalone or hung under a person. The page starts empty; its text is written afterward in the block editor.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'title' ),
					'properties'           => array(
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'Page title.', 'family-wiki' ),
						),
						'parent_id' => array(
							'type'        => 'integer',
							'description' => __( 'Person id to hang this page under. Omit for a standalone page.', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page' => self::page_schema(),
					),
				),
				'execute_callback'    => array( $this, 'ability_create_page' ),
				'permission_callback' => array( $this, 'can_create_page' ),
				'meta'                => self::ability_meta(
					false,
					false,
					false,
					__( 'Use edit_url to point the user at the block editor to write the page text; this ability only creates the title.', 'family-wiki' )
				),
			)
		);

		$this->register_ability(
			'familypedia/get-family-tree',
			array(
				'label'               => __( 'Get Family Tree', 'family-wiki' ),
				'description'         => __( "Returns a descendant tree outline starting from one person, as ready-to-display html, along with that person's immediate parents, children, and partners.", 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'person_id' ),
					'properties'           => array(
						'person_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Person id to root the tree at.', 'family-wiki' ),
						),
						'max_depth'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 10,
							'default'     => 3,
							'description' => __( 'Generations of descendants to expand. 0 means no limit.', 'family-wiki' ),
						),
						'show_dates' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether to include birth/death years next to each name.', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'person'    => self::person_summary_schema(),
						'tree_html' => array( 'type' => 'string' ),
						'parents'   => array(
							'type'  => 'array',
							'items' => self::person_ref_schema(),
						),
						'children'  => array(
							'type'  => 'array',
							'items' => self::person_ref_schema(),
						),
						'partners'  => array(
							'type'  => 'array',
							'items' => self::person_ref_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'ability_get_family_tree' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => self::ability_meta( true, false, true, '' ),
			)
		);

		$this->register_ability(
			'familypedia/list-upcoming-events',
			array(
				'label'               => __( 'List Upcoming Family Events', 'family-wiki' ),
				'description'         => __( 'Lists upcoming birthdays, marriage anniversaries, and death anniversaries within a day range, soonest first.', 'family-wiki' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'days'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 365,
							'default'     => 30,
							'description' => __( 'How many days ahead to look.', 'family-wiki' ),
						),
						'types' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'born', 'died', 'married' ),
							),
							'description' => __( 'Event types to include. Defaults to all three: born (birthdays), died (death anniversaries), married (marriage anniversaries).', 'family-wiki' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'events' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'type'        => array( 'type' => 'string' ),
									'person_id'   => array( 'type' => 'integer' ),
									'person_name' => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'occurs_on'   => array( 'type' => 'string' ),
									'url'         => array( 'type' => 'string' ),
								),
							),
						),
						'total'  => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_list_upcoming_events' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => self::ability_meta( true, false, true, '' ),
			)
		);
	}

	/**
	 * Whether the current user may read family wiki data through abilities.
	 * Matches the capability the app's own views already gate on.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'edit_others_pages' );
	}

	/**
	 * Whether the current user may create a new person.
	 *
	 * @return bool
	 */
	public function can_create_person() {
		return Editor::can_create();
	}

	/**
	 * Whether the current user may update the targeted person. Falls back to
	 * the general read capability when no id was supplied, so a bad or missing
	 * id surfaces as an execute-time error rather than a permission failure.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function can_update_person( $input ) {
		$person_id = is_array( $input ) && ! empty( $input['person_id'] ) ? absint( $input['person_id'] ) : 0;

		return $person_id ? Editor::can_edit( $person_id ) : $this->can_read();
	}

	/**
	 * Whether the current user may create the targeted page.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function can_create_page( $input ) {
		$parent_id = is_array( $input ) && ! empty( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;

		return Wiki_Page::can_create( $parent_id );
	}

	/**
	 * List people.
	 *
	 * @param array $input Ability input.
	 * @return array Ability result.
	 */
	public function ability_list_people( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$limit  = $this->clamp_int( $input['limit'] ?? 50, 1, 100 );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$args = array(
			'post_type'      => Person::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$alive_filter = array_key_exists( 'alive', $input ) ? $this->input_bool( $input, 'alive', true ) : null;

		$people = array();
		foreach ( get_posts( $args ) as $post ) {
			if ( Wiki_Page::is_page( $post ) ) {
				continue;
			}
			if ( null !== $alive_filter && (bool) Person::field( 'alive', $post->ID ) !== $alive_filter ) {
				continue;
			}
			$people[] = $post;
		}

		$total = count( $people );
		$page  = array_slice( $people, $offset, $limit );

		return array(
			'people' => array_map( array( $this, 'prepare_person_summary' ), $page ),
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Get one person.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_get_person( $input ) {
		$input = is_array( $input ) ? $input : array();
		$post  = $this->get_person( $input['person_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'person' => $this->prepare_person_data( $post ),
		);
	}

	/**
	 * Create a person.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_create_person( $input ) {
		$input = is_array( $input ) ? $input : array();
		$title = isset( $input['title'] ) ? $this->sanitize_label( $input['title'] ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'missing-title', __( 'A person needs a name.', 'family-wiki' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Person::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$unmatched = $this->apply_person_fields( $post_id, $input );
		Main::flush_family_data_cache( $post_id );

		return array(
			'person'          => $this->prepare_person_data( get_post( $post_id ) ),
			'unmatched_names' => $unmatched,
		);
	}

	/**
	 * Update a person.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_update_person( $input ) {
		$input = is_array( $input ) ? $input : array();
		$post  = $this->get_person( $input['person_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( array_key_exists( 'title', $input ) ) {
			$title = $this->sanitize_label( $input['title'] );
			if ( '' === $title ) {
				return new \WP_Error( 'invalid-title', __( 'A person needs a name.', 'family-wiki' ) );
			}
			$updated = wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $title,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$unmatched = $this->apply_person_fields( $post->ID, $input );
		Main::flush_family_data_cache( $post->ID );

		return array(
			'person'          => $this->prepare_person_data( get_post( $post->ID ) ),
			'unmatched_names' => $unmatched,
		);
	}

	/**
	 * List content pages.
	 *
	 * @param array $input Ability input.
	 * @return array Ability result.
	 */
	public function ability_list_pages( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$limit  = $this->clamp_int( $input['limit'] ?? 50, 1, 100 );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$has_parent_filter = array_key_exists( 'parent_id', $input );
		$parent_filter      = $has_parent_filter ? absint( $input['parent_id'] ) : null;

		$pages = array();
		foreach ( Person::get_all() as $post ) {
			if ( ! Wiki_Page::is_page( $post ) ) {
				continue;
			}
			if ( null !== $parent_filter && (int) $post->post_parent !== $parent_filter ) {
				continue;
			}
			$pages[] = $post;
		}

		$total = count( $pages );
		$page  = array_slice( $pages, $offset, $limit );

		return array(
			'pages' => array_map( array( $this, 'prepare_page_data' ), $page ),
			'total' => $total,
		);
	}

	/**
	 * Create a content page.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_create_page( $input ) {
		$input = is_array( $input ) ? $input : array();
		$title = isset( $input['title'] ) ? $this->sanitize_label( $input['title'] ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'missing-title', __( 'A page needs a title.', 'family-wiki' ) );
		}

		$parent_id = 0;
		if ( ! empty( $input['parent_id'] ) ) {
			$parent_id = absint( $input['parent_id'] );
			$parent    = get_post( $parent_id );
			if ( ! $parent || Person::POST_TYPE !== $parent->post_type ) {
				return new \WP_Error( 'invalid-parent', __( 'That parent could not be found.', 'family-wiki' ) );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Person::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_parent' => $parent_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, Wiki_Page::META_KEY, 1 );
		Main::flush_family_data_cache( $parent_id ? $parent_id : $post_id );

		return array(
			'page' => $this->prepare_page_data( get_post( $post_id ) ),
		);
	}

	/**
	 * Get the descendant tree for one person.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_get_family_tree( $input ) {
		$input = is_array( $input ) ? $input : array();
		$post  = $this->get_person( $input['person_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$people = Tree::get_people();
		if ( ! isset( $people[ $post->ID ] ) ) {
			return new \WP_Error( 'no-tree-data', __( 'This person has no recorded family relationships to build a tree from.', 'family-wiki' ) );
		}

		$max_depth  = $this->clamp_int( $input['max_depth'] ?? 3, 0, 10 );
		$show_dates = $this->input_bool( $input, 'show_dates', true );

		Tree::reset_expanded();
		$tree_html = Tree::render_list(
			$post->ID,
			array(
				'max_depth'    => $max_depth,
				'expand_fully' => false,
				'show_dates'   => $show_dates,
			)
		);

		$node = $people[ $post->ID ];

		return array(
			'person'    => $this->prepare_person_summary( $post ),
			'tree_html' => $tree_html,
			'parents'   => array_map( array( $this, 'person_ref' ), $node['parents'] ),
			'children'  => array_map( array( $this, 'person_ref' ), $node['children'] ),
			'partners'  => array_map(
				function ( $partner ) {
					return $partner['id']
						? $this->person_ref( $partner['id'] )
						: array(
							'id'   => 0,
							'name' => $partner['name'],
							'url'  => '',
						);
				},
				$node['partners']
			),
		);
	}

	/**
	 * List upcoming birthdays and anniversaries.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Ability result or error.
	 */
	public function ability_list_upcoming_events( $input ) {
		if ( ! Calendar::is_calendar_enabled() && ! Calendar::is_birthdays_enabled() ) {
			return new \WP_Error( 'feature-disabled', __( 'The family calendar and birthdays views are both turned off in Settings.', 'family-wiki' ) );
		}

		$input = is_array( $input ) ? $input : array();
		$days  = $this->clamp_int( $input['days'] ?? 30, 1, 365 );
		$types = ! empty( $input['types'] ) && is_array( $input['types'] )
			? array_intersect( array( 'born', 'died', 'married' ), $input['types'] )
			: array( 'born', 'died', 'married' );

		$events = array();
		foreach ( Calendar::get()->get_upcoming_events( $days, $types ) as $event ) {
			$events[] = array(
				'type'        => $event['type'],
				'person_id'   => $event['person_id'],
				'person_name' => get_the_title( $event['person_id'] ),
				'label'       => $event['label'],
				'occurs_on'   => $event['occurs_on'],
				'url'         => Person::url( $event['person_id'] ),
			);
		}

		return array(
			'events' => $events,
			'total'  => count( $events ),
		);
	}

	/**
	 * Register a single ability if it is not already registered.
	 *
	 * @param string $ability_id Ability ID.
	 * @param array  $args       Ability arguments.
	 */
	private function register_ability( $ability_id, $args ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_id ) ) {
			return;
		}

		wp_register_ability( $ability_id, $args );
	}

	/**
	 * Look up a person, rejecting content pages that are not people.
	 *
	 * @param int $person_id Person post ID.
	 * @return \WP_Post|\WP_Error Person post or error.
	 */
	private function get_person( $person_id ) {
		$person_id = absint( $person_id );
		if ( ! $person_id ) {
			return new \WP_Error( 'missing-person-id', __( 'A person id is required.', 'family-wiki' ) );
		}

		$post = get_post( $person_id );
		if ( ! $post || Person::POST_TYPE !== $post->post_type || Wiki_Page::is_page( $post ) ) {
			return new \WP_Error( 'invalid-person', __( 'Invalid person.', 'family-wiki' ) );
		}

		return $post;
	}

	/**
	 * Write the shared set of person facts and relationships from ability
	 * input, matching the field-by-field behaviour of the app's own edit form.
	 *
	 * @param int   $post_id The person.
	 * @param array $input   Ability input.
	 * @return string[] Names in children that matched nobody and were left off.
	 */
	private function apply_person_fields( $post_id, array $input ) {
		$unmatched = array();

		foreach ( array( 'born_as', 'birth_place', 'death_place', 'citizenships' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				Person::update( $field, $input[ $field ], $post_id );
			}
		}

		if ( array_key_exists( 'sex', $input ) ) {
			Person::update( 'sex', $input['sex'], $post_id );
		}

		if ( array_key_exists( 'alive', $input ) ) {
			Person::update( 'alive', $this->input_bool( $input, 'alive', true ), $post_id );
		}

		foreach ( array( 'birth', 'death' ) as $event ) {
			if ( array_key_exists( $event . '_date', $input ) ) {
				Person::update( $event . '_date', $input[ $event . '_date' ], $post_id );
			}
			$unknown_field = 'exact_' . $event . '_date_unknown';
			if ( array_key_exists( $unknown_field, $input ) ) {
				Person::update( $unknown_field, $this->input_bool( $input, $unknown_field, false ), $post_id );
			}
		}

		foreach ( array( 'father', 'mother' ) as $parent ) {
			if ( ! array_key_exists( $parent, $input ) ) {
				continue;
			}
			$entered = trim( (string) $input[ $parent ] );
			$related = $this->resolve_person_input( $entered, $post_id );
			Person::update( $parent, $related, $post_id );
			Person::update( $parent . '_name', $related ? '' : $entered, $post_id );
		}

		if ( array_key_exists( 'children', $input ) && is_array( $input['children'] ) ) {
			$children = array();
			foreach ( $input['children'] as $entered ) {
				$child = $this->resolve_person_input( $entered, $post_id );
				if ( $child ) {
					$children[] = $child;
				} else {
					$unmatched[] = trim( (string) $entered );
				}
			}
			Person::update( 'children', $children, $post_id );
		}

		if ( array_key_exists( 'marriages', $input ) && is_array( $input['marriages'] ) ) {
			$marriages = array();
			foreach ( $input['marriages'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$spouse_entered = isset( $row['spouse'] ) ? trim( (string) $row['spouse'] ) : '';
				$spouse         = $spouse_entered ? $this->resolve_person_input( $spouse_entered, $post_id ) : 0;

				$marriages[] = array(
					'spouse'            => $spouse,
					'relationship_type' => isset( $row['relationship_type'] ) ? $row['relationship_type'] : 'marriage',
					'spouse_name'       => $spouse ? '' : $spouse_entered,
					'marriage_date'     => isset( $row['marriage_date'] ) ? $row['marriage_date'] : '',
					'marriage_year'     => isset( $row['marriage_year'] ) ? $row['marriage_year'] : '',
					'marriage_place'    => isset( $row['marriage_place'] ) ? $row['marriage_place'] : '',
					'ended_date'        => isset( $row['ended_date'] ) ? $row['ended_date'] : '',
					'ended_year'        => isset( $row['ended_year'] ) ? $row['ended_year'] : '',
					'ended_reason'      => isset( $row['ended_reason'] ) ? $row['ended_reason'] : '',
				);
			}
			Person::update( 'marriages', $marriages, $post_id );
		}

		return array_values( array_unique( array_filter( $unmatched ) ) );
	}

	/**
	 * Resolve a person reference from ability input: an existing person id, or
	 * a name matched the way the app's own edit form matches one.
	 *
	 * @param string $value   The entered id or name.
	 * @param int    $exclude A person who may not be their own relative.
	 * @return int Person post ID, or 0 when nothing matched.
	 */
	private function resolve_person_input( $value, $exclude = 0 ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			if ( $id && $id !== (int) $exclude && Person::to_post( $id ) ) {
				return $id;
			}
		}

		return Editor::resolve_person( $value, $exclude );
	}

	/**
	 * Prepare a short person reference for output.
	 *
	 * @param int|\WP_Post $person Person post or ID.
	 * @return array Person reference.
	 */
	private function person_ref( $person ) {
		$post = get_post( $person );
		if ( ! $post ) {
			return array(
				'id'   => 0,
				'name' => '',
				'url'  => '',
			);
		}

		return array(
			'id'   => (int) $post->ID,
			'name' => get_the_title( $post ),
			'url'  => Person::url( $post ),
		);
	}

	/**
	 * Prepare a marriage row for output.
	 *
	 * @param array $marriage Stored marriage row.
	 * @return array Marriage data.
	 */
	private function prepare_marriage_data( $marriage ) {
		return array(
			'spouse'            => ! empty( $marriage['spouse'] ) ? $this->person_ref( $marriage['spouse'] ) : null,
			'relationship_type' => isset( $marriage['relationship_type'] ) ? $marriage['relationship_type'] : 'marriage',
			'spouse_name'       => isset( $marriage['spouse_name'] ) ? (string) $marriage['spouse_name'] : '',
			'marriage_date'     => isset( $marriage['marriage_date'] ) ? (string) $marriage['marriage_date'] : '',
			'marriage_year'     => isset( $marriage['marriage_year'] ) ? (string) $marriage['marriage_year'] : '',
			'marriage_place'    => isset( $marriage['marriage_place'] ) ? (string) $marriage['marriage_place'] : '',
			'ended_date'        => isset( $marriage['ended_date'] ) ? (string) $marriage['ended_date'] : '',
			'ended_year'        => isset( $marriage['ended_year'] ) ? (string) $marriage['ended_year'] : '',
			'ended_reason'      => isset( $marriage['ended_reason'] ) ? (string) $marriage['ended_reason'] : '',
		);
	}

	/**
	 * Prepare a short person summary for output.
	 *
	 * @param \WP_Post $post Person post.
	 * @return array Person summary.
	 */
	private function prepare_person_summary( \WP_Post $post ) {
		return array(
			'id'          => (int) $post->ID,
			'title'       => get_the_title( $post ),
			'url'         => Person::url( $post ),
			'edit_url'    => Person::edit_url( $post ),
			'sex'         => (string) Person::field( 'sex', $post->ID ),
			'alive'       => (bool) Person::field( 'alive', $post->ID ),
			'birth_date'  => (string) Person::field( 'birth_date', $post->ID ),
			'birth_place' => (string) Person::field( 'birth_place', $post->ID ),
			'death_date'  => (string) Person::field( 'death_date', $post->ID ),
			'death_place' => (string) Person::field( 'death_place', $post->ID ),
		);
	}

	/**
	 * Prepare full person data for output.
	 *
	 * @param \WP_Post $post Person post.
	 * @return array Person data.
	 */
	private function prepare_person_data( \WP_Post $post ) {
		$data = $this->prepare_person_summary( $post );

		$father = Person::field( 'father', $post->ID );
		$mother = Person::field( 'mother', $post->ID );

		$data['born_as']                  = (string) Person::field( 'born_as', $post->ID );
		$data['citizenships']             = (string) Person::field( 'citizenships', $post->ID );
		$data['exact_birth_date_unknown'] = (bool) Person::field( 'exact_birth_date_unknown', $post->ID );
		$data['exact_death_date_unknown'] = (bool) Person::field( 'exact_death_date_unknown', $post->ID );
		$data['bio']                      = wp_strip_all_tags( Bio::render( $post->ID ) );
		$data['father']                   = $father ? $this->person_ref( $father ) : null;
		$data['father_name']              = (string) Person::field( 'father_name', $post->ID );
		$data['mother']                   = $mother ? $this->person_ref( $mother ) : null;
		$data['mother_name']              = (string) Person::field( 'mother_name', $post->ID );
		$data['siblings']                 = array_map( array( $this, 'person_ref' ), Relationships::siblings( $post->ID, false ) );
		$data['half_siblings']            = array_map( array( $this, 'person_ref' ), Relationships::siblings( $post->ID, true ) );
		$data['children']                 = array_map( array( $this, 'person_ref' ), Person::field( 'children', $post->ID ) );
		$data['marriages']                = array_map( array( $this, 'prepare_marriage_data' ), Person::field( 'marriages', $post->ID ) );

		$related_pages = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_parent'    => $post->ID,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$data['related_pages'] = array();
		foreach ( $related_pages as $related ) {
			if ( Wiki_Page::is_page( $related ) ) {
				$data['related_pages'][] = $this->prepare_page_data( $related );
			}
		}

		return $data;
	}

	/**
	 * Prepare a content page for output.
	 *
	 * @param \WP_Post $post Page post.
	 * @return array Page data.
	 */
	private function prepare_page_data( \WP_Post $post ) {
		$parent = $post->post_parent ? get_post( $post->post_parent ) : null;

		return array(
			'id'              => (int) $post->ID,
			'title'           => get_the_title( $post ),
			'url'             => Person::url( $post ),
			'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
			'parent_id'       => (int) $post->post_parent,
			'parent_title'    => $parent ? get_the_title( $parent ) : '',
			'is_related_page' => (bool) $post->post_parent,
		);
	}

	/**
	 * Read a boolean input value.
	 *
	 * @param array  $input   Ability input.
	 * @param string $key     Input key.
	 * @param bool   $default_value Default value.
	 * @return bool Boolean value.
	 */
	private function input_bool( array $input, $key, $default_value = false ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return $default_value;
		}

		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $input[ $key ] );
		}

		return filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitize a human label.
	 *
	 * @param string $value Input value.
	 * @return string Sanitized label.
	 */
	private function sanitize_label( $value ) {
		return wp_strip_all_tags( trim( sanitize_text_field( (string) $value ) ) );
	}

	/**
	 * Clamp an integer to a range.
	 *
	 * @param mixed $value Input value.
	 * @param int   $min   Minimum value.
	 * @param int   $max   Maximum value.
	 * @return int Clamped value.
	 */
	private function clamp_int( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Ability metadata helper.
	 *
	 * @param bool   $is_readonly  Whether the ability is readonly.
	 * @param bool   $destructive  Whether the ability is destructive.
	 * @param bool   $idempotent   Whether the ability is idempotent.
	 * @param string $instructions Instructions for AI tools.
	 * @return array Ability meta.
	 */
	private static function ability_meta( $is_readonly, $destructive, $idempotent, $instructions ) {
		$annotations = array(
			'readonly'    => $is_readonly,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		);
		if ( $instructions ) {
			$annotations['instructions'] = $instructions;
		}

		return array(
			'annotations'  => $annotations,
			'show_in_rest' => true,
		);
	}

	/**
	 * Shared input schema for person facts, used by create and update.
	 *
	 * @param bool $creating Whether this schema is for creation.
	 * @return array Input schema.
	 */
	private static function person_facts_input_schema( $creating ) {
		$properties = array(
			'title'                    => array(
				'type'        => 'string',
				'description' => __( "Person's name.", 'family-wiki' ),
			),
			'sex'                      => array(
				'type'        => 'string',
				'enum'        => array( 'Male', 'Female', 'Unknown' ),
				'description' => __( 'Recorded sex.', 'family-wiki' ),
			),
			'alive'                    => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the person is recorded as alive.', 'family-wiki' ),
			),
			'born_as'                  => array(
				'type'        => 'string',
				'description' => __( 'Birth name, if different from the current name.', 'family-wiki' ),
			),
			'citizenships'             => array(
				'type'        => 'string',
				'description' => __( 'Free-text citizenships.', 'family-wiki' ),
			),
			'birth_date'               => array(
				'type'        => 'string',
				'description' => __( 'Birth date as YYYY-MM-DD, or a bare year.', 'family-wiki' ),
			),
			'exact_birth_date_unknown' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether birth_date is only an approximate year.', 'family-wiki' ),
			),
			'birth_place'              => array(
				'type'        => 'string',
				'description' => __( 'Birth place.', 'family-wiki' ),
			),
			'death_date'               => array(
				'type'        => 'string',
				'description' => __( 'Death date as YYYY-MM-DD, or a bare year.', 'family-wiki' ),
			),
			'exact_death_date_unknown' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether death_date is only an approximate year.', 'family-wiki' ),
			),
			'death_place'              => array(
				'type'        => 'string',
				'description' => __( 'Death place.', 'family-wiki' ),
			),
			'father'                   => array(
				'type'        => 'string',
				'description' => __( "Father's person id, or their name if they have no page yet.", 'family-wiki' ),
			),
			'mother'                   => array(
				'type'        => 'string',
				'description' => __( "Mother's person id, or their name if they have no page yet.", 'family-wiki' ),
			),
			'children'                 => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( "Each child's person id or name. Replaces the full recorded list. A name that matches nobody is left off and reported in unmatched_names.", 'family-wiki' ),
			),
			'marriages'                => array(
				'type'        => 'array',
				'items'       => self::marriage_input_schema(),
				'description' => __( 'Marriages and unmarried partnerships. For unmarried partners use [{"spouse":"123","relationship_type":"partnership"}]; never infer marriage from being together. Spouse relationships (husband or wife), including multiple marriages. A spouse alone is sufficient; no wedding date or place is required. Example: [{"spouse":"123"}]. Replaces the full list: fetch get-person first and preserve existing entries when adding a spouse. Use [] only to clear the list.', 'family-wiki' ),
			),
		);

		if ( $creating ) {
			$required = array( 'title' );
		} else {
			$properties = array_merge(
				array(
					'person_id' => array(
						'type'        => 'integer',
						'description' => __( 'Person id from familypedia/list-people.', 'family-wiki' ),
					),
				),
				$properties
			);
			$required = array( 'person_id' );
		}

		return array(
			'type'                 => 'object',
			'required'             => $required,
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for one marriage row.
	 *
	 * @return array Schema.
	 */
	private static function marriage_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'relationship_type' => array(
					'type'        => 'string',
					'enum'        => array( 'marriage', 'partnership' ),
					'description' => __( 'Use partnership for unmarried partners (together, boyfriend, girlfriend). Omitted means marriage. The spouse field identifies the partner in either case.', 'family-wiki' ),
				),
				'spouse'            => array(
					'type'        => 'string',
					'description' => __( "Husband or wife's person id as a string, or their name if they have no page yet. This records the spouse relationship even when no marriage details are known.", 'family-wiki' ),
				),
				'marriage_date'     => array(
					'type'        => 'string',
					'description' => __( 'Marriage date as YYYY-MM-DD.', 'family-wiki' ),
				),
				'marriage_year'     => array(
					'type'        => 'string',
					'description' => __( 'Marriage year, when only the year is known.', 'family-wiki' ),
				),
				'marriage_place'    => array( 'type' => 'string' ),
				'ended_date'        => array(
					'type'        => 'string',
					'description' => __( 'Date the marriage ended, as YYYY-MM-DD.', 'family-wiki' ),
				),
				'ended_year'        => array(
					'type'        => 'string',
					'description' => __( 'Year the marriage ended, when only the year is known.', 'family-wiki' ),
				),
				'ended_reason'      => array(
					'type' => 'string',
					'enum' => array_values( array_filter( array_keys( Person::ended_reason_choices() ) ) ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema shared by create-person and update-person.
	 *
	 * @return array Schema.
	 */
	private static function person_write_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'person'          => self::person_detail_schema(),
				'unmatched_names' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Output schema for a short person reference.
	 *
	 * @param bool $nullable Whether the reference may be null, for a relative
	 *                        who is recorded by name only, or not at all.
	 * @return array Schema.
	 */
	private static function person_ref_schema( $nullable = false ) {
		return array(
			'type'       => $nullable ? array( 'object', 'null' ) : 'object',
			'properties' => array(
				'id'   => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
				'url'  => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for a marriage.
	 *
	 * @return array Schema.
	 */
	private static function marriage_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'relationship_type' => array(
					'type' => 'string',
					'enum' => array( 'marriage', 'partnership' ),
				),
				'spouse'            => self::person_ref_schema( true ),
				'spouse_name'       => array( 'type' => 'string' ),
				'marriage_date'     => array( 'type' => 'string' ),
				'marriage_year'     => array( 'type' => 'string' ),
				'marriage_place'    => array( 'type' => 'string' ),
				'ended_date'        => array( 'type' => 'string' ),
				'ended_year'        => array( 'type' => 'string' ),
				'ended_reason'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for a person summary.
	 *
	 * @return array Schema.
	 */
	private static function person_summary_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'edit_url'    => array( 'type' => 'string' ),
				'sex'         => array( 'type' => 'string' ),
				'alive'       => array( 'type' => 'boolean' ),
				'birth_date'  => array( 'type' => 'string' ),
				'birth_place' => array( 'type' => 'string' ),
				'death_date'  => array( 'type' => 'string' ),
				'death_place' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for full person detail.
	 *
	 * @return array Schema.
	 */
	private static function person_detail_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				self::person_summary_schema()['properties'],
				array(
					'born_as'                  => array( 'type' => 'string' ),
					'citizenships'             => array( 'type' => 'string' ),
					'exact_birth_date_unknown' => array( 'type' => 'boolean' ),
					'exact_death_date_unknown' => array( 'type' => 'boolean' ),
					'bio'                      => array(
						'type'        => 'string',
						'description' => __( 'One-line generated biography: dates, parents, siblings, children.', 'family-wiki' ),
					),
					'father'                   => self::person_ref_schema( true ),
					'father_name'              => array( 'type' => 'string' ),
					'mother'                   => self::person_ref_schema( true ),
					'mother_name'              => array( 'type' => 'string' ),
					'siblings'                 => array(
						'type'  => 'array',
						'items' => self::person_ref_schema(),
					),
					'half_siblings'            => array(
						'type'  => 'array',
						'items' => self::person_ref_schema(),
					),
					'children'                 => array(
						'type'  => 'array',
						'items' => self::person_ref_schema(),
					),
					'marriages'                => array(
						'type'  => 'array',
						'items' => self::marriage_output_schema(),
					),
					'related_pages'            => array(
						'type'  => 'array',
						'items' => self::page_schema(),
					),
				)
			),
		);
	}

	/**
	 * Output schema for a content page.
	 *
	 * @return array Schema.
	 */
	private static function page_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'title'           => array( 'type' => 'string' ),
				'url'             => array( 'type' => 'string' ),
				'edit_url'        => array( 'type' => 'string' ),
				'parent_id'       => array( 'type' => 'integer' ),
				'parent_title'    => array( 'type' => 'string' ),
				'is_related_page' => array( 'type' => 'boolean' ),
			),
		);
	}
}
