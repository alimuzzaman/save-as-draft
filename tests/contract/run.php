<?php
/**
 * Executable database/REST contract runner.
 *
 * Run from a disposable WordPress install with:
 *
 *   wp --require=tests/fixtures/clone-contract-fixtures.php \
 *      eval-file tests/contract/run.php
 *
 * The script emits one JSON document. It deliberately uses the WordPress REST
 * dispatcher rather than calling a plugin class directly, so the authenticated
 * permission callback, request schema, idempotency, and compensating cleanup
 * are covered together. It does not require credentials in source control:
 * WP-CLI supplies the current user and the test site supplies its nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This file must be run by WP-CLI (or inside WordPress).\n" );
	exit( 2 );
}

if ( ! function_exists( 'clone_contract_register_fixtures' ) ) {
	fwrite(
		STDERR,
		"Load tests/fixtures/clone-contract-fixtures.php with WP-CLI --require first.\n"
	);
	exit( 2 );
}

clone_contract_register_fixtures();

$clone_contract_action = getenv( 'CLONE_CONTRACT_ACTION' );
$clone_contract_action = $clone_contract_action ? $clone_contract_action : 'run';
$clone_contract_keep   = '1' === getenv( 'CLONE_CONTRACT_KEEP' );

/**
 * Return a compact, deterministic representation of a response.
 *
 * @param mixed $response REST response or WP_Error.
 * @return array<string,mixed>
 */
function clone_contract_response_info( $response ) {
	if ( is_wp_error( $response ) ) {
		$status = $response->get_error_data();
		if ( is_array( $status ) && isset( $status['status'] ) ) {
			$status = (int) $status['status'];
		} else {
			$status = 0;
		}

		return array(
			'error'  => true,
			'code'   => $response->get_error_code(),
			'message'=> $response->get_error_message(),
			'status' => $status,
		);
	}

	$data = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
	return array(
		'error'  => false,
		'status' => method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0,
		'data'   => $data,
	);
}

/**
 * Create an assertion record without stopping the remaining matrix.
 *
 * @param array<string,mixed> $result Result document passed by reference.
 * @param string              $name   Stable assertion name.
 * @param bool                $passed Whether the assertion passed.
 * @param mixed               $details Optional diagnostic details.
 * @return void
 */
function clone_contract_assert( &$result, $name, $passed, $details = null ) {
	$record = array( 'name' => $name, 'passed' => (bool) $passed );
	if ( null !== $details ) {
		$record['details'] = $details;
	}
	$result['assertions'][] = $record;
	if ( ! $passed ) {
		$result['ok'] = false;
		$result['failures'][] = array( 'name' => $name, 'details' => $details );
	}
}

/**
 * Ensure a term exists and return its ID.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param string $name     Human-readable name.
 * @param string $slug     Stable slug.
 * @param int    $parent   Optional parent term ID.
 * @return int
 */
function clone_contract_term( $taxonomy, $name, $slug, $parent = 0 ) {
	$existing = term_exists( $slug, $taxonomy );
	if ( is_array( $existing ) ) {
		return (int) $existing['term_id'];
	}
	if ( is_int( $existing ) ) {
		return $existing;
	}

	$created = wp_insert_term(
		$name,
		$taxonomy,
		array(
			'slug'   => $slug,
			'parent' => (int) $parent,
		)
	);
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}
	return (int) $created['term_id'];
}

/**
 * Insert a fixture post and throw on failure.
 *
 * @param array<string,mixed> $postarr Post fields.
 * @return int
 */
function clone_contract_insert_post( $postarr ) {
	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	return (int) $post_id;
}

/**
 * Build all rows used by the contract checks.
 *
 * @return array<string,mixed>
 */
