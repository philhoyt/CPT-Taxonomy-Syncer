/**
 * CPT-Taxonomy Syncer - Relationship Query Loop Variation
 *
 * Adds a "Synced Relationships" panel to the Query Loop block and registers
 * a Relationship Query Loop variation. The settings live inside the block's
 * `query` attribute, which core passes to the inner blocks as context, so
 * the server reads them from there.
 */

import { registerBlockVariation } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';

const VARIATION_NAME = 'cpt-tax-syncer-relationship-query';

/**
 * Inspector panel for the relationship settings of one Query Loop block.
 *
 * @param {Object}                  props               Panel props.
 * @param {Object}                  props.query         The block's query attribute.
 * @param {(attrs: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The panel.
 */
function RelationshipPanel( { query, setAttributes } ) {
	const { useSyncedRelationship = false, useCustomOrder = false } = query;

	const updateQuery = ( changes ) =>
		setAttributes( { query: { ...query, ...changes } } );

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Synced Relationships', 'cpt-taxonomy-syncer' ) }
				initialOpen={ false }
			>
				<ToggleControl
					label={ __(
						'Use synced relationship',
						'cpt-taxonomy-syncer'
					) }
					help={ __(
						'Automatically query posts related to the current post through synced CPT-taxonomy relationships',
						'cpt-taxonomy-syncer'
					) }
					checked={ useSyncedRelationship }
					onChange={ ( value ) =>
						// A relationship query never inherits the template's query.
						updateQuery(
							value
								? {
										useSyncedRelationship: true,
										inherit: false,
									}
								: { useSyncedRelationship: false }
						)
					}
					__nextHasNoMarginBottom
				/>
				{ useSyncedRelationship && (
					<ToggleControl
						label={ __(
							'Use custom order',
							'cpt-taxonomy-syncer'
						) }
						help={ __(
							'Display related posts in the custom order set in the Relationships dashboard',
							'cpt-taxonomy-syncer'
						) }
						checked={ useCustomOrder }
						onChange={ ( value ) =>
							updateQuery( { useCustomOrder: value } )
						}
						__nextHasNoMarginBottom
					/>
				) }
				{ useSyncedRelationship && (
					<p className="cpt-tax-syncer-relationship-panel__note">
						{ __(
							'This will show posts from the selected Post Type that are assigned to the same taxonomy terms as the current post.',
							'cpt-taxonomy-syncer'
						) }
					</p>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

const withRelationshipControls = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		if ( props.name !== 'core/query' ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				<BlockEdit { ...props } />
				<RelationshipPanel
					query={ props.attributes.query ?? {} }
					setAttributes={ props.setAttributes }
				/>
			</>
		);
	},
	'withRelationshipControls'
);

addFilter(
	'editor.BlockEdit',
	'cpt-tax-syncer/relationship-controls',
	withRelationshipControls
);

registerBlockVariation( 'core/query', {
	name: VARIATION_NAME,
	title: __( 'Relationship Query Loop', 'cpt-taxonomy-syncer' ),
	description: __(
		'Display posts with synced relationships from the current post',
		'cpt-taxonomy-syncer'
	),
	icon: 'networking',
	category: 'design',
	keywords: [ 'relationship', 'sync', 'taxonomy', 'cpt' ],
	attributes: {
		query: {
			perPage: 10,
			pages: 0,
			offset: 0,
			postType: 'post',
			order: 'desc',
			orderBy: 'date',
			author: '',
			search: '',
			exclude: [],
			sticky: '',
			inherit: false,
			useSyncedRelationship: true,
			useCustomOrder: false,
		},
	},
	// The taxonomy, author, search and sticky controls are overridden by the
	// server-side relationship query and would only mislead.
	allowedControls: [ 'postType', 'order', 'orderBy', 'perPage' ],
	scope: [ 'inserter' ],
	isActive: ( blockAttributes ) =>
		blockAttributes.query?.useSyncedRelationship === true,
} );
