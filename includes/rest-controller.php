<?php
/**
 * REST controller for server-owned draft cloning.
 *
 * @package ClonePostUnsavedChanges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'clone_post_unsaved_changes_register_rest_routes' );
add_action(
	'clone_post_unsaved_changes_expire_idempotency',
	'clone_post_unsaved_changes_expire_idempotency_option'
);

/**
 * Register the private draft-cloning endpoint.
 *
 * @return void
 */
function clone_post_unsaved_changes_register_rest_routes() {
	register_rest_route(
		'clone-post-unsaved-changes/v1',
		'/drafts',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'clone_post_unsaved_changes_create_draft',
			'permission_callback' => 'clone_post_unsaved_changes_can_create_draft',
		)
	);
}

/**
 * Check source and target capabilities before cloning.
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_can_create_draft( WP_REST_Request $request ) {
	$source_id = absint( $request->get_param( 'source_id' ) );
	$source    = get_post( $source_id );

	if ( ! $source || ! current_user_can( 'edit_post', $source_id ) ) {
		return new WP_Error(
			'clone_post_unsaved_changes_forbidden_source',
			__( 'You cannot copy this post.', 'clone-post-unsaved-changes' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	$post_type = get_post_type_object( $source->post_type );
	$cap       = $post_type && ! empty( $post_type->cap->create_posts )
		? $post_type->cap->create_posts
		: ( $post_type ? $post_type->cap->edit_posts : 'edit_posts' );

	if ( ! current_user_can( $cap ) ) {
		return new WP_Error(
			'clone_post_unsaved_changes_forbidden_target',
			__( 'You cannot create a draft of this post type.', 'clone-post-unsaved-changes' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	return true;
}

/**
 * Create a draft from a saved source post plus a constrained editor overlay.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function clone_post_unsaved_changes_create_draft( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : $request->get_params();
	$valid  = clone_post_unsaved_changes_validate_request( $params );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$source_id = $valid['source_id'];
	$source    = get_post( $source_id );
	$type      = get_post_type_object( $source->post_type );
	$eligible  = clone_post_unsaved_changes_validate_source( $source, $type, $valid['editor'] );

	if ( is_wp_error( $eligible ) ) {
		return $eligible;
	}
	$meta_valid = clone_post_unsaved_changes_validate_meta_overlay( $valid['edited'], $source );
	if ( is_wp_error( $meta_valid ) ) {
		return $meta_valid;
	}
	$adapter = 'product' === $source->post_type ? 'woocommerce' : ( 'elementor' === $valid['editor'] ? 'elementor' : 'core' );
	if ( 'woocommerce' === $adapter ) {
		$woocommerce_overlay = clone_post_unsaved_changes_validate_woocommerce_overlay( $valid['edited'] );
		if ( is_wp_error( $woocommerce_overlay ) ) {
			return $woocommerce_overlay;
		}
	}

	$canonical = clone_post_unsaved_changes_canonicalize(
		array(
			'source_id'  => $valid['source_id'],
			'copy_title' => $valid['copy_title'],
			'editor'     => $valid['editor'],
			'edited'     => $valid['edited'],
		)
	);
	$hash      = hash( 'sha256', wp_json_encode( $canonical ) );
	$option    = 'clone_post_unsaved_changes_' . md5( $valid['request_id'] );
	$existing  = get_option( $option, false );
	$expires_at = time() + DAY_IN_SECONDS;

	if ( false !== $existing ) {
		if ( clone_post_unsaved_changes_idempotency_is_expired( $existing ) ) {
			delete_option( $option );
			$existing = false;
		} else {
			return clone_post_unsaved_changes_existing_request_response( $existing, $hash );
		}
	}

	// add_option is atomic, which prevents two simultaneous requests with the
	// same idempotency key from creating two drafts.
	if ( ! add_option( $option, array( 'hash' => $hash, 'target_id' => 0, 'expires_at' => $expires_at ), '', 'no' ) ) {
		$existing = get_option( $option, false );
		if ( clone_post_unsaved_changes_idempotency_is_expired( $existing ) ) {
			delete_option( $option );
			if ( ! add_option( $option, array( 'hash' => $hash, 'target_id' => 0, 'expires_at' => $expires_at ), '', 'no' ) ) {
				return clone_post_unsaved_changes_existing_request_response( get_option( $option, false ), $hash );
			}
		} else {
			return clone_post_unsaved_changes_existing_request_response( $existing, $hash );
		}
	}
	// Schedule cleanup as soon as the reservation exists, so an interrupted
	// request cannot leave an orphaned idempotency option indefinitely.
	wp_schedule_single_event( $expires_at, 'clone_post_unsaved_changes_expire_idempotency', array( $option ) );

	$target_id = 'woocommerce' === $adapter
		? clone_post_unsaved_changes_insert_woocommerce_draft( $source, $valid )
		: clone_post_unsaved_changes_insert_draft( $source, $valid );
	if ( is_wp_error( $target_id ) ) {
		delete_option( $option );
		return $target_id;
	}

	if ( 'elementor' === $valid['editor'] ) {
		$elementor = clone_post_unsaved_changes_clone_elementor_document( $source, $target_id, $valid['edited']['elementor'] );
		if ( is_wp_error( $elementor ) ) {
			wp_delete_post( $target_id, true );
			delete_option( $option );
			return $elementor;
		}
	}

	update_option(
		$option,
		array(
			'hash'      => $hash,
			'target_id' => $target_id,
			'editor'    => $valid['editor'],
			'adapter'   => $adapter,
			'expires_at' => $expires_at,
		),
		false
	);

	return new WP_REST_Response(
		array(
			'id'       => $target_id,
			'edit_url' => clone_post_unsaved_changes_edit_url( $target_id, $valid['editor'] ),
			'editor'   => 'elementor' === $valid['editor'] ? 'elementor' : 'core',
			'adapter'  => $adapter,
		),
		201
	);
}

/**
 * Return whether an idempotency record has passed its bounded retention time.
 *
 * Legacy records without an expiry remain valid so an upgrade cannot create a
 * duplicate for a request made by an earlier plugin version.
 *
 * @param mixed $record Stored idempotency record.
 * @return bool
 */