function clone_contract_create_fixture() {
	global $wpdb;

	$user_id = (int) getenv( 'CLONE_CONTRACT_USER_ID' );
	if ( ! $user_id ) {
		$user_id = (int) get_current_user_id();
	}
	if ( ! $user_id ) {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$user_id = ! empty( $users ) ? (int) $users[0] : 0;
	}
	if ( ! $user_id ) {
		throw new RuntimeException( 'No authenticated editor user was found; set CLONE_CONTRACT_USER_ID.' );
	}
	wp_set_current_user( $user_id );

	$nonce = wp_create_nonce( 'wp_rest' );
	$suffix = strtolower( wp_generate_password( 8, false, false ) );

	$topic_parent = clone_contract_term(
		'clone_contract_topic',
		'Contract topic parent ' . $suffix,
		'clone-contract-topic-parent-' . $suffix
	);
	$topic_child = clone_contract_term(
		'clone_contract_topic',
		'Contract topic child ' . $suffix,
		'clone-contract-topic-child-' . $suffix,
		$topic_parent
	);
	$label_one = clone_contract_term(
		'clone_contract_label',
		'Contract label one ' . $suffix,
		'clone-contract-label-one-' . $suffix
	);
	$label_two = clone_contract_term(
		'clone_contract_label',
		'Contract label two ' . $suffix,
		'clone-contract-label-two-' . $suffix
	);
	$category_one = clone_contract_term(
		'category',
		'Contract category one ' . $suffix,
		'clone-contract-category-one-' . $suffix
	);
	$category_two = clone_contract_term(
		'category',
		'Contract category two ' . $suffix,
		'clone-contract-category-two-' . $suffix
	);
	$tag_one = clone_contract_term(
		'post_tag',
		'Contract tag one ' . $suffix,
		'clone-contract-tag-one-' . $suffix
	);
	$tag_two = clone_contract_term(
		'post_tag',
		'Contract tag two ' . $suffix,
		'clone-contract-tag-two-' . $suffix
	);

	$parent_id = clone_contract_insert_post(
		array(
			'post_type'    => 'clone_contract_item',
			'post_status'  => 'publish',
			'post_title'   => 'Clone contract parent ' . $suffix,
			'post_content' => '<!-- wp:paragraph --><p>Parent fixture.</p><!-- /wp:paragraph -->',
			'post_author'  => $user_id,
		)
	);

	// An attachment row is enough to verify reference reuse. No file is copied
	// by this fixture; a production adapter must not manufacture one either.
	$attachment_id = clone_contract_insert_post(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Clone contract thumbnail ' . $suffix,
			'post_mime_type' => 'image/png',
			'post_author'    => $user_id,
		)
	);
	update_post_meta( $attachment_id, '_wp_attached_file', 'clone-contract/' . $suffix . '.png' );

	$source_id = clone_contract_insert_post(
		array(
			'post_type'      => 'clone_contract_item',
			'post_status'    => 'publish',
			'post_title'     => 'Clone contract source ' . $suffix,
			'post_name'      => 'clone-contract-source-' . $suffix,
			'post_content'   => '<!-- wp:paragraph --><p>Saved source content.</p><!-- /wp:paragraph -->',
			'post_excerpt'    => 'Saved source excerpt.',
			'post_author'    => $user_id,
			'post_parent'    => $parent_id,
			'menu_order'     => 19,
			'comment_status' => 'open',
			'ping_status'    => 'closed',
			'post_password'  => 'clone-contract-password',
		)
	);
	set_post_thumbnail( $source_id, $attachment_id );
	update_post_meta( $source_id, '_wp_page_template', 'contract-template.php' );
	add_post_type_support( 'clone_contract_item', 'post-formats' );
	if ( function_exists( 'add_theme_support' ) ) {
		add_theme_support( 'post-formats', array( 'quote' ) );
	}
	set_post_format( $source_id, 'quote' );
	wp_set_object_terms( $source_id, array( $topic_child ), 'clone_contract_topic' );
	wp_set_object_terms( $source_id, array( $label_one, $label_two ), 'clone_contract_label' );
	wp_set_object_terms( $source_id, array( $category_one ), 'category' );
	wp_set_object_terms( $source_id, array( $tag_one ), 'post_tag' );

	$sticky_source_id = clone_contract_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Sticky contract source ' . $suffix,
			'post_content' => 'Sticky source content.',
			'post_author'  => $user_id,
		)
	);
	stick_post( $sticky_source_id );

	add_post_meta( $source_id, 'clone_contract_allowed', 'saved allowlisted value' );
	add_post_meta( $source_id, 'clone_contract_repeated', 'saved repeated A' );
	add_post_meta( $source_id, 'clone_contract_repeated', 'saved repeated B' );
	add_post_meta(
		$source_id,
		'clone_contract_serialized',
		array(
			'saved' => true,
			'kind'  => 'serialized fixture',
		)
	);
	add_post_meta( $source_id, 'clone_contract_default', 'saved default value' );
	add_post_meta( $source_id, 'clone_contract_private', 'must not copy' );
	add_post_meta( $source_id, '_clone_contract_internal', 'must not copy' );
	add_post_meta( $source_id, '_edit_lock', (string) time() );

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID' => $source_id,
			'comment_author'  => 'Clone contract fixture',
			'comment_content' => 'Source comment must not be cloned.',
			'comment_approved'=> 1,
		)
	);
	if ( ! $comment_id ) {
		throw new RuntimeException( 'Could not create source comment fixture.' );
	}

	$revision_id = wp_save_post_revision( $source_id );
	if ( ! $revision_id ) {
		$revision_id = clone_contract_insert_post(
			array(
				'post_type'      => 'revision',
				'post_status'    => 'inherit',
				'post_parent'    => $source_id,
				'post_title'     => 'Revision fixture',
				'post_content'   => 'Revision content.',
				'post_author'    => $user_id,
			)
		);
	}

	$child_id = clone_contract_insert_post(
		array(
			'post_type'   => 'clone_contract_item',
			'post_status' => 'draft',
			'post_parent' => $source_id,
			'post_title'  => 'Clone contract child ' . $suffix,
			'post_author' => $user_id,
		)
	);

	global $wpdb;
	$table = $wpdb->prefix . 'clone_contract_data';
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS `{$table}` (\n" .
		"  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n" .
		"  `post_id` bigint(20) unsigned NOT NULL,\n" .
		"  `payload` longtext NOT NULL,\n" .
		"  PRIMARY KEY (`id`), KEY `post_id` (`post_id`)\n" .
		") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	$wpdb->insert(
		$table,
		array(
			'post_id' => $source_id,
			'payload' => 'custom table data must not be copied',
		),
		array( '%d', '%s' )
	);

	$state = array(
		'user_id'       => $user_id,
		'nonce'         => $nonce,
		'suffix'        => $suffix,
		'parent_id'     => $parent_id,
		'source_id'     => $source_id,
		'sticky_source_id' => $sticky_source_id,
		'attachment_id' => $attachment_id,
		'child_id'      => $child_id,
		'revision_id'   => (int) $revision_id,
		'comment_id'    => (int) $comment_id,
		'table'         => $table,
		'topic_parent'  => $topic_parent,
		'topic_child'   => $topic_child,
		'label_one'     => $label_one,
		'label_two'     => $label_two,
		'category_one'  => $category_one,
		'category_two'  => $category_two,
		'tag_one'       => $tag_one,
		'tag_two'       => $tag_two,
	);
	update_option( 'clone_contract_fixture_state', $state, false );
	return $state;
}

