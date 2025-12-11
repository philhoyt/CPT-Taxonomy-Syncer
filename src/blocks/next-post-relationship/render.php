<?php
/**
 * PHP file to use when rendering the block type on the server to show on the front end.
 *
 * The following variables are exposed to the file:
 *     $attributes (array): The block attributes.
 *     $content (string): The block default content.
 *     $block (WP_Block): The block instance.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/docs/reference-guides/block-api/block-metadata.md#render
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$relationship_pair = $attributes['relationshipPair'] ?? '';
$use_custom_order  = isset( $attributes['useCustomOrder'] ) ? (bool) $attributes['useCustomOrder'] : true;
$link_text         = $attributes['linkText'] ?? __( 'Next:', 'cpt-taxonomy-syncer' );

// If no relationship pair selected, return empty.
if ( empty( $relationship_pair ) ) {
	return;
}

// Parse relationship pair (format: "cpt_slug|taxonomy_slug").
$pair_parts = explode( '|', $relationship_pair );
if ( count( $pair_parts ) !== 2 ) {
	return;
}

$cpt_slug      = $pair_parts[0];
$taxonomy_slug = $pair_parts[1];

// Get current post.
global $post;
if ( ! $post || ! $post->ID ) {
	return;
}

// Get adjacent post using custom relationship order.
$adjacent_post = CPT_Tax_Syncer_Adjacent_Post_Blocks::get_adjacent_post(
	$post->ID,
	$cpt_slug,
	$taxonomy_slug,
	false, // is_previous (false = next)
	$use_custom_order
);

// If no adjacent post found, return empty.
if ( ! $adjacent_post ) {
	return;
}

// Build the link.
$adjacent_url   = get_permalink( $adjacent_post->ID );
$adjacent_title = get_the_title( $adjacent_post->ID );

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-cpt-tax-syncer-next-post-relationship',
	)
);
?>

<div <?php echo $wrapper_attributes; ?>>
	<a href="<?php echo esc_url( $adjacent_url ); ?>" rel="next">
		<?php echo esc_html( $link_text ); ?>
		<?php if ( ! empty( $adjacent_title ) ) : ?>
			<span class="wp-block-cpt-tax-syncer-next-post-relationship__title">
				<?php echo esc_html( $adjacent_title ); ?>
			</span>
		<?php endif; ?>
	</a>
</div>
