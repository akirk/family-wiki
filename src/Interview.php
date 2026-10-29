<?php
/**
 * Guided interviews, source notes, fact review and an editable biography outline.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Interview {
	const ACTION = 'familypedia_interview';
	const META = '_familypedia_interview';
	const DRAFT_META = '_familypedia_biography_draft';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	public static function url( $person_id, $session_id = 0 ) {
		$url = trailingslashit( Person::url( $person_id ) ) . 'interviews/';
		return $session_id ? add_query_arg( 'session', $session_id, $url ) : $url;
	}

	public static function can_manage( $person_id ) {
		$post = get_post( $person_id );
		return $post && Person::POST_TYPE === $post->post_type && ! Wiki_Page::is_page( $post )
			&& in_array( $post->post_status, array( 'publish', 'private', 'draft' ), true )
			&& current_user_can( 'edit_others_pages' ) && current_user_can( 'edit_post', $post->ID );
	}

	public static function is_session( $post ) {
		$post = get_post( $post );
		return $post && Person::POST_TYPE === $post->post_type && is_array( get_post_meta( $post->ID, self::META, true ) );
	}

	public static function edit_url( $post ) {
		$post = get_post( $post );
		if ( $post && self::is_session( $post ) ) {
			return self::url( $post->post_parent, $post->ID );
		}
		if ( $post && self::draft( $post->post_parent, $post->ID ) ) {
			return add_query_arg( 'draft', $post->ID, self::url( $post->post_parent ) );
		}
		return $post ? get_edit_post_link( $post->ID, '' ) : '';
	}

	/** Always validate both the subject and the requested source page. */
	public static function session( $person_id, $session_id ) {
		$post = get_post( $session_id );
		if ( ! self::can_manage( $person_id ) || ! self::is_session( $post ) || (int) $post->post_parent !== (int) $person_id
			|| ! in_array( $post->post_status, array( 'publish', 'private', 'draft' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}
		return $post;
	}

	public static function sessions( $person_id ) {
		if ( ! self::can_manage( $person_id ) ) {
			return array();
		}
		return array_values(
			array_filter(
				get_posts(
					array(
						'post_type'      => Person::POST_TYPE,
						'post_parent'    => $person_id,
						'post_status'    => array( 'publish', 'private', 'draft' ),
						'posts_per_page' => -1,
						'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only direct child pages are loaded.
					'orderby'            => 'date',
					'order'              => 'DESC',
					)
				),
				function ( $post ) use ( $person_id ) {
					return (bool) self::session( $person_id, $post->ID );
				}
			)
		);
	}

	/** Stable question IDs keep saved answers intact when wording changes. */
	public static function catalogue( $about_other = false ) {
		$catalogue = array(
			'childhood'     => array(
				'label'     => __( 'Childhood', 'family-wiki' ),
				'questions' => array(
					'home'         => array( __( 'What was the home you grew up in like?', 'family-wiki' ), __( 'Who lived there? What could you see from your window? Did you move during childhood?', 'family-wiki' ) ),
					'early_memory' => array( __( 'What is one of your earliest memories?', 'family-wiki' ), __( 'Who was with you? What do you remember seeing, hearing or smelling?', 'family-wiki' ) ),
					'parents'      => array( __( 'What were your parents and grandparents like?', 'family-wiki' ), __( 'What did they do every day? What sayings or habits do you remember?', 'family-wiki' ) ),
				),
			),
			'school'        => array(
				'label'     => __( 'School and growing up', 'family-wiki' ),
				'questions' => array(
					'school_days'  => array( __( 'What was a typical school day like?', 'family-wiki' ), __( 'How did you get there? Who were your friends? Was there a teacher you remember?', 'family-wiki' ) ),
					'free_time'    => array( __( 'What did you enjoy doing when you were young?', 'family-wiki' ), __( 'Where did you go? What music, games or hobbies mattered to you?', 'family-wiki' ) ),
					'leaving_home' => array( __( 'How did life change as you became an adult?', 'family-wiki' ), __( 'When did you leave home? What did you hope your life would be like?', 'family-wiki' ) ),
				),
			),
			'work'          => array(
				'label'     => __( 'Work', 'family-wiki' ),
				'questions' => array(
					'first_job'    => array( __( 'How did you find your first job?', 'family-wiki' ), __( 'What did you do? Who helped you learn? What did you spend your first pay on?', 'family-wiki' ) ),
					'working_life' => array( __( 'Which parts of your working life stand out?', 'family-wiki' ), __( 'What were you proud of? Who did you work with? Did your work take you somewhere new?', 'family-wiki' ) ),
					'changes'      => array( __( 'Were there times when you changed direction?', 'family-wiki' ), __( 'What prompted the change? What was difficult? What happened next?', 'family-wiki' ) ),
				),
			),
			'relationships' => array(
				'label'     => __( 'Relationships and family', 'family-wiki' ),
				'questions' => array(
					'important_people' => array( __( 'Who have been the important people in your life?', 'family-wiki' ), __( 'How did you meet? What did you enjoy doing together?', 'family-wiki' ) ),
					'family_life'      => array( __( 'What memories of family life would you like to keep?', 'family-wiki' ), __( 'Think of celebrations, raising children, visits, friendships or ordinary days together.', 'family-wiki' ) ),
					'traditions'       => array( __( 'Which family traditions or stories were passed down to you?', 'family-wiki' ), __( 'Who told you the story? Did you experience it yourself, or hear it from someone else?', 'family-wiki' ) ),
				),
			),
			'everyday'      => array(
				'label'     => __( 'Everyday life', 'family-wiki' ),
				'questions' => array(
					'sunday'    => array( __( 'What was a typical Sunday like?', 'family-wiki' ), __( 'What did you eat? Who visited? How did this change over the years?', 'family-wiki' ) ),
					'places'    => array( __( 'Which places have felt like home?', 'family-wiki' ), __( 'What brought you there? What were the neighbours and surroundings like?', 'family-wiki' ) ),
					'enjoyment' => array( __( 'What has always brought you joy?', 'family-wiki' ), __( 'Are there recipes, songs, journeys, pets or small routines you want us to remember?', 'family-wiki' ) ),
				),
			),
			'memories'      => array(
				'label'     => __( 'Particular memories', 'family-wiki' ),
				'questions' => array(
					'turning_point' => array( __( 'Which events changed your life?', 'family-wiki' ), __( 'How did you experience them? What helped you through? An approximate year is enough.', 'family-wiki' ) ),
					'photo_story'   => array( __( 'Is there a photograph or object with a story you would like to tell?', 'family-wiki' ), __( 'Who is pictured? Where and roughly when was it? Why have you kept it?', 'family-wiki' ) ),
					'remember'      => array( __( 'What else would you like the family to know or remember?', 'family-wiki' ), __( 'Is there a story we have not asked about, or someone we should speak to next?', 'family-wiki' ) ),
				),
			),
		);
		if ( ! $about_other ) {
			return $catalogue;
		}
		$other_questions = array(
			'home'             => array( __( 'What do you know about their childhood home?', 'family-wiki' ), __( 'Who lived there? What was the neighbourhood like? Did they move during childhood?', 'family-wiki' ) ),
			'early_memory'     => array( __( 'What is one of your earliest memories of them?', 'family-wiki' ), __( 'Where were you? What did they say or do? What stands out about that moment?', 'family-wiki' ) ),
			'parents'          => array( __( 'What were their parents and grandparents like?', 'family-wiki' ), __( 'What did they do every day? Which stories about them were passed down?', 'family-wiki' ) ),
			'school_days'      => array( __( 'What do you know about their school days?', 'family-wiki' ), __( 'Where did they go to school? Who were their friends? Did they tell any stories about it?', 'family-wiki' ) ),
			'free_time'        => array( __( 'What did they enjoy doing when they were young?', 'family-wiki' ), __( 'What music, games or hobbies mattered to them? Who spent time with them?', 'family-wiki' ) ),
			'leaving_home'     => array( __( 'How did their adult life begin?', 'family-wiki' ), __( 'When did they leave home? What plans or hopes did they talk about?', 'family-wiki' ) ),
			'first_job'        => array( __( 'What do you know about their first job?', 'family-wiki' ), __( 'How did they find it? What did they do? Who helped them learn?', 'family-wiki' ) ),
			'working_life'     => array( __( 'Which parts of their working life stand out?', 'family-wiki' ), __( 'What were they proud of? Who did they work with? Where did their work take them?', 'family-wiki' ) ),
			'changes'          => array( __( 'Were there times when they changed direction?', 'family-wiki' ), __( 'What prompted the change? What was difficult? What happened next?', 'family-wiki' ) ),
			'important_people' => array( __( 'Who were the important people in their life?', 'family-wiki' ), __( 'How did they meet? What did they enjoy doing together?', 'family-wiki' ) ),
			'family_life'      => array( __( 'Which memories of family life with them would you like to keep?', 'family-wiki' ), __( 'Think of celebrations, raising children, visits, friendships or ordinary days together.', 'family-wiki' ) ),
			'traditions'       => array( __( 'Which traditions or stories did they pass down?', 'family-wiki' ), __( 'Did you hear these stories from them, or from someone else? Who else might remember?', 'family-wiki' ) ),
			'sunday'           => array( __( 'What was a typical Sunday like for them?', 'family-wiki' ), __( 'What did they eat? Who visited? How did this change over the years?', 'family-wiki' ) ),
			'places'           => array( __( 'Which places felt like home to them?', 'family-wiki' ), __( 'What brought them there? What were the neighbours and surroundings like?', 'family-wiki' ) ),
			'enjoyment'        => array( __( 'What brought them joy?', 'family-wiki' ), __( 'Are there recipes, songs, journeys, pets or small routines that remind you of them?', 'family-wiki' ) ),
			'turning_point'    => array( __( 'Which events changed their life?', 'family-wiki' ), __( 'How did they experience them? What helped them through? An approximate year is enough.', 'family-wiki' ) ),
			'photo_story'      => array( __( 'Is there a photograph or object that tells a story about them?', 'family-wiki' ), __( 'Who is pictured? Where and roughly when was it? Why was it kept?', 'family-wiki' ) ),
			'remember'         => array( __( 'What else would you like the family to remember about them?', 'family-wiki' ), __( 'Is there a story we have not asked about, or someone we should speak to next?', 'family-wiki' ) ),
		);
		foreach ( $catalogue as &$group ) {
			foreach ( $group['questions'] as $key => &$prompt ) {
				$prompt = $other_questions[ $key ];
			}
		}
		return $catalogue;
	}

	public static function fact_labels() {
		return array(
			'birth_date'   => __( 'Birth date', 'family-wiki' ),
			'birth_place'  => __( 'Birth place', 'family-wiki' ),
			'born_as'      => __( 'Birth name', 'family-wiki' ),
			'death_date'   => __( 'Death date', 'family-wiki' ),
			'death_place'  => __( 'Death place', 'family-wiki' ),
			'citizenships' => __( 'Citizenships', 'family-wiki' ),
		);
	}

	/** Include date precision when comparing a proposed change with live facts. */
	public static function fact_value( $person_id, $field ) {
		$value = (string) Person::field( $field, $person_id );
		if ( in_array( $field, array( 'birth_date', 'death_date' ), true ) && Person::field( 'exact_' . $field . '_unknown', $person_id ) ) {
			$value = substr( $value, 0, 4 );
		}
		return $value;
	}

	private static function text( $input, $key, $multiline = false ) {
		$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	private static function valid_date( $date ) {
		if ( preg_match( '/^[0-9]{4}$/', $date ) ) {
			return (int) $date > 0;
		}
		return preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	public static function create( $person_id, $input ) {
		if ( ! self::can_manage( $person_id ) || ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'permission', __( 'You cannot add interviews here.', 'family-wiki' ) );
		}
		$speaker = self::text( $input, 'speaker' );
		$date = self::text( $input, 'date' );
		if ( '' === $speaker || ! self::valid_date( $date ) || 10 !== strlen( $date ) ) {
			return new \WP_Error( 'details', __( 'Enter the speaker and a valid interview date.', 'family-wiki' ) );
		}
		$data = array(
			'speaker'     => $speaker,
			'about_other' => 'other' === self::text( $input, 'perspective' ),
			'date'        => $date,
			'topic'       => 'childhood',
			'question'    => 'home',
			'answers'     => array(),
			'notes'       => '',
			'follow_up'   => '',
			'finished'    => false,
			'version'     => 1,
			'reviews'     => array(),
		);
		foreach ( self::fact_labels() as $field => $label ) {
			$data['reviews'][ $field ] = array(
				'original' => self::fact_value( $person_id, $field ),
				'status'   => 'unreviewed',
				'proposed' => '',
				'note'     => '',
			);
		}
		// translators: 1: speaker, 2: date.
		$title = sprintf( __( 'Interview with %1$s — %2$s', 'family-wiki' ), $speaker, $date );
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Person::POST_TYPE,
					'post_parent'  => $person_id,
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => self::transcript( $data ),
					'meta_input'   => array(
						Wiki_Page::META_KEY => 1,
						self::META          => $data,
					),
				)
			),
			true
		);
		return $id;
	}

	/** Save notes only; even confirmed facts do not change the person here. */
	public static function save( $person_id, $session_id, $input ) {
		if ( ! self::session( $person_id, $session_id ) ) {
			return new \WP_Error( 'permission', __( 'This interview is not available to edit.', 'family-wiki' ) );
		}
		$old = get_post_meta( $session_id, self::META, true );
		if ( (int) ( $input['version'] ?? 0 ) !== $old['version'] ) {
			return new \WP_Error( 'conflict', __( 'This interview changed in another window. Copy your unsaved notes before reloading.', 'family-wiki' ) );
		}
		$data = $old;
		if ( isset( $input['speaker'], $input['date'] ) ) {
			$speaker = self::text( $input, 'speaker' );
			$date = self::text( $input, 'date' );
			if ( '' === $speaker || ! self::valid_date( $date ) || 10 !== strlen( $date ) ) {
				return new \WP_Error( 'details', __( 'Enter the speaker and a valid interview date.', 'family-wiki' ) );
			}
			$data['speaker'] = $speaker;
			$data['date'] = $date;
		}
		if ( isset( $input['perspective'] ) ) {
			$data['about_other'] = 'other' === self::text( $input, 'perspective' );
		}
		$catalogue = self::catalogue();
		$topic = self::text( $input, 'topic' );
		$question = self::text( $input, 'question' );
		if ( isset( $catalogue[ $topic ]['questions'][ $question ] ) ) {
			$data['topic'] = $topic;
			$data['question'] = $question;
		}
		foreach ( $catalogue as $group ) {
			foreach ( $group['questions'] as $key => $prompt ) {
				$data['answers'][ $key ] = self::text( is_array( $input['answers'] ?? null ) ? $input['answers'] : array(), $key, true );
			}
		}
		$data['notes'] = self::text( $input, 'notes', true );
		$data['follow_up'] = self::text( $input, 'follow_up', true );
		$data['finished'] = ! empty( $input['finished'] );
		foreach ( self::fact_labels() as $field => $label ) {
			$row = isset( $input['reviews'][ $field ] ) && is_array( $input['reviews'][ $field ] ) ? $input['reviews'][ $field ] : array();
			$status = self::text( $row, 'status' );
			if ( ! in_array( $status, array( 'unreviewed', 'confirmed', 'correction', 'unsure', 'applied' ), true ) || ( 'applied' === $status && 'applied' !== $old['reviews'][ $field ]['status'] ) ) {
				$status = 'unreviewed';
			}
			$data['reviews'][ $field ]['status'] = $status;
			$data['reviews'][ $field ]['proposed'] = self::text( $row, 'proposed' );
			$data['reviews'][ $field ]['note'] = self::text( $row, 'note', true );
		}
		return self::store( $session_id, $data, $old );
	}

	/** Compare-and-swap prevents an autosave from overwriting a newer session. */
	private static function store( $session_id, $data, $old ) {
		$data['version'] = $old['version'] + 1;
		if ( ! update_post_meta( $session_id, self::META, wp_slash( $data ), $old ) ) {
			return new \WP_Error( 'conflict', __( 'The interview could not be saved. Copy your notes before reloading and trying again.', 'family-wiki' ) );
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $session_id,
					'post_content' => self::transcript( $data ),
					// translators: 1: speaker, 2: date.
					'post_title'   => sprintf( __( 'Interview with %1$s — %2$s', 'family-wiki' ), $data['speaker'], $data['date'] ),
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			update_post_meta( $session_id, self::META, wp_slash( $old ), $data );
			return $result;
		}
		return $data;
	}

	/** Apply one explicitly reviewed value, preserving the original testimony. */
	public static function apply_fact( $person_id, $session_id, $field ) {
		if ( ! self::session( $person_id, $session_id ) || ! isset( self::fact_labels()[ $field ] ) ) {
			return new \WP_Error( 'permission', __( 'This fact cannot be changed here.', 'family-wiki' ) );
		}
		$data = get_post_meta( $session_id, self::META, true );
		$row = $data['reviews'][ $field ];
		$value = $row['proposed'];
		if ( isset( $row['applied_value'] ) || 'correction' !== $row['status'] || '' === $value ) {
			return new \WP_Error( 'review', __( 'Choose “Add or correct” and enter a proposed value first.', 'family-wiki' ) );
		}
		if ( self::fact_value( $person_id, $field ) !== $row['original'] ) {
			return new \WP_Error( 'conflict', __( 'This fact has changed since the interview began. Review it on the person’s facts page; the interview keeps both accounts.', 'family-wiki' ) );
		}
		if ( in_array( $field, array( 'birth_date', 'death_date' ), true ) ) {
			if ( ! self::valid_date( $value ) ) {
				return new \WP_Error( 'date', __( 'Use a valid YYYY-MM-DD date or just a year. Keep uncertain details in the note.', 'family-wiki' ) );
			}
			$birth = 'birth_date' === $field ? $value : self::fact_value( $person_id, 'birth_date' );
			$death = 'death_date' === $field ? $value : self::fact_value( $person_id, 'death_date' );
			if ( ( $birth && $death && ( (int) substr( $birth, 0, 4 ) > (int) substr( $death, 0, 4 ) || ( 10 === strlen( $birth ) && 10 === strlen( $death ) && 0 < strcmp( $birth, $death ) ) ) ) || ( 'death_date' === $field && Person::field( 'alive', $person_id ) ) ) {
				return new \WP_Error( 'date', __( 'Review the birth, death and living status together on the person’s facts page before applying this date.', 'family-wiki' ) );
			}
		}
		$updated = $data;
		$updated['reviews'][ $field ]['status'] = 'applied';
		$updated['reviews'][ $field ]['applied_value'] = $value;
		$result = self::store( $session_id, $updated, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Person::update( $field, $value, $person_id );
		if ( in_array( $field, array( 'birth_date', 'death_date' ), true ) ) {
			$approximate = 4 === strlen( $value );
			Person::update( 'exact_' . $field . '_unknown', $approximate, $person_id );
		}
		return $result;
	}

	public static function transcript( $data ) {
		$html = '<p>' . esc_html( $data['speaker'] . ' — ' . $data['date'] ) . '</p>';
		foreach ( self::catalogue( ! empty( $data['about_other'] ) ) as $group ) {
			foreach ( $group['questions'] as $key => $prompt ) {
				if ( ! empty( $data['answers'][ $key ] ) ) {
					$html .= '<h2>' . esc_html( $prompt[0] ) . '</h2>' . wpautop( esc_html( $data['answers'][ $key ] ) );
				}
			}
		}
		foreach ( array(
			'notes'     => __( 'Other memories', 'family-wiki' ),
			'follow_up' => __( 'Questions to return to', 'family-wiki' ),
		) as $key => $label ) {
			if ( ! empty( $data[ $key ] ) ) {
				$html .= '<h2>' . esc_html( $label ) . '</h2>' . wpautop( esc_html( $data[ $key ] ) );
			}
		}
		foreach ( self::fact_labels() as $field => $label ) {
			$row = $data['reviews'][ $field ];
			if ( 'unreviewed' === $row['status'] && ! $row['note'] && ! $row['proposed'] ) {
				continue;
			}
			$statuses = array(
				'unreviewed' => __( 'Not discussed', 'family-wiki' ),
				'confirmed'  => __( 'Confirmed by speaker', 'family-wiki' ),
				'correction' => __( 'Add or correct', 'family-wiki' ),
				'unsure'     => __( 'Unsure / conflicting memories', 'family-wiki' ),
				'applied'    => __( 'Applied to person’s facts', 'family-wiki' ),
			);
			$html .= '<h2>' . esc_html( $label ) . '</h2><p>' . esc_html( $statuses[ $row['status'] ] ) . '</p>';
			$html .= '<p>' . esc_html__( 'Originally recorded:', 'family-wiki' ) . ' ' . esc_html( $row['original'] ) . '</p>';
			if ( $row['proposed'] ) {
				$html .= '<p>' . esc_html__( 'Proposed value:', 'family-wiki' ) . ' ' . esc_html( $row['proposed'] ) . '</p>';
			}
			if ( isset( $row['applied_value'] ) ) {
				$html .= '<p>' . esc_html__( 'Applied value:', 'family-wiki' ) . ' ' . esc_html( $row['applied_value'] ) . '</p>';
			}
			$html .= wpautop( esc_html( $row['note'] ) );
		}
		return $html;
	}

	/** Keep submitted words recoverable when a regular form fails validation. */
	public static function recovery_text( $input ) {
		$parts = array();
		foreach ( array( 'speaker', 'date', 'notes', 'follow_up', 'draft_text' ) as $key ) {
			$value = self::text( $input, $key, true );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
		foreach ( self::catalogue() as $group ) {
			foreach ( $group['questions'] as $key => $prompt ) {
				$value = self::text( is_array( $input['answers'] ?? null ) ? $input['answers'] : array(), $key, true );
				if ( '' !== $value ) {
					$parts[] = $prompt[0] . "\n" . $value;
				}
			}
		}
		foreach ( self::fact_labels() as $field => $label ) {
			$row = isset( $input['reviews'][ $field ] ) && is_array( $input['reviews'][ $field ] ) ? $input['reviews'][ $field ] : array();
			$value = trim( self::text( $row, 'proposed' ) . "\n" . self::text( $row, 'note', true ) );
			if ( '' !== $value ) {
				$parts[] = $label . "\n" . $value;
			}
		}
		return implode( "\n\n", $parts );
	}

	/** An attributed outline, without inventing prose or resolving uncertainty. */
	public static function outline( $sessions ) {
		$parts = array();
		foreach ( $sessions as $session ) {
			$data = get_post_meta( $session->ID, self::META, true );
			$parts[] = $data['speaker'] . ' — ' . $data['date'];
			foreach ( self::catalogue() as $group ) {
				$notes = array();
				foreach ( $group['questions'] as $key => $prompt ) {
					if ( ! empty( $data['answers'][ $key ] ) ) {
						$notes[] = $data['answers'][ $key ];
					}
				}
				if ( $notes ) {
					$parts[] = $group['label'] . "\n\n" . implode( "\n\n", $notes );
				}
			}
			if ( ! empty( $data['notes'] ) ) {
				$parts[] = $data['notes'];
			}
		}
		return implode( "\n\n", $parts );
	}

	public static function draft( $person_id, $draft_id ) {
		$post = get_post( $draft_id );
		return self::can_manage( $person_id ) && $post && Person::POST_TYPE === $post->post_type && (int) $post->post_parent === (int) $person_id
			&& 'draft' === $post->post_status && current_user_can( 'edit_post', $post->ID ) && is_array( get_post_meta( $post->ID, self::DRAFT_META, true ) ) ? $post : null;
	}

	public static function create_draft( $person_id, $source_ids ) {
		if ( ! self::can_manage( $person_id ) || ! current_user_can( 'publish_pages' ) || ! is_array( $source_ids ) || ! $source_ids ) {
			return new \WP_Error( 'sources', __( 'Select at least one interview to use for the outline.', 'family-wiki' ) );
		}
		$sessions = array();
		foreach ( array_unique( array_map( 'absint', $source_ids ) ) as $id ) {
			$session = self::session( $person_id, $id );
			if ( ! $session ) {
				return new \WP_Error( 'sources', __( 'One of the selected interviews is unavailable.', 'family-wiki' ) );
			}
			$data = get_post_meta( $id, self::META, true );
			if ( array_filter( $data['answers'] ) || '' !== $data['notes'] ) {
				$sessions[] = $session;
			}
		}
		if ( ! $sessions ) {
			return new \WP_Error( 'empty', __( 'Add some memories to the interviews first.', 'family-wiki' ) );
		}
		$meta = array(
			'text'     => self::outline( $sessions ),
			'sources'  => array_map(
				function ( $post ) {
					return $post->ID;
				},
				$sessions
			),
			'version'  => 1,
			'appended' => false,
		);
		return wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Person::POST_TYPE,
					'post_parent'  => $person_id,
					'post_status'  => 'draft',
					// translators: %s is the subject's name.
					'post_title'   => sprintf( __( 'Biography draft — %s', 'family-wiki' ), get_the_title( $person_id ) ),
					'post_content' => wpautop( esc_html( $meta['text'] ) ),
					'meta_input'   => array(
						Wiki_Page::META_KEY => 1,
						self::DRAFT_META    => $meta,
					),
				)
			),
			true
		);
	}

	public static function save_draft( $person_id, $draft_id, $input, $append = false ) {
		if ( ! self::draft( $person_id, $draft_id ) ) {
			return new \WP_Error( 'permission', __( 'This biography draft is unavailable.', 'family-wiki' ) );
		}
		$old = get_post_meta( $draft_id, self::DRAFT_META, true );
		if ( $old['appended'] || (int) ( $input['version'] ?? 0 ) !== $old['version'] ) {
			return new \WP_Error( 'conflict', __( 'This draft changed or has already been added to the biography. Reload to see the latest version.', 'family-wiki' ) );
		}
		$data = $old;
		$data['text'] = self::text( $input, 'draft_text', true );
		if ( ! $data['text'] ) {
			return new \WP_Error( 'empty', __( 'The draft is empty.', 'family-wiki' ) );
		}
		$person = get_post( $person_id );
		if ( $append && ( empty( $input['reviewed'] ) || self::text( $input, 'biography_hash' ) !== hash( 'sha256', $person->post_content ) ) ) {
			return new \WP_Error( 'review', __( 'Review the draft and current biography, then confirm it is ready to add. Reload if the biography has changed.', 'family-wiki' ) );
		}
		++$data['version'];
		// Reserve this version before appending, so a repeated request cannot append twice.
		$data['appended'] = $append;
		if ( ! update_post_meta( $draft_id, self::DRAFT_META, wp_slash( $data ), $old ) ) {
			return new \WP_Error( 'conflict', __( 'The draft could not be saved. Keep a copy of your text and reload.', 'family-wiki' ) );
		}
		$html = wpautop( esc_html( $data['text'] ) );
		if ( $append ) {
			$sources = array();
			foreach ( $data['sources'] as $id ) {
				$source = self::session( $person_id, $id );
				if ( $source ) {
					$sources[] = '<a href="' . esc_url( Person::url( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a>';
				}
			}
			$html .= '<p>' . esc_html__( 'Interview sources:', 'family-wiki' ) . ' ' . implode( '; ', $sources ) . '</p>';
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $person_id,
						'post_content' => $person->post_content . "\n\n" . $html,
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				update_post_meta( $draft_id, self::DRAFT_META, wp_slash( $old ), $data );
				return $result;
			}
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $draft_id,
					'post_content' => $html,
				)
			),
			true
		);
		return is_wp_error( $result ) ? $result : $data;
	}

	/** Regular forms work without JavaScript; autosave uses the same checked path. */
	public function maybe_save() {
		if ( ! isset( $_POST['familypedia_action'] ) || self::ACTION !== $_POST['familypedia_action'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- All used fields are individually type checked and sanitized below.
		$input = wp_unslash( $_POST );
		$person_id = absint( $input['person_id'] ?? 0 );
		$session_id = absint( $input['session_id'] ?? 0 );
		$draft_id = absint( $input['draft_id'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $person_id );
		$op = self::text( $input, 'op' );
		$result = new \WP_Error( 'action', __( 'This interview action is unavailable.', 'family-wiki' ) );
		$url = self::url( $person_id, $session_id );
		if ( self::can_manage( $person_id ) ) {
			if ( 'start' === $op ) {
				$result = self::create( $person_id, $input );
				if ( ! is_wp_error( $result ) ) {
					$url = self::url( $person_id, $result );
				}
			} elseif ( 'save' === $op || 0 === strpos( $op, 'apply:' ) ) {
				$result = self::save( $person_id, $session_id, $input );
				if ( ! is_wp_error( $result ) && 0 === strpos( $op, 'apply:' ) ) {
					$result = self::apply_fact( $person_id, $session_id, substr( $op, 6 ) );
					$url .= '#fact-review';
				}
			} elseif ( 'outline' === $op ) {
				$result = self::create_draft( $person_id, $input['sources'] ?? array() );
				if ( ! is_wp_error( $result ) ) {
					$url = add_query_arg( 'draft', $result, self::url( $person_id ) );
				}
			} elseif ( in_array( $op, array( 'save_draft', 'append' ), true ) ) {
				$result = self::save_draft( $person_id, $draft_id, $input, 'append' === $op );
				$url = add_query_arg( 'draft', $draft_id, self::url( $person_id ) );
			}
		}
		if ( ! empty( $input['autosave'] ) && 'save' === $op ) {
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 'conflict' === $result->get_error_code() ? 409 : 500 );
			}
			wp_send_json_success( array( 'version' => $result['version'] ) );
		}
		if ( is_wp_error( $result ) && self::can_manage( $person_id ) ) {
			set_transient( 'familypedia_interview_recovery_' . get_current_user_id() . '_' . $person_id, self::recovery_text( $input ), HOUR_IN_SECONDS );
		}
		Editor::set_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Saved.', 'family-wiki' ), is_wp_error( $result ) ? 'error' : 'success' );
		wp_safe_redirect( $url );
		exit;
	}
}