/**
 * Snapshot source-owned state before and after the clone.
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function clone_contract_snapshot( $post_id ) {
	global $wpdb;
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array( 'missing' => true );
	}

	// Keep a raw SQL view alongside the WordPress API view. This makes the
	// contract a database diff as well as a REST/object-model assertion: the
	// source row and its owned rows must be byte-for-byte stable, while the
	// target rows can be inspected directly in wp_posts, wp_postmeta, and
	// wp_term_relationships.
	$db_post = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ID, post_author, post_date, post_modified, post_status, post_type, post_title, post_content, post_excerpt, post_parent, menu_order, post_password, post_name, guid, comment_status, ping_status FROM {$wpdb->posts} WHERE ID = %d",
			$post_id
		),
		ARRAY_A
	);
	$db_meta = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$post_id
		),
		ARRAY_A
	);
	$db_terms = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT term_taxonomy_id, term_order FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id, term_order",
			$post_id
		),
		ARRAY_A
	);

	$terms = array();
	$objects = get_object_taxonomies( $post->post_type, 'objects' );
	foreach ( $objects as $taxonomy => $tax_object ) {
		if ( empty( $tax_object->show_in_rest ) || 'post_format' === $taxonomy ) {
			continue;
		}
		$term_ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$terms[ $taxonomy ] = is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
		sort( $terms[ $taxonomy ], SORT_NUMERIC );
	}

	$meta = get_post_meta( $post_id );
	foreach ( $meta as $key => $values ) {
		foreach ( $values as $index => $value ) {
			$meta[ $key ][ $index ] = maybe_unserialize( $value );
		}
	}
	ksort( $meta );

	$table = $wpdb->prefix . 'clone_contract_data';
	$custom_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT post_id, payload FROM `{$table}` WHERE post_id = %d ORDER BY id", $post_id ),
		ARRAY_A
	);

	return array(
		'id'              => (int) $post->ID,
		'post_type'       => $post->post_type,
		'post_status'     => $post->post_status,
		'post_title'      => $post->post_title,
		'post_content'    => $post->post_content,
		'post_excerpt'    => $post->post_excerpt,
		'post_parent'     => (int) $post->post_parent,
		'menu_order'      => (int) $post->menu_order,
		'comment_status'  => $post->comment_status,
		'ping_status'     => $post->ping_status,
		'post_password'   => $post->post_password,
		'post_name'       => $post->post_name,
		'guid'            => $post->guid,
		'template'        => get_post_meta( $post_id, '_wp_page_template', true ),
		'format'          => get_post_format( $post_id ),
		'sticky'          => is_sticky( $post_id ),
		'thumbnail'       => (int) get_post_thumbnail_id( $post_id ),
		'terms'           => $terms,
		'meta'            => $meta,
		'comments'        => (int) get_comments( array( 'post_id' => $post_id, 'count' => true ) ),
		'revisions'       => count( wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) ),
		'children'        => array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $post->post_type,
					'post_parent'    => $post_id,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		),
		'custom_rows'     => $custom_rows,
		'db'              => array(
			'post'                => $db_post,
			'postmeta'            => $db_meta,
			'term_relationships'  => $db_terms,
			'custom_table'        => $custom_rows,
		),
	);
}

/**
 * Count fixture-type posts. The result is used to prove no orphan target was
 * left on rejected or partially failed requests.
 *
 * @param string $post_type Type to count. Empty means every post type,
 *                          including denied product-like types.
 * @return int
 */
function clone_contract_count_posts( $post_type = '' ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type ? $post_type : 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	return count( $ids );
}

/**
 * Dispatch a JSON-like request through the REST server.
 *
 * @param array<string,mixed> $body Request body.
 * @param int                 $user_id Authenticated user.
 * @param string              $nonce REST nonce.
 * @return mixed
 */
