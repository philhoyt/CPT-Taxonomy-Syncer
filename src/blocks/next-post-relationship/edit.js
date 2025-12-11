/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @param {Object} props Block props.
 * @return {Element} Element to render.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { relationshipPair, useCustomOrder, linkText } = attributes;
	const [ pairs, setPairs ] = useState( [] );

	// Get pairs from localized data
	useEffect( () => {
		if ( typeof cptTaxSyncerQuery !== 'undefined' && cptTaxSyncerQuery.pairs ) {
			setPairs( cptTaxSyncerQuery.pairs );
		}
	}, [] );

	// Build options for relationship pairs
	const pairOptions = [
		{ label: __( 'Select a relationship...', 'cpt-taxonomy-syncer' ), value: '' },
		...pairs.map( ( pair, index ) => ( {
			label: `${ pair.cpt_slug } ↔ ${ pair.taxonomy_slug }`,
			value: `${ pair.cpt_slug }|${ pair.taxonomy_slug }`,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Relationship Settings', 'cpt-taxonomy-syncer' ) }>
					<SelectControl
						label={ __( 'Relationship Pair', 'cpt-taxonomy-syncer' ) }
						value={ relationshipPair }
						options={ pairOptions }
						onChange={ ( value ) => setAttributes( { relationshipPair: value } ) }
						help={ __( 'Select which CPT-taxonomy relationship to use for navigation.', 'cpt-taxonomy-syncer' ) }
					/>
					<ToggleControl
						label={ __( 'Use Custom Order', 'cpt-taxonomy-syncer' ) }
						checked={ useCustomOrder }
						onChange={ ( value ) => setAttributes( { useCustomOrder: value } ) }
						help={ __( 'Navigate using the custom order set in the Relationships dashboard.', 'cpt-taxonomy-syncer' ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Link Text', 'cpt-taxonomy-syncer' ) }
						value={ linkText }
						onChange={ ( value ) => setAttributes( { linkText: value } ) }
						help={ __( 'Text to display for the next post link.', 'cpt-taxonomy-syncer' ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ relationshipPair ? (
					<span className="wp-block-cpt-tax-syncer-next-post-relationship__placeholder">
						{ linkText || __( 'Next', 'cpt-taxonomy-syncer' ) }
					</span>
				) : (
					<span className="wp-block-cpt-tax-syncer-next-post-relationship__placeholder">
						{ __( 'Select a relationship pair in the block settings.', 'cpt-taxonomy-syncer' ) }
					</span>
				) }
			</div>
		</>
	);
}
