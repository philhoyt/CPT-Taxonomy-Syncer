<?php
/**
 * Server render for the Previous Post (Relationship) block.
 *
 * Variables exposed by WordPress: $attributes (array), $content (string), $block (WP_Block).
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo CPT_Tax_Syncer_Adjacent_Post_Blocks::render_block( $attributes, 'previous', (int) ( $block->context['postId'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() escapes every value it prints.
