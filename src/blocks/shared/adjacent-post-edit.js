/**
 * Shared editor component for the Previous/Next Post (Relationship) blocks.
 *
 * The two blocks differ only in direction, so one component carries the
 * inspector controls and the placeholder for both.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
} from '@wordpress/components';

/**
 * The configured pairs, printed by the plugin on the editor-data handle.
 *
 * Read at render time so the order the scripts load in does not matter.
 *
 * @return {Array<{cpt_slug: string, taxonomy_slug: string}>} Configured pairs.
 */
function getPairs() {
	return window.cptTaxSyncerQuery?.pairs ?? [];
}

const COPY = {
	next: {
		defaultLinkText: __( 'Next:', 'cpt-taxonomy-syncer' ),
		linkTextHelp: __(
			'Text to display for the next post link.',
			'cpt-taxonomy-syncer'
		),
	},
	previous: {
		defaultLinkText: __( 'Previous:', 'cpt-taxonomy-syncer' ),
		linkTextHelp: __(
			'Text to display for the previous post link.',
			'cpt-taxonomy-syncer'
		),
	},
};

/**
 * Options for the relationship pair select.
 *
 * @return {Array<{label: string, value: string}>} Select options.
 */
function pairOptions() {
	return [
		{
			label: __( 'Select a relationship…', 'cpt-taxonomy-syncer' ),
			value: '',
		},
		...getPairs().map( ( pair ) => ( {
			label: `${ pair.cpt_slug } ↔ ${ pair.taxonomy_slug }`,
			value: `${ pair.cpt_slug }|${ pair.taxonomy_slug }`,
		} ) ),
	];
}

/**
 * Editor UI for an adjacent-post block.
 *
 * @param {Object}                  props               Block props.
 * @param {Object}                  props.attributes    Block attributes.
 * @param {(attrs: Object) => void} props.setAttributes Attribute setter.
 * @param {'next'|'previous'}       props.direction     Which neighbour the block links to.
 * @return {Element} Element to render.
 */
export default function AdjacentPostEdit( {
	attributes,
	setAttributes,
	direction,
} ) {
	const { relationshipPair, useCustomOrder, linkText } = attributes;
	const copy = COPY[ direction ];

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Relationship Settings',
						'cpt-taxonomy-syncer'
					) }
				>
					<SelectControl
						label={ __(
							'Relationship Pair',
							'cpt-taxonomy-syncer'
						) }
						value={ relationshipPair }
						options={ pairOptions() }
						onChange={ ( value ) =>
							setAttributes( { relationshipPair: value } )
						}
						help={ __(
							'Select which CPT-taxonomy relationship to use for navigation.',
							'cpt-taxonomy-syncer'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Use Custom Order',
							'cpt-taxonomy-syncer'
						) }
						checked={ useCustomOrder }
						onChange={ ( value ) =>
							setAttributes( { useCustomOrder: value } )
						}
						help={ __(
							'Navigate using the custom order set in the Relationships dashboard.',
							'cpt-taxonomy-syncer'
						) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Link Text', 'cpt-taxonomy-syncer' ) }
						value={ linkText }
						placeholder={ copy.defaultLinkText }
						onChange={ ( value ) =>
							setAttributes( { linkText: value } )
						}
						help={ copy.linkTextHelp }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<span className="cpt-tax-syncer-adjacent-post__placeholder">
					{ relationshipPair
						? linkText || copy.defaultLinkText
						: __(
								'Select a relationship pair in the block settings.',
								'cpt-taxonomy-syncer'
							) }
				</span>
			</div>
		</>
	);
}
