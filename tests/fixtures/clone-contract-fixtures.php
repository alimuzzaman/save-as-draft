<?php
/**
 * Runtime fixtures for the Clone Post contract checks.
 *
 * This file is deliberately a test-only plugin. Load it with WP-CLI's
 * `--require` flag (or include it from a disposable test site's mu-plugins
 * directory); it never runs in a production plugin package.
 */

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

if ( ! function_exists( 'clone_contract_register_fixtures' ) ) {
	/**
	 * Register a REST-enabled Gutenberg custom post type and its taxonomies.
	 *
	 * The function is callable because `wp eval-file` can execute after the
	 * normal `init` action. The init hook keeps the fixture usable as a regular
	 * test plugin as well.
	 *
	 * @return void
	 */
	function clone_contract_register_fixtures() {
		if ( ! post_type_exists( 'clone_contract_item' ) ) {
			register_post_type(
				'clone_contract_item',
				array(
					'labels'       => array( 'name' => 'Clone contract items' ),
					'public'       => true,
					'show_ui'      => true,
					'show_in_rest' => true,
					'rest_base'    => 'clone-contract-items',
					'supports'     => array(
						'title',
						'editor',
						'excerpt',
						'thumbnail',
						'page-attributes',
						'custom-fields',
						'comments',
						'revisions',
					),
					'taxonomies'   => array( 'category', 'post_tag' ),
				)
			);
		}

		if ( ! taxonomy_exists( 'clone_contract_topic' ) ) {
			register_taxonomy(
				'clone_contract_topic',
				array( 'clone_contract_item' ),
				array(
					'labels'             => array( 'name' => 'Clone topics' ),
					'public'             => true,
					'show_ui'            => true,
					'show_in_rest'       => true,
					'rest_base'          => 'clone-contract-topics',
					'hierarchical'       => true,
					'show_admin_column'  => true,
					'capabilities'       => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
						'assign_terms' => 'edit_posts',
					),
				)
			);
		}

		if ( ! taxonomy_exists( 'clone_contract_label' ) ) {
			register_taxonomy(
				'clone_contract_label',
				array( 'clone_contract_item' ),
				array(
					'labels'            => array( 'name' => 'Clone labels' ),
					'public'            => true,
					'show_ui'           => true,
					'show_in_rest'      => true,
					'rest_base'         => 'clone-contract-labels',
					'hierarchical'      => false,
					'show_admin_column' => true,
					'capabilities'      => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
						'assign_terms' => 'edit_posts',
					),
				)
			);
		}

		// `category` and `post_tag` are registered by core but are not always
		// attached to a custom type when this file is loaded with --require.
		register_taxonomy_for_object_type( 'category', 'clone_contract_item' );
		register_taxonomy_for_object_type( 'post_tag', 'clone_contract_item' );

		register_post_meta(
			'clone_contract_item',
			'clone_contract_allowed',
			array(
				'single'       => true,
				'type'         => 'string',
				'show_in_rest' => true,
			)
		);
		register_post_meta(
			'clone_contract_item',
			'clone_contract_repeated',
			array(
				'single'       => false,
				'type'         => 'string',
				'show_in_rest' => true,
			)
		);
	register_post_meta(
			'clone_contract_item',
			'clone_contract_serialized',
			array(
				'single'       => true,
				'type'         => 'object',
				'show_in_rest' => true,
			)
		);
		register_post_meta(
			'clone_contract_item',
			'clone_contract_default',
			array(
				'single'       => true,
				'type'         => 'string',
				'show_in_rest' => true,
			)
		);
	}

}

if ( ! function_exists( 'clone_contract_allowed_meta_keys' ) ) {
	/**
	 * Opt the fixture's two scalar keys into the plugin's exact-key allowlist.
	 *
	 * The filter is intentionally conservative. If the production endpoint does
	 * not expose this filter, the contract runner reports that the environment
	 * is not ready rather than silently treating private keys as copyable.
	 *
	 * @param array  $keys     Existing allowlisted keys.
 * @param int    $source_id Source post ID, when supplied by the endpoint.
 * @param string $post_type Source post type, when supplied by the endpoint.

	 * @return array
	 */
	function clone_contract_allowed_meta_keys( $keys, $source_id = 0, $post_type = '' ) {
		if ( '' !== $post_type && 'clone_contract_item' !== $post_type ) {
			return $keys;
		}

		$keys = is_array( $keys ) ? $keys : array();
		return array_values(
			array_unique(
				array_merge(
					$keys,
					array(
						'clone_contract_allowed',
						'clone_contract_repeated',
						'clone_contract_serialized',
					)
				)
			)
		);
	}

}

/**
 * Register fixture hooks only after WordPress has loaded.
 *
 * WP-CLI processes `--require` before WordPress defines `ABSPATH`, so the
 * fixture cannot call core hook functions at file-load time.
 *
 * @return void
 */
function clone_contract_install_fixture_hooks() {
	add_action( 'init', 'clone_contract_register_fixtures', 1 );
	add_filter( 'clone_post_unsaved_changes_allowed_meta_keys', 'clone_contract_allowed_meta_keys', 10, 3 );

	if ( did_action( 'init' ) ) {
		clone_contract_register_fixtures();
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'ABSPATH' ) ) {
	WP_CLI::add_hook( 'after_wp_load', 'clone_contract_install_fixture_hooks' );
} else {
	clone_contract_install_fixture_hooks();
}