function clone_post_unsaved_changes_idempotency_is_expired( $record ) {
	return is_array( $record ) && isset( $record['expires_at'] ) && time() >= (int) $record['expires_at'];
}

/**
 * Remove one expired idempotency record from the options table.
 *
 * @param string $option Option name.
 * @return void
 */
function clone_post_unsaved_changes_expire_idempotency_option( $option ) {
	if ( ! is_string( $option ) || 0 !== strpos( $option, 'clone_post_unsaved_changes_' ) ) {
		return;
	}
	$record = get_option( $option, false );
	if ( clone_post_unsaved_changes_idempotency_is_expired( $record ) ) {
		delete_option( $option );
	}
}

/**
 * Validate the JSON request without accepting arbitrary edited post fields.
 *
 * @param array<string, mixed> $params Request params.
 * @return array<string, mixed>|WP_Error
 */
function clone_post_unsaved_changes_validate_request( $params ) {
	$source_id  = isset( $params['source_id'] ) ? absint( $params['source_id'] ) : 0;
	$request_id = isset( $params['request_id'] ) && is_string( $params['request_id'] ) ? $params['request_id'] : '';
	$copy_title = isset( $params['copy_title'] ) && is_string( $params['copy_title'] ) ? $params['copy_title'] : '';
	$editor     = isset( $params['editor'] ) && is_string( $params['editor'] ) ? $params['editor'] : '';
	$edited     = isset( $params['edited'] ) && is_array( $params['edited'] ) ? $params['edited'] : null;

	if ( ! $source_id || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $request_id ) || '' === trim( $copy_title ) || ! in_array( $editor, array( 'block', 'elementor' ), true ) || null === $edited ) {
		return new WP_Error(
			'clone_post_unsaved_changes_invalid_request',
			__( 'The Save As request is invalid.', 'clone-post-unsaved-changes' ),
			array( 'status' => 400 )
		);
	}

	$allowed = array( 'title', 'content', 'excerpt', 'featured_media', 'comment_status', 'ping_status', 'format', 'sticky', 'template', 'parent', 'menu_order', 'taxonomies', 'meta' );
	if ( 'elementor' === $editor ) {
		$allowed[] = 'elementor';
	}
	foreach ( array_keys( $edited ) as $key ) {
		if ( ! in_array( $key, $allowed, true ) ) {
			return new WP_Error(
				'clone_post_unsaved_changes_unsupported_dirty_fields',
				__( 'This post has unsaved fields that Save As cannot safely copy.', 'clone-post-unsaved-changes' ),
				array( 'status' => 400 )
			);
		}
	}

	if ( isset( $edited['taxonomies'] ) && ! is_array( $edited['taxonomies'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'The taxonomy changes are invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( isset( $edited['meta'] ) && ! is_array( $edited['meta'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'The meta changes are invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'elementor' === $editor ) {
		$elementor_valid = isset( $edited['elementor'] ) ? clone_post_unsaved_changes_validate_elementor_payload( $edited['elementor'] ) : new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor document snapshot is missing.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		if ( is_wp_error( $elementor_valid ) ) {
			return $elementor_valid;
		}
	} elseif ( isset( $edited['elementor'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'The Elementor snapshot is only valid for Elementor requests.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	return array(
		'source_id'  => $source_id,
		'request_id' => $request_id,
		'copy_title' => $copy_title,
		'editor'     => $editor,
		'edited'     => $edited,
	);
}

/**
 * Validate the frozen Elementor document snapshot accepted by the pinned adapter.
 *
 * @param mixed $payload Elementor data captured from the current editor.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_validate_elementor_payload( $payload ) {
	if ( ! is_array( $payload ) || array_diff( array_keys( $payload ), array( 'elements', 'settings' ) ) || ! array_key_exists( 'elements', $payload ) || ! array_key_exists( 'settings', $payload ) || ! is_array( $payload['elements'] ) || ! is_array( $payload['settings'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor document snapshot is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	$element_keys = array_keys( $payload['elements'] );
	if ( ! empty( $element_keys ) && $element_keys !== range( 0, count( $element_keys ) - 1 ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor element snapshot is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	$setting_keys = array_keys( $payload['settings'] );
	if ( ! empty( $setting_keys ) && $setting_keys === range( 0, count( $setting_keys ) - 1 ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor settings snapshot is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	foreach ( array( 'ID', 'post_id', 'post_status', 'post_author', 'post_title', 'post_name', 'post_date', 'post_modified', 'guid', 'post_content', 'post_excerpt' ) as $reserved ) {
		if ( array_key_exists( $reserved, $payload['settings'] ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor snapshot contains a protected post field.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}

	$encoded = wp_json_encode( $payload );
	if ( false === $encoded || strlen( $encoded ) > 5 * 1024 * 1024 || ! clone_post_unsaved_changes_elementor_payload_depth_is_safe( $payload, 0 ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_elementor_payload', __( 'The Elementor snapshot is too large or deeply nested.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	return true;
}

/**
 * Keep the pinned Elementor payload bounded before passing it to its API.
 *
 * @param mixed $value Current payload value.
 * @param int   $depth Current nesting depth.
 * @return bool
 */
function clone_post_unsaved_changes_elementor_payload_depth_is_safe( $value, $depth ) {
	if ( $depth > 64 ) {
		return false;
	}
	if ( ! is_array( $value ) ) {
		return true;
	}
	foreach ( $value as $child ) {
		if ( ! clone_post_unsaved_changes_elementor_payload_depth_is_safe( $child, $depth + 1 ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Reject post types and documents that need a dedicated adapter.
 *
 * @param WP_Post      $source Source post.
 * @param WP_Post_Type $type   Source type object.
 * @param string       $editor Requested editor adapter.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_validate_source( $source, $type, $editor ) {
	if ( ! $type || ! $type->public || ! $type->show_in_rest ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_post_type', __( 'This post type is not supported by Save As.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'product_variation' === $source->post_type ) {
		return new WP_Error( 'clone_post_unsaved_changes_woocommerce_unsupported', __( 'WooCommerce products require WooCommerce’s own duplicate workflow.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'product' === $source->post_type ) {
		if ( ! clone_post_unsaved_changes_woocommerce_is_available() ) {
			return new WP_Error( 'clone_post_unsaved_changes_woocommerce_unsupported', __( 'WooCommerce’s product duplicate API is not available.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		if ( 'block' !== $editor ) {
			return new WP_Error( 'clone_post_unsaved_changes_unsupported_editor', __( 'Save As currently supports the block editor only.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		// WooCommerce owns this unprefixed capability filter; mirror its native
		// duplicate workflow while keeping our own globals prefixed.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook.
		$duplicate_cap = apply_filters( 'woocommerce_duplicate_product_capability', 'manage_woocommerce' );
		if ( ! is_string( $duplicate_cap ) || ! current_user_can( $duplicate_cap ) || ! wc_get_product( $source->ID ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_woocommerce_unsupported', __( 'This WooCommerce product cannot be duplicated safely.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}
	if ( 'block' === $editor && function_exists( 'use_block_editor_for_post_type' ) && ! use_block_editor_for_post_type( $source->post_type ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_editor', __( 'Save As currently supports the block editor only.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( in_array( $source->post_status, array( 'trash', 'auto-draft' ), true ) || 'attachment' === $source->post_type || wp_is_post_revision( $source ) || wp_is_post_autosave( $source ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_source', __( 'This source post cannot be copied.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	$is_elementor = 'elementor_library' === $source->post_type || metadata_exists( 'post', $source->ID, '_elementor_data' ) || metadata_exists( 'post', $source->ID, '_elementor_edit_mode' );
	if ( $is_elementor && 'elementor' !== $editor ) {
		return new WP_Error( 'clone_post_unsaved_changes_elementor_unsupported', __( 'Elementor documents require the Elementor Save As adapter.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'elementor' === $editor && ! $is_elementor ) {
		return new WP_Error( 'clone_post_unsaved_changes_elementor_unsupported', __( 'This post is not an Elementor document.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'elementor' === $editor ) {
		$elementor = clone_post_unsaved_changes_validate_elementor_document( $source );
		if ( is_wp_error( $elementor ) ) {
			return $elementor;
		}
	}

	return true;
}

/**
 * Reject changed meta that the server is not explicitly configured to copy.
 *
 * @param array<string, mixed> $edited Editor overlay.
 * @param WP_Post              $source Source post.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_validate_meta_overlay( $edited, $source ) {
	if ( empty( $edited['meta'] ) ) {
		return true;
	}
	foreach ( array_keys( $edited['meta'] ) as $key ) {
		if ( ! is_string( $key ) || ! clone_post_unsaved_changes_meta_is_allowed( $key, $source ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_meta_unsupported', __( 'This post has changed meta that Save As is not configured to copy.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}
	return true;
}

/**
 * Return an idempotent prior response or a conflict.
 *
 * @param mixed  $existing Stored request state.
 * @param string $hash     Canonical payload hash.
 * @return WP_REST_Response|WP_Error
 */
function clone_post_unsaved_changes_existing_request_response( $existing, $hash ) {
	if ( ! is_array( $existing ) || empty( $existing['hash'] ) || ! hash_equals( (string) $existing['hash'], $hash ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_idempotency_conflict', __( 'This request ID was already used for different copy data.', 'clone-post-unsaved-changes' ), array( 'status' => 409 ) );
	}

	$target_id = isset( $existing['target_id'] ) ? absint( $existing['target_id'] ) : 0;
	if ( ! $target_id ) {
		return new WP_Error( 'clone_post_unsaved_changes_request_in_progress', __( 'This Save As request is still being processed.', 'clone-post-unsaved-changes' ), array( 'status' => 409 ) );
	}

	if ( ! get_post( $target_id ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_idempotency_conflict', __( 'This request ID cannot be reused.', 'clone-post-unsaved-changes' ), array( 'status' => 409 ) );
	}

	$editor  = isset( $existing['editor'] ) && 'elementor' === $existing['editor'] ? 'elementor' : 'block';
	$adapter = isset( $existing['adapter'] ) && in_array( $existing['adapter'], array( 'core', 'elementor', 'woocommerce' ), true ) ? $existing['adapter'] : ( 'elementor' === $editor ? 'elementor' : 'core' );

	return new WP_REST_Response(
		array(
			'id'       => $target_id,
			'edit_url' => clone_post_unsaved_changes_edit_url( $target_id, $editor ),
			'editor'   => 'elementor' === $editor ? 'elementor' : 'core',
			'adapter'  => $adapter,
		),
		200
	);
}

/**
 * Check that WooCommerce's documented native product duplicator is loaded.
 *
 * @return bool
 */
function clone_post_unsaved_changes_woocommerce_is_available() {
	return function_exists( 'wc_get_product' ) && class_exists( 'WC_Admin_Duplicate_Product' ) && method_exists( 'WC_Admin_Duplicate_Product', 'product_duplicate' );
}

/**
 * Reject unsaved product-owned fields and core fields with no product meaning.
 *
 * The native WooCommerce duplicator owns product data and meta. This endpoint
 * may overlay only the same post-level fields it already understands safely.
 *
 * @param array<string,mixed> $edited Editor overlay.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_validate_woocommerce_overlay( $edited ) {
	if ( ! empty( $edited['meta'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_meta_unsupported', __( 'Unsaved WooCommerce product meta cannot be copied safely.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( isset( $edited['sticky'] ) && true === clone_post_unsaved_changes_normalize_boolean( $edited['sticky'] ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_dirty_fields', __( 'Sticky status is not supported for WooCommerce products.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( isset( $edited['format'] ) && ! in_array( (string) $edited['format'], array( '', 'standard' ), true ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_dirty_fields', __( 'Post formats are not supported for WooCommerce products.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( isset( $edited['template'] ) && '' !== (string) $edited['template'] ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_dirty_fields', __( 'Post templates are not supported for WooCommerce products.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	return true;
}

/**
 * Duplicate a saved WooCommerce product with WooCommerce's native workflow.
 *
 * @param WP_Post              $source Source product post.
 * @param array<string,mixed>  $valid  Validated request data.
 * @return int|WP_Error
 */
function clone_post_unsaved_changes_insert_woocommerce_draft( $source, $valid ) {
	$source_fingerprint = clone_post_unsaved_changes_elementor_source_fingerprint( $source->ID );
	if ( is_wp_error( $source_fingerprint ) ) {
		return $source_fingerprint;
	}

	$product = wc_get_product( $source->ID );
	if ( ! $product ) {
		return new WP_Error( 'clone_post_unsaved_changes_woocommerce_unsupported', __( 'This WooCommerce product cannot be duplicated safely.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	$target_id        = 0;
	$tracked_duplicate = null;
	$track_duplicate   = static function ( $duplicate, $original ) use ( $source, &$tracked_duplicate ) {
		if ( is_object( $original ) && method_exists( $original, 'get_id' ) && $source->ID === (int) $original->get_id() ) {
			$tracked_duplicate = $duplicate;
		}
	};
	$track_new_product = static function ( $product_id, $duplicate ) use ( &$target_id, &$tracked_duplicate ) {
		if ( is_object( $tracked_duplicate ) && $duplicate === $tracked_duplicate ) {
			$target_id = absint( $product_id );
		}
	};
	add_action( 'woocommerce_product_duplicate_before_save', $track_duplicate, PHP_INT_MAX, 2 );
	add_action( 'woocommerce_new_product', $track_new_product, PHP_INT_MAX, 2 );

	try {
		$duplicator = new WC_Admin_Duplicate_Product();
		$duplicate  = $duplicator->product_duplicate( $product );
		if ( ! is_object( $duplicate ) || ! method_exists( $duplicate, 'get_id' ) ) {
			$error = new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce could not create the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
		} else {
			$target_id = absint( $duplicate->get_id() );
			$error     = 0 === $target_id ? new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce could not create the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) ) : null;
		}
	} catch ( Throwable $exception ) {
		$error = new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce could not create the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	remove_action( 'woocommerce_product_duplicate_before_save', $track_duplicate, PHP_INT_MAX );
	remove_action( 'woocommerce_new_product', $track_new_product, PHP_INT_MAX );

	if ( isset( $error ) && is_wp_error( $error ) ) {
		clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id );
		return $error;
	}

	$target = get_post( $target_id );
	if ( ! $target || 'product' !== $target->post_type || 'draft' !== $target->post_status ) {
		clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id );
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce changed the new draft unexpectedly.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	$source_unchanged = clone_post_unsaved_changes_elementor_source_unchanged( $source->ID, $source_fingerprint );
	if ( is_wp_error( $source_unchanged ) ) {
		clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id );
		return $source_unchanged;
	}

	$overlay = clone_post_unsaved_changes_apply_woocommerce_core_overlay( $target_id, $source, $valid );
	if ( is_wp_error( $overlay ) ) {
		clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id );
		return $overlay;
	}
	$source_unchanged = clone_post_unsaved_changes_elementor_source_unchanged( $source->ID, $source_fingerprint );
	if ( is_wp_error( $source_unchanged ) ) {
		clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id );
		return $source_unchanged;
	}

	return $target_id;
}

/**
 * Apply supported post-level edits without touching WooCommerce-owned data.
 *
 * @param int                  $target_id Target product ID.
 * @param WP_Post              $source    Source product post.
 * @param array<string,mixed>  $valid     Validated request data.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_apply_woocommerce_core_overlay( $target_id, $source, $valid ) {
	$edited = $valid['edited'];
	$fields = array(
		'ID'         => $target_id,
		'post_author' => get_current_user_id(),
		'post_title' => $valid['copy_title'],
	);
	$map    = array(
		'content'        => 'post_content',
		'excerpt'        => 'post_excerpt',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
		'parent'         => 'post_parent',
		'menu_order'     => 'menu_order',
	);
	foreach ( $map as $edited_key => $post_key ) {
		if ( array_key_exists( $edited_key, $edited ) ) {
			$fields[ $post_key ] = $edited[ $edited_key ];
		}
	}
	$updated = wp_update_post( wp_slash( $fields ), true );
	if ( is_wp_error( $updated ) || $target_id !== (int) $updated ) {
		return is_wp_error( $updated ) ? $updated : new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce could not update the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	$taxonomy_result = clone_post_unsaved_changes_apply_woocommerce_taxonomy_overlay( $target_id, $source, $edited );
	if ( is_wp_error( $taxonomy_result ) ) {
		return $taxonomy_result;
	}
	$thumbnail_result = clone_post_unsaved_changes_apply_woocommerce_thumbnail_overlay( $target_id, $source, $edited );
	if ( is_wp_error( $thumbnail_result ) ) {
		return $thumbnail_result;
	}

	$target = get_post( $target_id );
	if ( ! $target || 'draft' !== $target->post_status ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'WooCommerce changed the new draft unexpectedly.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Apply the existing REST taxonomy overlay rules to a native product duplicate.
 *
 * @param int                  $target_id Target product ID.
 * @param WP_Post              $source    Source product post.
 * @param array<string,mixed>  $edited    Editor overlay.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_apply_woocommerce_taxonomy_overlay( $target_id, $source, $edited ) {
	$taxonomies = get_object_taxonomies( $source->post_type, 'objects' );
	$overlays   = isset( $edited['taxonomies'] ) ? $edited['taxonomies'] : array();
	$rest_bases = array();
	foreach ( $taxonomies as $taxonomy ) {
		if ( $taxonomy->show_in_rest ) {
			$rest_bases[] = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
		}
	}
	foreach ( array_keys( $overlays ) as $rest_base ) {
		if ( ! in_array( $rest_base, $rest_bases, true ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'A taxonomy is not available for this post type.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}
	foreach ( $taxonomies as $taxonomy ) {
		$rest_base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
		if ( ! $taxonomy->show_in_rest || ! array_key_exists( $rest_base, $overlays ) ) {
			continue;
		}
		$terms = $overlays[ $rest_base ];
		if ( ! is_array( $terms ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'A taxonomy value is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		$terms = array_values( array_unique( array_map( 'absint', $terms ) ) );
		foreach ( $terms as $term_id ) {
			$term = get_term( $term_id, $taxonomy->name );
			if ( ! $term || is_wp_error( $term ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				return new WP_Error( 'clone_post_unsaved_changes_forbidden_term', __( 'You cannot assign one of the copied terms.', 'clone-post-unsaved-changes' ), array( 'status' => 403 ) );
			}
		}
		$set = wp_set_object_terms( $target_id, $terms, $taxonomy->name, false );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
	}

	return true;
}

/**
 * Apply the existing featured-media overlay rule to a native product duplicate.
 *
 * @param int                  $target_id Target product ID.
 * @param WP_Post              $source    Source product post.
 * @param array<string,mixed>  $edited    Editor overlay.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_apply_woocommerce_thumbnail_overlay( $target_id, $source, $edited ) {
	$thumbnail_id = array_key_exists( 'featured_media', $edited ) ? absint( $edited['featured_media'] ) : get_post_thumbnail_id( $source );
	if ( $thumbnail_id && 'attachment' !== get_post_type( $thumbnail_id ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_featured_media', __( 'The featured media is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( $thumbnail_id ) {
		if ( false === set_post_thumbnail( $target_id, $thumbnail_id ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'The featured media could not be copied.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
		}
	} elseif ( array_key_exists( 'featured_media', $edited ) && metadata_exists( 'post', $target_id, '_thumbnail_id' ) && ! delete_post_thumbnail( $target_id ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'The featured media could not be removed.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	if ( $thumbnail_id !== (int) get_post_thumbnail_id( $target_id ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'The featured media could not be copied.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Delete a failed native duplicate and any variations it created.
 *
 * @param int $target_id Native duplicate product ID.
 * @return void
 */
function clone_post_unsaved_changes_cleanup_woocommerce_duplicate( $target_id ) {
	$target_id = absint( $target_id );
	if ( ! $target_id ) {
		return;
	}
	$children = get_posts(
		array(
			'post_type'      => 'product_variation',
			'post_parent'    => $target_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $children as $child_id ) {
		wp_delete_post( (int) $child_id, true );
	}
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $target_id ) : false;
	if ( $product && method_exists( $product, 'delete' ) ) {
		$product->delete( true );
		return;
	}
	wp_delete_post( $target_id, true );
}

/**
 * Insert and populate the new draft, deleting it on any population failure.
 *
 * @param WP_Post               $source Source post.
 * @param array<string, mixed>  $valid  Validated request data.
 * @return int|WP_Error
 */
function clone_post_unsaved_changes_insert_draft( $source, $valid ) {
	$edited = $valid['edited'];
	$fields = array(
		'post_type'      => $source->post_type,
		'post_status'    => 'draft',
		'post_author'    => get_current_user_id(),
		'post_title'     => $valid['copy_title'],
		'post_content'   => $source->post_content,
		'post_excerpt'   => $source->post_excerpt,
		'comment_status' => $source->comment_status,
		'ping_status'    => $source->ping_status,
		'post_parent'    => (int) $source->post_parent,
		'menu_order'     => (int) $source->menu_order,
	);
	$map    = array(
		'content'        => 'post_content',
		'excerpt'        => 'post_excerpt',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
		'parent'         => 'post_parent',
		'menu_order'     => 'menu_order',
	);
	foreach ( $map as $edited_key => $post_key ) {
		if ( array_key_exists( $edited_key, $edited ) ) {
			$fields[ $post_key ] = $edited[ $edited_key ];
		}
	}

	$target_id = wp_insert_post( wp_slash( $fields ), true );
	if ( is_wp_error( $target_id ) ) {
		return $target_id;
	}

	$result = clone_post_unsaved_changes_populate_draft( $target_id, $source, $edited );
	if ( is_wp_error( $result ) ) {
		wp_delete_post( $target_id, true );
		return $result;
	}

	return $target_id;
}

/**
 * Populate terms, core metadata, and explicitly allowed post meta.
 *
 * @param int                  $target_id New post ID.
 * @param WP_Post              $source    Source post.
 * @param array<string, mixed> $edited    Editor overlay.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_populate_draft( $target_id, $source, $edited ) {
	$taxonomies = get_object_taxonomies( $source->post_type, 'objects' );
	$overlays   = isset( $edited['taxonomies'] ) ? $edited['taxonomies'] : array();
	$rest_bases  = array();
	foreach ( $taxonomies as $taxonomy ) {
		if ( $taxonomy->show_in_rest ) {
			$rest_bases[] = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
		}
	}
	foreach ( array_keys( $overlays ) as $rest_base ) {
		if ( ! in_array( $rest_base, $rest_bases, true ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'A taxonomy is not available for this post type.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}

	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_object_terms( $source->ID, $taxonomy->name, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$rest_base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
		if ( $taxonomy->show_in_rest && array_key_exists( $rest_base, $overlays ) ) {
			$terms = $overlays[ $rest_base ];
		}
		if ( ! is_array( $terms ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_request', __( 'A taxonomy value is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		$terms = array_values( array_unique( array_map( 'absint', $terms ) ) );
		foreach ( $terms as $term_id ) {
			$term = get_term( $term_id, $taxonomy->name );
			if ( ! $term || is_wp_error( $term ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				return new WP_Error( 'clone_post_unsaved_changes_forbidden_term', __( 'You cannot assign one of the copied terms.', 'clone-post-unsaved-changes' ), array( 'status' => 403 ) );
			}
		}
		$set = wp_set_object_terms( $target_id, $terms, $taxonomy->name, false );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
	}

	$format = array_key_exists( 'format', $edited ) ? (string) $edited['format'] : (string) get_post_format( $source );
	if ( '' !== $format && 'standard' !== $format && ! in_array( sanitize_key( $format ), get_post_format_slugs(), true ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_format', __( 'The post format is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	$format_result = set_post_format( $target_id, $format ? $format : false );
	if ( is_wp_error( $format_result ) || false === $format_result ) {
		return new WP_Error(
			'clone_post_unsaved_changes_incomplete_clone',
			__( 'The post format could not be copied.', 'clone-post-unsaved-changes' ),
			array( 'status' => 500 )
		);
	}
	$expected_format = 'standard' === $format ? '' : sanitize_key( $format );
	if ( $expected_format !== (string) get_post_format( $target_id ) ) {
		return new WP_Error(
			'clone_post_unsaved_changes_incomplete_clone',
			__( 'The post format could not be copied.', 'clone-post-unsaved-changes' ),
			array( 'status' => 500 )
		);
	}

	if ( 'post' !== $source->post_type && array_key_exists( 'sticky', $edited ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_dirty_fields', __( 'Sticky status is only supported for posts.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( 'post' === $source->post_type ) {
		$sticky = array_key_exists( 'sticky', $edited ) ? clone_post_unsaved_changes_normalize_boolean( $edited['sticky'] ) : is_sticky( $source->ID );
		if ( null === $sticky ) {
			return new WP_Error( 'clone_post_unsaved_changes_invalid_sticky', __( 'The sticky status is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		$post_type = get_post_type_object( $source->post_type );
		if ( $sticky && $post_type && ! current_user_can( $post_type->cap->edit_others_posts ) && ! current_user_can( $post_type->cap->publish_posts ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_forbidden_sticky', __( 'You cannot make this draft sticky.', 'clone-post-unsaved-changes' ), array( 'status' => 403 ) );
		}
		if ( $sticky ) {
			stick_post( $target_id );
		} else {
			unstick_post( $target_id );
		}
		if ( $sticky !== is_sticky( $target_id ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'The sticky status could not be copied.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
		}
	}

	$thumbnail_id = array_key_exists( 'featured_media', $edited ) ? absint( $edited['featured_media'] ) : get_post_thumbnail_id( $source );
	if ( $thumbnail_id && 'attachment' !== get_post_type( $thumbnail_id ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_invalid_featured_media', __( 'The featured media is invalid.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	if ( $thumbnail_id ) {
		$thumbnail_result = set_post_thumbnail( $target_id, $thumbnail_id );
		if ( false === $thumbnail_result ) {
			return new WP_Error(
				'clone_post_unsaved_changes_incomplete_clone',
				__( 'The featured media could not be copied.', 'clone-post-unsaved-changes' ),
				array( 'status' => 500 )
			);
		}
		if ( $thumbnail_id !== (int) get_post_thumbnail_id( $target_id ) ) {
			return new WP_Error(
				'clone_post_unsaved_changes_incomplete_clone',
				__( 'The featured media could not be copied.', 'clone-post-unsaved-changes' ),
				array( 'status' => 500 )
			);
		}
	}

	$template = array_key_exists( 'template', $edited ) ? (string) $edited['template'] : get_page_template_slug( $source );
	if ( '' !== $template ) {
		if ( false === update_post_meta( $target_id, '_wp_page_template', $template ) ) {
			return new WP_Error(
				'clone_post_unsaved_changes_incomplete_clone',
				__( 'The page template could not be copied.', 'clone-post-unsaved-changes' ),
				array( 'status' => 500 )
			);
		}
	} elseif ( metadata_exists( 'post', $target_id, '_wp_page_template' ) && ! delete_post_meta( $target_id, '_wp_page_template' ) ) {
		return new WP_Error(
			'clone_post_unsaved_changes_incomplete_clone',
			__( 'The page template could not be cleared.', 'clone-post-unsaved-changes' ),
			array( 'status' => 500 )
		);
	}

	$meta_result = clone_post_unsaved_changes_copy_allowed_meta( $target_id, $source, $edited );
	if ( is_wp_error( $meta_result ) ) {
		return $meta_result;
	}

	return true;
}

/**
 * Normalize the REST boolean values accepted for the sticky attribute.
 *
 * @param mixed $value Raw sticky overlay value.
 * @return bool|null
 */
function clone_post_unsaved_changes_normalize_boolean( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( ! in_array( $value, array( 0, 1, '0', '1', 'false', 'true' ), true ) ) {
		return null;
	}
	return rest_sanitize_boolean( $value );
}

/**
 * Copy exact, registered, REST-visible meta rows and apply allowed overlays.
 *
 * @param int                  $target_id New post ID.
 * @param WP_Post              $source    Source post.
 * @param array<string, mixed> $edited    Editor overlay.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_copy_allowed_meta( $target_id, $source, $edited ) {
	$source_meta = get_post_meta( $source->ID );
	foreach ( $source_meta as $key => $values ) {
		if ( ! clone_post_unsaved_changes_meta_is_allowed( $key, $source ) ) {
			continue;
		}
		foreach ( $values as $value ) {
			if ( ! add_post_meta( $target_id, $key, $value ) ) {
				return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'A permitted meta value could not be copied.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
			}
		}
	}

	if ( empty( $edited['meta'] ) ) {
		return true;
	}
	foreach ( $edited['meta'] as $key => $value ) {
		if ( ! is_string( $key ) || ! clone_post_unsaved_changes_meta_is_allowed( $key, $source ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_meta_unsupported', __( 'This post has changed meta that Save As is not configured to copy.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post_meta', $target_id, $key ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'A permitted meta value could not be updated.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
		}
		delete_post_meta( $target_id, $key );
		$registered = clone_post_unsaved_changes_registered_meta( $key, $source->post_type );
		$values     = ! empty( $registered['single'] ) ? array( $value ) : ( is_array( $value ) ? $value : array( $value ) );
		foreach ( $values as $meta_value ) {
			$meta_value = sanitize_meta( $key, $meta_value, 'post', $source->post_type );
			if ( ! add_post_meta( $target_id, $key, $meta_value ) ) {
				return new WP_Error( 'clone_post_unsaved_changes_incomplete_clone', __( 'A permitted meta value could not be updated.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
			}
		}
	}

	return true;
}

/**
 * Decide whether a meta key is safe and exposed through the REST contract.
 *
 * Registered REST-visible keys are copied by default. A site may provide the
 * allowlist filter to narrow that set for a particular post type; arbitrary
 * unregistered/private keys remain denied.
 *
 * @param string  $key    Meta key.
 * @param WP_Post $source Source post.
 * @return bool
 */
function clone_post_unsaved_changes_meta_is_allowed( $key, $source ) {
	if ( 0 === strpos( $key, '_edit_' ) || 0 === strpos( $key, '_wp_old_' ) || 0 === strpos( $key, '_elementor' ) || 0 === strpos( $key, '_wc_' ) || 0 === strpos( $key, '_product_' ) || 0 === strpos( $key, '_clone_post_unsaved_changes_' ) || in_array( $key, array( '_thumbnail_id', '_wp_page_template' ), true ) ) {
		return false;
	}

	$allowed = apply_filters( 'clone_post_unsaved_changes_allowed_meta_keys', null, $source->ID, $source->post_type );
	if ( null !== $allowed && ( ! is_array( $allowed ) || ! in_array( $key, $allowed, true ) ) ) {
		return false;
	}

	if ( ! current_user_can( 'edit_post_meta', $source->ID, $key ) ) {
		return false;
	}

	$registered = clone_post_unsaved_changes_registered_meta( $key, $source->post_type );

	return isset( $registered['show_in_rest'] ) && (bool) $registered['show_in_rest'];
}

/**
 * Return the registration for one post-meta key, respecting post subtype.
 *
 * @param string $key       Meta key.
 * @param string $post_type Post type.
 * @return array<string, mixed>
 */
function clone_post_unsaved_changes_registered_meta( $key, $post_type ) {
	$registered = get_registered_meta_keys( 'post', $post_type );
	if ( isset( $registered[ $key ] ) ) {
		return $registered[ $key ];
	}
	$registered = get_registered_meta_keys( 'post' );
	return isset( $registered[ $key ] ) ? $registered[ $key ] : array();
}

/**
 * Recursively sort associative arrays before hashing an idempotency payload.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function clone_post_unsaved_changes_canonicalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	foreach ( $value as $key => $child ) {
		$value[ $key ] = clone_post_unsaved_changes_canonicalize( $child );
	}
	if ( ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value );
	}
	return $value;
}

/**
 * Validate the narrowly pinned Elementor adapter before a draft is inserted.
 *
 * Elementor does not publish a generic cross-version clone contract. The
 * adapter is therefore deliberately pinned to the runtime verified here.
 *
 * @param WP_Post $source Elementor source post.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_validate_elementor_document( $source ) {
	if ( ! defined( 'ELEMENTOR_VERSION' ) || '4.2.3' !== ELEMENTOR_VERSION || ! class_exists( 'Elementor\\Plugin' ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_elementor_version', __( 'Save As currently supports Elementor 4.2.3 only.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	$documents = \Elementor\Plugin::instance()->documents;
	if ( ! is_object( $documents ) || ! method_exists( $documents, 'get' ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_elementor_version', __( 'The installed Elementor document API is not supported.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	$document = $documents->get( $source->ID );
	if ( ! is_object( $document ) || ! method_exists( $document, 'get_name' ) || ! in_array( $document->get_name(), array( 'wp-page', 'wp-post' ), true ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_elementor_unsupported', __( 'This Elementor document type is not supported by Save As.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}

	foreach ( array( 'save', 'set_is_built_with_elementor', 'get_edit_url' ) as $method ) {
		if ( ! method_exists( $document, $method ) ) {
			return new WP_Error( 'clone_post_unsaved_changes_unsupported_elementor_version', __( 'The installed Elementor document API is not supported.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
		}
	}

	return true;
}

/**
 * Clone Elementor data through its document API, not raw Elementor meta.
 *
 * @param WP_Post              $source    Elementor source post.
 * @param int                  $target_id New WordPress draft ID.
 * @param array<string, mixed> $payload   Frozen Elementor editor data.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_clone_elementor_document( $source, $target_id, $payload ) {
	$valid = clone_post_unsaved_changes_validate_elementor_document( $source );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$valid_payload = clone_post_unsaved_changes_validate_elementor_payload( $payload );
	if ( is_wp_error( $valid_payload ) ) {
		return $valid_payload;
	}

	$target_document = \Elementor\Plugin::instance()->documents->get( $target_id );
	if ( ! is_object( $target_document ) || ! method_exists( $target_document, 'save' ) || ! method_exists( $target_document, 'set_is_built_with_elementor' ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'Elementor could not prepare the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	$source_fingerprint = clone_post_unsaved_changes_elementor_source_fingerprint( $source->ID );
	if ( is_wp_error( $source_fingerprint ) ) {
		return $source_fingerprint;
	}

	$target_document->set_is_built_with_elementor( true );
	$saved = $target_document->save(
		array(
			'elements' => $payload['elements'],
			'settings' => $payload['settings'],
		)
	);
	if ( false === $saved || is_wp_error( $saved ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'Elementor could not save the new draft.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	$target = get_post( $target_id );
	if ( ! $target || 'draft' !== $target->post_status ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'Elementor changed the new draft status unexpectedly.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	$target_payload = clone_post_unsaved_changes_elementor_target_contains_payload( $target_id, $payload );
	if ( is_wp_error( $target_payload ) ) {
		return $target_payload;
	}
	$source_unchanged = clone_post_unsaved_changes_elementor_source_unchanged( $source->ID, $source_fingerprint );
	if ( is_wp_error( $source_unchanged ) ) {
		return $source_unchanged;
	}

	$css_class = 'Elementor\\Core\\Files\\CSS\\Post';
	if ( ! class_exists( $css_class ) || ! method_exists( $css_class, 'create' ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_elementor_version', __( 'The installed Elementor CSS API is not supported.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	$post_css = $css_class::create( $target_id );
	if ( ! is_object( $post_css ) || ! method_exists( $post_css, 'update' ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_unsupported_elementor_version', __( 'The installed Elementor CSS API is not supported.', 'clone-post-unsaved-changes' ), array( 'status' => 400 ) );
	}
	$css_updated = $post_css->update();
	if ( false === $css_updated ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'Elementor could not generate the new draft stylesheet.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}
	$source_unchanged = clone_post_unsaved_changes_elementor_source_unchanged( $source->ID, $source_fingerprint );
	if ( is_wp_error( $source_unchanged ) ) {
		return $source_unchanged;
	}

	return true;
}

/**
 * Verify that Elementor persisted every submitted element and setting.
 *
 * Elementor normalizes the JSON on save (for example by adding defaults and
 * generated IDs), so this is a deep-subset check rather than a byte-for-byte
 * comparison. A missing submitted value is still a hard adapter failure.
 *
 * @param int                  $target_id New draft ID.
 * @param array<string, mixed> $payload   Frozen editor payload.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_elementor_target_contains_payload( $target_id, $payload ) {
	$stored_elements = get_post_meta( $target_id, '_elementor_data', true );
	$stored_settings = get_post_meta( $target_id, '_elementor_page_settings', true );
	$stored_elements = is_string( $stored_elements ) ? json_decode( $stored_elements, true ) : $stored_elements;
	$stored_settings = is_string( $stored_settings ) ? json_decode( $stored_settings, true ) : $stored_settings;
	// Elementor 4.2.3 does not create _elementor_page_settings when the
	// document settings object is empty. Treat that representation as an empty
	// object, but keep non-empty submitted settings fail-closed.
	if ( ( null === $stored_settings || '' === $stored_settings ) && empty( $payload['settings'] ) ) {
		$stored_settings = array();
	}

	if ( ! is_array( $stored_elements ) || ! is_array( $stored_settings ) || ! clone_post_unsaved_changes_elementor_value_contains( $payload['elements'], $stored_elements ) || ! clone_post_unsaved_changes_elementor_value_contains( $payload['settings'], $stored_settings ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'Elementor did not persist the complete editor snapshot.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Check whether an expected JSON value is contained in a normalized value.
 *
 * @param mixed $expected Expected subset.
 * @param mixed $actual   Persisted value.
 * @return bool
 */
function clone_post_unsaved_changes_elementor_value_contains( $expected, $actual ) {
	if ( ! is_array( $expected ) ) {
		return $expected === $actual;
	}
	if ( ! is_array( $actual ) ) {
		return false;
	}

	$is_list = array_keys( $expected ) === range( 0, count( $expected ) - 1 );
	if ( $is_list ) {
		foreach ( $expected as $index => $value ) {
			if ( ! array_key_exists( $index, $actual ) || ! clone_post_unsaved_changes_elementor_value_contains( $value, $actual[ $index ] ) ) {
				return false;
			}
		}
		return true;
	}

	foreach ( $expected as $key => $value ) {
		// These are editor-only Backbone model fields. Elementor 4.2.3
		// intentionally strips them before writing _elementor_data.
		if ( in_array( $key, array( 'isLocked', 'interactions', 'editSettings', 'defaultEditSettings' ), true ) ) {
			continue;
		}
		if ( 'isInner' === $key && false === $value && ! array_key_exists( $key, $actual ) ) {
			continue;
		}
		if ( ! array_key_exists( $key, $actual ) || ! clone_post_unsaved_changes_elementor_value_contains( $value, $actual[ $key ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Fingerprint the source post and its owned database relationships.
 *
 * @param int $source_id Source post ID.
 * @return string|WP_Error
 */
function clone_post_unsaved_changes_elementor_source_fingerprint( $source_id ) {
	$source = get_post( $source_id, ARRAY_A );
	if ( ! is_array( $source ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_adapter_failed', __( 'The Elementor source could not be read safely.', 'clone-post-unsaved-changes' ), array( 'status' => 500 ) );
	}

	$meta = get_post_meta( $source_id );
	ksort( $meta );
	$terms = array();
	foreach ( get_object_taxonomies( $source['post_type'] ) as $taxonomy ) {
		$term_ids = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		sort( $term_ids, SORT_NUMERIC );
		$terms[ $taxonomy ] = $term_ids;
	}
	ksort( $terms );

	return hash( 'sha256', wp_json_encode( array( 'post' => $source, 'meta' => $meta, 'terms' => $terms ) ) );
}

/**
 * Verify the source fingerprint after an Elementor target operation.
 *
 * @param int    $source_id   Source post ID.
 * @param string $fingerprint Original source fingerprint.
 * @return true|WP_Error
 */
function clone_post_unsaved_changes_elementor_source_unchanged( $source_id, $fingerprint ) {
	$current = clone_post_unsaved_changes_elementor_source_fingerprint( $source_id );
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( ! hash_equals( $fingerprint, $current ) ) {
		return new WP_Error( 'clone_post_unsaved_changes_source_changed', __( 'The original Elementor document changed while the copy was being created.', 'clone-post-unsaved-changes' ), array( 'status' => 409 ) );
	}
	return true;
}

/**
 * Return the editor-specific URL after cloning.
 *
 * @param int    $post_id Draft post ID.
 * @param string $editor  Target editor.
 * @return string
 */
function clone_post_unsaved_changes_edit_url( $post_id, $editor ) {
	if ( 'elementor' === $editor && class_exists( 'Elementor\\Plugin' ) ) {
		$document = \Elementor\Plugin::instance()->documents->get( $post_id );
		if ( is_object( $document ) && method_exists( $document, 'get_edit_url' ) ) {
			return $document->get_edit_url();
		}
	}

	return get_edit_post_link( $post_id, 'raw' );
}