function clone_contract_dispatch( $body, $user_id, $nonce ) {
	wp_set_current_user( (int) $user_id );
	$request = new WP_REST_Request( 'POST', '/clone-post-unsaved-changes/v1/drafts' );
	$request->set_header( 'X-WP-Nonce', $nonce ? $nonce : wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body_params( $body );
	return rest_do_request( $request );
}

/**
 * Assert a rejected request and no extra fixture post.
 *
 * @param array<string,mixed> $result Result document.
 * @param string              $name Assertion name.
 * @param array<string,mixed> $body Request body.
 * @param array<string,mixed> $state Fixture state.
 * @return array<string,mixed> Response info.
 */
function clone_contract_expect_rejected( &$result, $name, $body, $state ) {
	$before = clone_contract_count_posts();
	$response = clone_contract_dispatch( $body, $state['user_id'], $state['nonce'] );
	$info = clone_contract_response_info( $response );
	$after = clone_contract_count_posts();
	$rejected = ! empty( $info['error'] ) || ( isset( $info['status'] ) && $info['status'] >= 400 );
	clone_contract_assert(
		$result,
		$name . ': request rejected',
		$rejected,
		$info
	);
	clone_contract_assert(
		$result,
		$name . ': no target post created',
		$before === $after,
		array( 'before' => $before, 'after' => $after, 'response' => $info )
	);
	return $info;
}

/**
 * Delete only rows created by this fixture and remove its table/state.
 *
 * @param array<string,mixed>|null $state Fixture state.
 * @param array<int,int>           $target_ids Targets created by this run.
 * @return void
 */
function clone_contract_cleanup( $state, $target_ids = array() ) {
	global $wpdb;
	foreach ( array_unique( array_map( 'intval', $target_ids ) ) as $target_id ) {
		if ( $target_id > 0 ) {
			if ( is_sticky( $target_id ) ) {
				unstick_post( $target_id );
			}
			wp_delete_post( $target_id, true );
		}
	}
	if ( ! is_array( $state ) ) {
		delete_option( 'clone_contract_fixture_targets' );
		return;
	}
	foreach ( array( 'child_id', 'source_id', 'sticky_source_id', 'parent_id', 'attachment_id', 'revision_id' ) as $key ) {
		if ( ! empty( $state[ $key ] ) ) {
			if ( is_sticky( (int) $state[ $key ] ) ) {
				unstick_post( (int) $state[ $key ] );
			}
			wp_delete_post( (int) $state[ $key ], true );
		}
	}
	if ( ! empty( $state['comment_id'] ) ) {
		wp_delete_comment( (int) $state['comment_id'], true );
	}
	foreach ( array( 'topic_parent', 'topic_child', 'label_one', 'label_two', 'category_one', 'category_two', 'tag_one', 'tag_two' ) as $key ) {
		if ( ! empty( $state[ $key ] ) ) {
			$term = get_term( (int) $state[ $key ] );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_delete_term( (int) $state[ $key ], $term->taxonomy );
			}
		}
	}
	if ( ! empty( $state['table'] ) && preg_match( '/^[A-Za-z0-9_]+$/', $state['table'] ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$state['table']}`" );
	}
	delete_option( 'clone_contract_fixture_state' );
	delete_option( 'clone_contract_fixture_targets' );
}

/**
 * Register product-like post types used solely to exercise the hard deny list.
 *
 * @return void
 */
function clone_contract_register_unsupported_types() {
	foreach ( array( 'product', 'product_variation' ) as $post_type ) {
		if ( post_type_exists( $post_type ) ) {
			continue;
		}
		register_post_type(
			$post_type,
			array(
				'public'       => true,
				'show_ui'      => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
	}
}

/**
 * Create a source in a denied post type.
 *
 * @param string $post_type Post type.
 * @param int    $user_id   Author.
 * @return int
 */
function clone_contract_denied_source( $post_type, $user_id ) {
	return clone_contract_insert_post(
		array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => 'Denied source ' . $post_type . ' ' . wp_generate_password( 5, false, false ),
			'post_author' => $user_id,
		)
	);
}

/**
 * Whether this disposable site can exercise the optional native WooCommerce path.
 *
 * @return bool
 */
function clone_contract_woocommerce_available() {
	return function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Simple' ) && class_exists( 'WC_Admin_Duplicate_Product' );
}

/**
 * Create one disposable native product without requiring WooCommerce in every
 * contract environment.
 *
 * @param array<string,mixed> $state Fixture state.
 * @return int
 */
function clone_contract_create_woocommerce_product( $state ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Woo contract source ' . $state['suffix'] );
	$product->set_status( 'publish' );
	$product->set_description( 'Saved WooCommerce product description.' );
	$product->set_short_description( 'Saved WooCommerce product excerpt.' );
	$product->set_regular_price( '19.99' );
	$product->set_image_id( (int) $state['attachment_id'] );
	$product->set_manage_stock( false );
	$product->save();
	return (int) $product->get_id();
}

/**
 * Build the supported post-level overlay for a native WooCommerce product.
 *
 * @param int                  $source_id WooCommerce source ID.
 * @param array<string,mixed>  $state     Fixture state.
 * @return array<string,mixed>
 */
function clone_contract_woocommerce_body( $source_id, $state ) {
	return array(
		'source_id'  => $source_id,
		'request_id' => wp_generate_uuid4(),
		'editor'     => 'block',
		'copy_title' => 'Unsaved Woo clone title ' . $state['suffix'],
		'edited'     => array(
			'title'          => 'Unsaved Woo clone title ' . $state['suffix'],
			'content'        => 'Unsaved WooCommerce product description.',
			'excerpt'        => 'Unsaved WooCommerce product excerpt.',
			'featured_media' => 0,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'parent'         => 0,
			'menu_order'     => 0,
			'taxonomies'     => array(),
		),
	);
}

/**
 * Build a request body for the frozen endpoint schema.
 *
 * @param array<string,mixed> $state Fixture state.
 * @return array<string,mixed>
 */
function clone_contract_success_body( $state ) {
	return array(
		'source_id'  => (int) $state['source_id'],
		'request_id' => wp_generate_uuid4(),
		'editor'     => 'block',
		'copy_title' => 'Unsaved clone title ' . $state['suffix'],
		'edited'     => array(
			'title'          => 'Unsaved clone title ' . $state['suffix'],
			'content'        => '<!-- wp:paragraph --><p>Unsaved overlay content.</p><!-- /wp:paragraph -->',
			'excerpt'        => 'Unsaved overlay excerpt.',
			'featured_media' => (int) $state['attachment_id'],
			'comment_status' => 'closed',
			'ping_status'    => 'open',
			'format'         => 'quote',
			'template'       => 'contract-template.php',
			'taxonomies'     => array(
				'categories' => array( (int) $state['category_two'] ),
				'tags'       => array( (int) $state['tag_two'] ),
			),
			'meta'           => array(
				'clone_contract_allowed'    => 'unsaved allowlisted value',
				'clone_contract_repeated'   => array( 'unsaved repeated A', 'unsaved repeated B' ),
				'clone_contract_serialized' => array( 'saved' => false, 'kind' => 'unsaved object' ),
			),
		),
	);
}

/**
 * Build a request that leaves the source format untouched in the overlay.
 *
 * @param array<string,mixed> $state Fixture state.
 * @return array<string,mixed>
 */
function clone_contract_format_fallback_body( $state ) {
	$body = clone_contract_success_body( $state );
	unset( $body['edited']['format'] );
	$body['request_id'] = wp_generate_uuid4();
	$body['copy_title'] = 'Format fallback clone ' . $state['suffix'];
	$body['edited']['title'] = $body['copy_title'];
	return $body;
}

/**
 * Build the small post fixture body used to exercise sticky post state.
 *
 * @param array<string,mixed> $state  Fixture state.
 * @param bool|null           $sticky Optional unsaved sticky value.
 * @return array<string,mixed>
 */
function clone_contract_sticky_body( $state, $sticky = null ) {
	$edited = array(
		'title'          => 'Sticky clone title ' . $state['suffix'],
		'content'        => 'Sticky clone content.',
		'excerpt'        => '',
		'featured_media' => 0,
		'comment_status' => 'open',
		'ping_status'    => 'closed',
		'format'         => '',
		'template'       => '',
		'parent'         => 0,
		'menu_order'     => 0,
		'taxonomies'     => array(),
	);
	if ( null !== $sticky ) {
		$edited['sticky'] = $sticky;
	}

	return array(
		'source_id'  => (int) $state['sticky_source_id'],
		'request_id' => wp_generate_uuid4(),
		'editor'     => 'block',
		'copy_title' => $edited['title'],
		'edited'     => $edited,
	);
}

/**
 * Run the positive and negative contract matrix.
 *
 * @param array<string,mixed> $state Fixture state.
 * @return array<string,mixed>
 */
function clone_contract_run( $state ) {
	$result = array(
		'ok'         => true,
		'contract'   => 'clone-post-unsaved-changes/v1/drafts',
		'assertions' => array(),
		'failures'   => array(),
		'target_ids' => array(),
		'environment'=> array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'user_id'   => (int) $state['user_id'],
		),
	);

	$routes = rest_get_server()->get_routes();
	$route_ready = isset( $routes['/clone-post-unsaved-changes/v1/drafts'] );
	$result['environment']['route_registered'] = $route_ready;
	if ( ! $route_ready ) {
		clone_contract_assert(
			$result,
			'endpoint route is registered',
			false,
			array( 'hint' => 'Activate the plugin implementation before running this fixture.' )
		);
		return $result;
	}

	$source_before = clone_contract_snapshot( (int) $state['source_id'] );
	$body = clone_contract_success_body( $state );
	$before_count = clone_contract_count_posts();
	$response = clone_contract_dispatch( $body, $state['user_id'], $state['nonce'] );
	$info = clone_contract_response_info( $response );
	$result['responses']['success'] = $info;
	$target_id = ! empty( $info['data']['id'] ) ? (int) $info['data']['id'] : 0;
	if ( $target_id ) {
		$result['target_ids'][] = $target_id;
	}
	clone_contract_assert( $result, 'positive request returns a target ID', $target_id > 0, $info );
	clone_contract_assert(
		$result,
		'positive request creates exactly one post',
		clone_contract_count_posts() === $before_count + ( $target_id ? 1 : 0 ),
		array( 'before' => $before_count, 'after' => clone_contract_count_posts() )
	);

	if ( $target_id ) {
		$target = clone_contract_snapshot( $target_id );
		clone_contract_assert( $result, 'target is a draft', 'draft' === $target['post_status'], $target['post_status'] );
		clone_contract_assert( $result, 'target keeps the source post type', 'clone_contract_item' === $target['post_type'], $target['post_type'] );
		clone_contract_assert(
			$result,
			'target wp_posts row contains the draft overlay',
			isset( $target['db']['post'] ) && 'draft' === $target['db']['post']['post_status'] && $body['copy_title'] === $target['db']['post']['post_title'] && $body['edited']['content'] === $target['db']['post']['post_content'],
			$target['db']['post']
		);
		clone_contract_assert( $result, 'edited title is applied', $body['copy_title'] === $target['post_title'], $target['post_title'] );
		clone_contract_assert( $result, 'edited content is applied', $body['edited']['content'] === $target['post_content'], $target['post_content'] );
		clone_contract_assert( $result, 'edited excerpt is applied', $body['edited']['excerpt'] === $target['post_excerpt'], $target['post_excerpt'] );
		clone_contract_assert( $result, 'source parent is copied', $source_before['post_parent'] === $target['post_parent'], array( 'source' => $source_before['post_parent'], 'target' => $target['post_parent'] ) );
		clone_contract_assert( $result, 'source menu order is copied', $source_before['menu_order'] === $target['menu_order'], array( 'source' => $source_before['menu_order'], 'target' => $target['menu_order'] ) );
		clone_contract_assert( $result, 'edited discussion settings are applied', 'closed' === $target['comment_status'] && 'open' === $target['ping_status'], array( 'comment' => $target['comment_status'], 'ping' => $target['ping_status'] ) );
		clone_contract_assert( $result, 'source template is copied', $source_before['template'] === $target['template'], array( 'source' => $source_before['template'], 'target' => $target['template'] ) );
		clone_contract_assert( $result, 'source format is copied', $source_before['format'] === $target['format'], array( 'source' => $source_before['format'], 'target' => $target['format'] ) );
		clone_contract_assert( $result, 'featured-media reference is reused', $source_before['thumbnail'] === $target['thumbnail'] && $target['thumbnail'] === (int) $state['attachment_id'], array( 'source' => $source_before['thumbnail'], 'target' => $target['thumbnail'] ) );
		clone_contract_assert( $result, 'source password is not copied', '' === $target['post_password'], $target['post_password'] );
		clone_contract_assert( $result, 'source slug is not reused', $source_before['post_name'] !== $target['post_name'], array( 'source' => $source_before['post_name'], 'target' => $target['post_name'] ) );
		clone_contract_assert( $result, 'saved custom taxonomy sets are copied dynamically', $source_before['terms']['clone_contract_topic'] === $target['terms']['clone_contract_topic'] && $source_before['terms']['clone_contract_label'] === $target['terms']['clone_contract_label'], array( 'source' => $source_before['terms'], 'target' => $target['terms'] ) );
		clone_contract_assert( $result, 'edited core taxonomy sets are applied', array( (int) $state['category_two'] ) === $target['terms']['category'] && array( (int) $state['tag_two'] ) === $target['terms']['post_tag'], array( 'target' => $target['terms'] ) );
		clone_contract_assert( $result, 'allowlisted single meta is replaced', array( 'unsaved allowlisted value' ) === $target['meta']['clone_contract_allowed'], $target['meta']['clone_contract_allowed'] );
		clone_contract_assert( $result, 'allowlisted repeated meta preserves every row', array( 'unsaved repeated A', 'unsaved repeated B' ) === $target['meta']['clone_contract_repeated'], $target['meta']['clone_contract_repeated'] );
		clone_contract_assert( $result, 'allowlisted serialized meta is replaced', array( array( 'saved' => false, 'kind' => 'unsaved object' ) ) === $target['meta']['clone_contract_serialized'], $target['meta']['clone_contract_serialized'] );
		clone_contract_assert( $result, 'filter-narrowed meta excludes another registered key', ! isset( $target['meta']['clone_contract_default'] ), array_keys( $target['meta'] ) );
		clone_contract_assert( $result, 'private and operational meta are absent', ! isset( $target['meta']['clone_contract_private'], $target['meta']['_clone_contract_internal'], $target['meta']['_edit_lock'], $target['meta']['_elementor_data'], $target['meta']['_elementor_edit_mode'] ), array_keys( $target['meta'] ) );
		clone_contract_assert( $result, 'comments are not copied', 0 === $target['comments'], $target['comments'] );
		clone_contract_assert( $result, 'revisions are not copied', 0 === $target['revisions'], $target['revisions'] );
		clone_contract_assert( $result, 'child posts are not copied', empty( $target['children'] ), $target['children'] );
		clone_contract_assert( $result, 'custom-table rows are not copied', empty( $target['custom_rows'] ), $target['custom_rows'] );
	}

	// With no narrowing filter installed, a registered show_in_rest key is
	// copied by the default metadata contract, including an unsaved overlay.
	$default_meta_body = clone_contract_success_body( $state );
	$default_meta_body['request_id'] = wp_generate_uuid4();
	$default_meta_body['copy_title'] = 'Default registered meta clone ' . $state['suffix'];
	$default_meta_body['edited']['title'] = $default_meta_body['copy_title'];
	$default_meta_body['edited']['meta'] = array( 'clone_contract_default' => 'unsaved default value' );
	remove_filter( 'clone_post_unsaved_changes_allowed_meta_keys', 'clone_contract_allowed_meta_keys', 10 );
	try {
		$default_meta_response = clone_contract_response_info( clone_contract_dispatch( $default_meta_body, $state['user_id'], $state['nonce'] ) );
	} finally {
		add_filter( 'clone_post_unsaved_changes_allowed_meta_keys', 'clone_contract_allowed_meta_keys', 10, 3 );
	}
	$default_meta_target_id = ! empty( $default_meta_response['data']['id'] ) ? (int) $default_meta_response['data']['id'] : 0;
	$result['responses']['default_registered_meta'] = $default_meta_response;
	if ( $default_meta_target_id ) {
		$result['target_ids'][] = $default_meta_target_id;
	}
	$default_meta_target = $default_meta_target_id ? clone_contract_snapshot( $default_meta_target_id ) : array();
	clone_contract_assert(
		$result,
		'registered show_in_rest meta is copied without a narrowing filter',
		$default_meta_target_id > 0 && isset( $default_meta_target['meta']['clone_contract_default'] ) && array( 'unsaved default value' ) === $default_meta_target['meta']['clone_contract_default'],
		array(
			'response' => $default_meta_response,
			'meta'     => isset( $default_meta_target['meta'] ) ? $default_meta_target['meta'] : array(),
		)
	);

	$format_fallback_body = clone_contract_format_fallback_body( $state );
	$format_fallback      = clone_contract_dispatch( $format_fallback_body, $state['user_id'], $state['nonce'] );
	$format_fallback_info = clone_contract_response_info( $format_fallback );
	$format_fallback_id   = ! empty( $format_fallback_info['data']['id'] ) ? (int) $format_fallback_info['data']['id'] : 0;
	$result['responses']['format_fallback'] = $format_fallback_info;
	$format_fallback_target = $format_fallback_id ? clone_contract_snapshot( $format_fallback_id ) : array();
	clone_contract_assert(
		$result,
		'source format is copied when not edited',
		$format_fallback_id > 0 && $source_before['format'] === $format_fallback_target['format'],
		array(
			'source' => $source_before['format'],
			'target' => isset( $format_fallback_target['format'] ) ? $format_fallback_target['format'] : null,
		)
	);
	if ( $format_fallback_id ) {
		$result['target_ids'][] = $format_fallback_id;
	}
	$invalid_format = $body;
	$invalid_format['request_id'] = wp_generate_uuid4();
	$invalid_format['edited']['format'] = 'clone-contract-invalid-format';
	clone_contract_expect_rejected( $result, 'invalid format leaves no target', $invalid_format, $state );

	$sticky_before = clone_contract_snapshot( (int) $state['sticky_source_id'] );
	$sticky_fallback = clone_contract_dispatch( clone_contract_sticky_body( $state ), $state['user_id'], $state['nonce'] );
	$sticky_fallback_info = clone_contract_response_info( $sticky_fallback );
	$sticky_fallback_id = ! empty( $sticky_fallback_info['data']['id'] ) ? (int) $sticky_fallback_info['data']['id'] : 0;
	$sticky_fallback_target = $sticky_fallback_id ? clone_contract_snapshot( $sticky_fallback_id ) : array();
	$result['responses']['sticky_fallback'] = $sticky_fallback_info;
	clone_contract_assert( $result, 'saved sticky state is copied', $sticky_fallback_id > 0 && true === $sticky_fallback_target['sticky'], $sticky_fallback_target );
	if ( $sticky_fallback_id ) {
		$result['target_ids'][] = $sticky_fallback_id;
	}

	$sticky_override = clone_contract_sticky_body( $state, false );
	$sticky_override_response = clone_contract_dispatch( $sticky_override, $state['user_id'], $state['nonce'] );
	$sticky_override_info = clone_contract_response_info( $sticky_override_response );
	$sticky_override_id = ! empty( $sticky_override_info['data']['id'] ) ? (int) $sticky_override_info['data']['id'] : 0;
	$sticky_override_target = $sticky_override_id ? clone_contract_snapshot( $sticky_override_id ) : array();
	$result['responses']['sticky_override'] = $sticky_override_info;
	clone_contract_assert( $result, 'unsaved sticky change is applied', $sticky_override_id > 0 && false === $sticky_override_target['sticky'], $sticky_override_target );
	if ( $sticky_override_id ) {
		$result['target_ids'][] = $sticky_override_id;
	}
	$sticky_after = clone_contract_snapshot( (int) $state['sticky_source_id'] );
	clone_contract_assert( $result, 'sticky source remains unchanged', serialize( $sticky_before ) === serialize( $sticky_after ), array( 'before' => $sticky_before, 'after' => $sticky_after ) );

	$source_after = clone_contract_snapshot( (int) $state['source_id'] );
	clone_contract_assert( $result, 'source row and owned state remain unchanged', serialize( $source_before ) === serialize( $source_after ), array( 'before' => $source_before, 'after' => $source_after ) );
	clone_contract_assert( $result, 'direct SQL source rows remain unchanged', serialize( $source_before['db'] ) === serialize( $source_after['db'] ), array( 'before' => $source_before['db'], 'after' => $source_after['db'] ) );

	// The same body, including request_id, must return the original target and
	// must not insert another draft.
	$replay_before = clone_contract_count_posts();
	$replay = clone_contract_dispatch( $body, $state['user_id'], $state['nonce'] );
	$replay_info = clone_contract_response_info( $replay );
	$result['responses']['replay'] = $replay_info;
	$replay_id = ! empty( $replay_info['data']['id'] ) ? (int) $replay_info['data']['id'] : 0;
	clone_contract_assert( $result, 'same request_id replays the same target', $target_id > 0 && $replay_id === $target_id, array( 'first' => $target_id, 'replay' => $replay_id, 'response' => $replay_info ) );
	clone_contract_assert( $result, 'same request_id creates no duplicate', $replay_before === clone_contract_count_posts(), array( 'before' => $replay_before, 'after' => clone_contract_count_posts() ) );

	$conflict = $body;
	$conflict['copy_title'] = 'Conflicting request payload';
	$conflict_info = clone_contract_response_info( clone_contract_dispatch( $conflict, $state['user_id'], $state['nonce'] ) );
	$result['responses']['idempotency_conflict'] = $conflict_info;
	clone_contract_assert( $result, 'same request_id with a different payload is rejected', ! empty( $conflict_info['error'] ) || $conflict_info['status'] >= 400, $conflict_info );

	$idempotency_option = 'clone_post_unsaved_changes_' . md5( $body['request_id'] );
	$idempotency_record = get_option( $idempotency_option, array() );
	clone_contract_assert(
		$result,
		'idempotency records have bounded retention',
		isset( $idempotency_record['expires_at'] ) && (int) $idempotency_record['expires_at'] > time(),
		$idempotency_record
	);
	clone_contract_assert(
		$result,
		'idempotency cleanup is scheduled at reservation time',
		false !== wp_next_scheduled( 'clone_post_unsaved_changes_expire_idempotency', array( $idempotency_option ) ),
		wp_next_scheduled( 'clone_post_unsaved_changes_expire_idempotency', array( $idempotency_option ) )
	);
	$idempotency_record['expires_at'] = time() - 1;
	update_option( $idempotency_option, $idempotency_record, false );
	do_action( 'clone_post_unsaved_changes_expire_idempotency', $idempotency_option );
	clone_contract_assert(
		$result,
		'expired idempotency records are removed',
		false === get_option( $idempotency_option, false ),
		get_option( $idempotency_option, false )
	);
	wp_clear_scheduled_hook( 'clone_post_unsaved_changes_expire_idempotency', array( $idempotency_option ) );

	clone_contract_register_unsupported_types();
	if ( clone_contract_woocommerce_available() ) {
		$result['environment']['woocommerce_editor'] = function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'product' ) ? 'block' : 'classic';
		$result['environment']['woocommerce_adapter_test'] = 'enabled (forced block-editor contract)';
		$woo_source_id = clone_contract_create_woocommerce_product( $state );
		$woo_before    = clone_contract_snapshot( $woo_source_id );
		$woo_body      = clone_contract_woocommerce_body( $woo_source_id, $state );
		$force_product_block_editor = static function ( $use_block_editor, $post_type ) {
			return 'product' === $post_type ? true : $use_block_editor;
		};
		add_filter( 'use_block_editor_for_post_type', $force_product_block_editor, 10, 2 );
		try {
			$woo_response = clone_contract_response_info( clone_contract_dispatch( $woo_body, $state['user_id'], $state['nonce'] ) );
		} finally {
			remove_filter( 'use_block_editor_for_post_type', $force_product_block_editor, 10 );
		}
		$woo_target_id = ! empty( $woo_response['data']['id'] ) ? (int) $woo_response['data']['id'] : 0;
		$result['responses']['woocommerce'] = $woo_response;
		clone_contract_assert( $result, 'WooCommerce product returns a native draft target', $woo_target_id > 0, $woo_response );
		clone_contract_assert( $result, 'WooCommerce product response has the adapter marker', isset( $woo_response['data']['adapter'] ) && 'woocommerce' === $woo_response['data']['adapter'], $woo_response );
		if ( $woo_target_id ) {
			$result['target_ids'][] = $woo_target_id;
			$woo_target         = clone_contract_snapshot( $woo_target_id );
			$woo_target_product = wc_get_product( $woo_target_id );
				clone_contract_assert( $result, 'WooCommerce target is a draft', 'draft' === $woo_target['post_status'], $woo_target );
				clone_contract_assert( $result, 'WooCommerce saved product data uses native duplication', $woo_target_product && '19.99' === $woo_target_product->get_regular_price(), $woo_target_product ? $woo_target_product->get_regular_price() : null );
				clone_contract_assert( $result, 'WooCommerce supported core overlay is applied', $woo_body['copy_title'] === $woo_target['post_title'] && $woo_body['edited']['content'] === $woo_target['post_content'] && $woo_body['edited']['excerpt'] === $woo_target['post_excerpt'], $woo_target );
				clone_contract_assert( $result, 'WooCommerce featured-media removal overlay is applied', 0 === $woo_target['thumbnail'], $woo_target['thumbnail'] );
		}
		$woo_after = clone_contract_snapshot( $woo_source_id );
		clone_contract_assert( $result, 'WooCommerce source remains unchanged', serialize( $woo_before ) === serialize( $woo_after ), array( 'before' => $woo_before, 'after' => $woo_after ) );
		$woo_dirty = $woo_body;
		$woo_dirty['request_id'] = wp_generate_uuid4();
		$woo_dirty['edited']['regular_price'] = '1.00';
		clone_contract_expect_rejected( $result, 'unsaved WooCommerce-specific fields are rejected before insertion', $woo_dirty, $state );
		wp_delete_post( $woo_source_id, true );
	} else {
		$result['environment']['woocommerce_adapter_test'] = 'skipped: WooCommerce native APIs are unavailable';
		$denied_id = clone_contract_denied_source( 'product', $state['user_id'] );
		$denied = $body;
		$denied['source_id'] = $denied_id;
		$denied['request_id'] = wp_generate_uuid4();
		clone_contract_expect_rejected( $result, 'product sources are explicitly unsupported without WooCommerce', $denied, $state );
		wp_delete_post( $denied_id, true );
	}
	foreach ( array( 'product_variation' ) as $post_type ) {
		$denied_id = clone_contract_denied_source( $post_type, $state['user_id'] );
		$denied = $body;
		$denied['source_id'] = $denied_id;
		$denied['request_id'] = wp_generate_uuid4();
		clone_contract_expect_rejected( $result, $post_type . ' sources are explicitly unsupported', $denied, $state );
		wp_delete_post( $denied_id, true );
	}

	$elementor_id = clone_contract_insert_post(
		array(
			'post_type'   => 'clone_contract_item',
			'post_status' => 'publish',
			'post_title'  => 'Elementor document fixture ' . $state['suffix'],
			'post_author' => $state['user_id'],
		)
	);
	update_post_meta( $elementor_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $elementor_id, '_elementor_data', '[]' );
	$elementor = $body;
	$elementor['source_id'] = $elementor_id;
	$elementor['request_id'] = wp_generate_uuid4();
	clone_contract_expect_rejected( $result, 'Elementor document enters no generic clone', $elementor, $state );
	wp_delete_post( $elementor_id, true );

	$elementor_mode = $body;
	$elementor_mode['request_id'] = wp_generate_uuid4();
	$elementor_mode['editor'] = 'elementor';
	clone_contract_expect_rejected( $result, 'Elementor editor mode is blocked without a pinned adapter', $elementor_mode, $state );

	$elementor_reserved = $body;
	$elementor_reserved['request_id'] = wp_generate_uuid4();
	$elementor_reserved['editor'] = 'elementor';
	$elementor_reserved['edited'] = array(
		'elementor' => array(
			'elements' => array(),
			'settings' => array( 'post_status' => 'publish' ),
		),
	);
	clone_contract_expect_rejected( $result, 'Elementor reserved settings are rejected before insertion', $elementor_reserved, $state );

	$elementor_malformed = $body;
	$elementor_malformed['request_id'] = wp_generate_uuid4();
	$elementor_malformed['editor'] = 'elementor';
	$elementor_malformed['edited'] = array( 'elementor' => array( 'elements' => array() ) );
	clone_contract_expect_rejected( $result, 'Malformed Elementor snapshots are rejected before insertion', $elementor_malformed, $state );

	foreach ( array( 'trash', 'auto-draft' ) as $status ) {
		$status_id = clone_contract_insert_post(
			array(
				'post_type'   => 'clone_contract_item',
				'post_status' => $status,
				'post_title'  => 'Unsupported ' . $status . ' fixture',
				'post_author' => $state['user_id'],
			)
		);
		$status_body = $body;
		$status_body['source_id'] = $status_id;
		$status_body['request_id'] = wp_generate_uuid4();
		clone_contract_expect_rejected( $result, $status . ' sources enter no clone', $status_body, $state );
		wp_delete_post( $status_id, true );
	}

	$attachment_body = $body;
	$attachment_body['source_id'] = (int) $state['attachment_id'];
	$attachment_body['request_id'] = wp_generate_uuid4();
	clone_contract_expect_rejected( $result, 'attachment sources enter no clone', $attachment_body, $state );

	$revision_body = $body;
	$revision_body['source_id'] = (int) $state['revision_id'];
	$revision_body['request_id'] = wp_generate_uuid4();
	clone_contract_expect_rejected( $result, 'revision sources enter no clone', $revision_body, $state );

	$unknown_field = $body;
	$unknown_field['request_id'] = wp_generate_uuid4();
	$unknown_field['edited']['unknown_field'] = 'must reject';
	clone_contract_expect_rejected( $result, 'unsupported dirty field is rejected before insertion', $unknown_field, $state );

	$unknown_taxonomy = $body;
	$unknown_taxonomy['request_id'] = wp_generate_uuid4();
	$unknown_taxonomy['edited']['taxonomies']['clone_contract_unknown'] = array( 1 );
	clone_contract_expect_rejected( $result, 'unsupported dirty taxonomy is rejected before insertion', $unknown_taxonomy, $state );

	$invalid_term = $body;
	$invalid_term['request_id'] = wp_generate_uuid4();
	$invalid_term['edited']['taxonomies']['categories'] = array( 999999999 );
	clone_contract_expect_rejected( $result, 'invalid term failure leaves no target', $invalid_term, $state );

	$private_meta = $body;
	$private_meta['request_id'] = wp_generate_uuid4();
	$private_meta['edited']['meta']['clone_contract_private'] = 'must reject';
	clone_contract_expect_rejected( $result, 'unallowlisted meta is rejected before insertion', $private_meta, $state );

	$missing_source = $body;
	$missing_source['source_id'] = 999999999;
	$missing_source['request_id'] = wp_generate_uuid4();
	clone_contract_expect_rejected( $result, 'missing source is rejected before insertion', $missing_source, $state );

	return $result;
}

/**
 * Main action dispatcher.
 */
$clone_contract_state = null;
$clone_contract_targets = array();
$clone_contract_result = array(
	'ok'         => true,
	'action'     => $clone_contract_action,
	'assertions' => array(),
	'failures'   => array(),
);

try {
	if ( 'teardown' === $clone_contract_action ) {
		$clone_contract_state = get_option( 'clone_contract_fixture_state', null );
		$clone_contract_targets = get_option( 'clone_contract_fixture_targets', array() );
		clone_contract_cleanup( $clone_contract_state, $clone_contract_targets );
		$clone_contract_result['message'] = 'Fixture state and rows removed.';
	} else {
		$clone_contract_state = clone_contract_create_fixture();
		$clone_contract_result['fixture'] = $clone_contract_state;
		if ( 'setup' === $clone_contract_action ) {
			$clone_contract_result['message'] = 'Fixture rows created; rerun with CLONE_CONTRACT_ACTION=run.';
		} else {
			$clone_contract_result = array_merge( $clone_contract_result, clone_contract_run( $clone_contract_state ) );
			$clone_contract_targets = isset( $clone_contract_result['target_ids'] ) ? $clone_contract_result['target_ids'] : array();
			if ( $clone_contract_keep ) {
				update_option( 'clone_contract_fixture_targets', $clone_contract_targets, false );
			}
		}
	}
} catch ( Throwable $clone_contract_error ) {
	$clone_contract_result['ok'] = false;
	$clone_contract_result['error'] = array(
		'class'   => get_class( $clone_contract_error ),
		'message' => $clone_contract_error->getMessage(),
		'file'    => $clone_contract_error->getFile(),
		'line'    => $clone_contract_error->getLine(),
	);
}

if ( ! $clone_contract_keep && 'setup' !== $clone_contract_action && 'teardown' !== $clone_contract_action ) {
	clone_contract_cleanup( $clone_contract_state, $clone_contract_targets );
	$clone_contract_result['cleanup'] = 'completed';
} elseif ( 'setup' === $clone_contract_action ) {
	$clone_contract_result['cleanup'] = 'skipped (CLONE_CONTRACT_ACTION=setup)';
} else {
	$clone_contract_result['cleanup'] = 'kept (set CLONE_CONTRACT_ACTION=teardown to remove)';
}

echo wp_json_encode( $clone_contract_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( empty( $clone_contract_result['ok'] ) ) {
	exit( 1 );
}
