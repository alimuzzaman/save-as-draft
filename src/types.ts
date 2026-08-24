/**
 * Shared types for the Clone Post with Unsaved Changes plugin.
 */

export interface TaxonomyOverlay {
	[ restBase: string ]: number[];
}

export interface EditedPostOverlay {
	title: string;
	content: string;
	excerpt: unknown;
	featured_media: unknown;
	comment_status: unknown;
	ping_status: unknown;
	format: unknown;
	sticky: unknown;
	template: unknown;
	parent: unknown;
	menu_order: unknown;
	taxonomies: TaxonomyOverlay;
	meta?: Record< string, unknown >;
}

export interface DraftRequest {
	source_id: number;
	request_id: string;
	copy_title: string;
	editor: 'block';
	edited: EditedPostOverlay;
}

export interface DraftResponse {
	id: number;
	edit_url: string;
	editor: 'core' | 'elementor';
	adapter: 'core' | 'woocommerce' | 'elementor';
}
