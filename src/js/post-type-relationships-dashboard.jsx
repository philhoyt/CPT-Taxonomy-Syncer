/**
 * CPT-Taxonomy Syncer - Post Type Relationships Dashboard
 *
 * Lists each parent post with its related posts and lets the user reorder
 * them by drag-and-drop or with the Move up / Move down buttons.
 */

import { useState, useEffect, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { chevronUp, chevronDown } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';

import './post-type-relationships-dashboard.scss';

const config = window.cptTaxSyncerPostTypeRelationships ?? {
	restNamespace: 'cpt-tax-syncer/v1',
	perPage: 50,
};

/**
 * Moves one item of an array to a new index.
 *
 * @param {Array}  items Source array (not mutated).
 * @param {number} from  Index to move from.
 * @param {number} to    Index to move to.
 * @return {Array} The reordered copy.
 */
function moveItem( items, from, to ) {
	const copy = [ ...items ];
	const [ item ] = copy.splice( from, 1 );
	copy.splice( to, 0, item );
	return copy;
}

/**
 * One related post with its reorder controls.
 *
 * @param {Object} props           Item props.
 * @param {Object} props.post      The related post.
 * @param {number} props.index     Position in the list.
 * @param {number} props.count     Number of related posts.
 * @param {Object} props.dragState Which item is dragged / dragged over.
 * @param {Object} props.handlers  onMove(from, to) and the drag handlers.
 * @return {Element} The list item.
 */
function RelatedPostItem( { post, index, count, dragState, handlers } ) {
	const isDragged = dragState.dragged === index;
	const isDraggedOver = dragState.over === index;
	const className = [
		'cpt-tax-syncer-related-post',
		isDragged ? 'is-dragged' : '',
		isDraggedOver ? 'is-dragged-over' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<li
			className={ className }
			draggable
			onDragStart={ ( event ) => handlers.onDragStart( event, index ) }
			onDragEnd={ handlers.onDragEnd }
			onDragOver={ ( event ) => handlers.onDragOver( event, index ) }
			onDrop={ ( event ) => handlers.onDrop( event, index ) }
		>
			<span className="cpt-tax-syncer-related-post__position">
				{ index + 1 }.
			</span>
			<a href={ post.edit_url }>{ post.title }</a>
			<span className="cpt-tax-syncer-related-post__controls">
				<Button
					icon={ chevronUp }
					size="small"
					label={ sprintf(
						/* translators: %s: post title */
						__( 'Move %s up', 'cpt-taxonomy-syncer' ),
						post.title
					) }
					disabled={ index === 0 }
					onClick={ () => handlers.onMove( index, index - 1 ) }
				/>
				<Button
					icon={ chevronDown }
					size="small"
					label={ sprintf(
						/* translators: %s: post title */
						__( 'Move %s down', 'cpt-taxonomy-syncer' ),
						post.title
					) }
					disabled={ index === count - 1 }
					onClick={ () => handlers.onMove( index, index + 1 ) }
				/>
			</span>
		</li>
	);
}

/**
 * One parent post row with its reorderable related posts.
 *
 * @param {Object}                      props              Row props.
 * @param {Object}                      props.relationship The relationship (post, term, related_posts).
 * @param {(related: Object[]) => void} props.onReorder    Called with the new related_posts array.
 * @return {Element} The table row.
 */
function RelationshipRow( { relationship, onReorder } ) {
	const [ dragState, setDragState ] = useState( {
		dragged: null,
		over: null,
	} );
	const related = relationship.related_posts;

	const handlers = {
		onMove: ( from, to ) => onReorder( moveItem( related, from, to ) ),
		onDragStart: ( event, index ) => {
			event.dataTransfer.effectAllowed = 'move';
			setDragState( { dragged: index, over: null } );
		},
		onDragEnd: () => setDragState( { dragged: null, over: null } ),
		onDragOver: ( event, index ) => {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			setDragState( ( state ) => ( { ...state, over: index } ) );
		},
		onDrop: ( event, index ) => {
			event.preventDefault();
			if ( dragState.dragged !== null && dragState.dragged !== index ) {
				onReorder( moveItem( related, dragState.dragged, index ) );
			}
			setDragState( { dragged: null, over: null } );
		},
	};

	return (
		<tr>
			<td>
				<strong>
					<a href={ relationship.post.edit_url }>
						{ relationship.post.title }
					</a>
				</strong>
			</td>
			<td>
				<strong>{ relationship.term.name }</strong>
				<br />
				<span className="description">
					{ sprintf(
						/* translators: %d: number of related posts */
						__( '%d related', 'cpt-taxonomy-syncer' ),
						relationship.related_count
					) }
				</span>
			</td>
			<td>
				{ related.length === 0 ? (
					<span className="description">
						{ __( 'No related posts', 'cpt-taxonomy-syncer' ) }
					</span>
				) : (
					<ul className="cpt-tax-syncer-related-posts">
						{ related.map( ( post, index ) => (
							<RelatedPostItem
								key={ post.id }
								post={ post }
								index={ index }
								count={ related.length }
								dragState={ dragState }
								handlers={ handlers }
							/>
						) ) }
					</ul>
				) }
			</td>
		</tr>
	);
}

/**
 * Previous / next page controls.
 *
 * @param {Object}                 props          Props.
 * @param {number}                 props.page     Current page.
 * @param {number}                 props.pages    Total pages.
 * @param {(page: number) => void} props.onChange Called with the new page number.
 * @return {Element|null} The controls, or null for a single page.
 */
function Pagination( { page, pages, onChange } ) {
	if ( pages <= 1 ) {
		return null;
	}

	return (
		<nav
			className="cpt-tax-syncer-pagination"
			aria-label={ __(
				'Relationships pagination',
				'cpt-taxonomy-syncer'
			) }
		>
			<Button
				variant="secondary"
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Previous page', 'cpt-taxonomy-syncer' ) }
			</Button>
			<span className="cpt-tax-syncer-pagination__status">
				{ sprintf(
					/* translators: %1$d: current page, %2$d: total pages */
					__( 'Page %1$d of %2$d', 'cpt-taxonomy-syncer' ),
					page,
					pages
				) }
			</span>
			<Button
				variant="secondary"
				disabled={ page >= pages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next page', 'cpt-taxonomy-syncer' ) }
			</Button>
		</nav>
	);
}

/**
 * Loads one page of relationships and saves reorders.
 *
 * @param {string} postType The parent post type.
 * @param {string} taxonomy The paired taxonomy.
 * @param {number} page     The page to load.
 * @return {Object} relationships, pages, loading, saving, error and saveOrder().
 */
function useRelationships( postType, taxonomy, page ) {
	const [ pages, setPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ relationships, setRelationships ] = useState( [] );

	useEffect( () => {
		if ( ! postType || ! taxonomy ) {
			setError(
				__( 'Missing post type or taxonomy.', 'cpt-taxonomy-syncer' )
			);
			setLoading( false );
			return;
		}

		setLoading( true );
		setError( null );
		apiFetch( {
			path: addQueryArgs(
				`/${ config.restNamespace }/post-type-relationships`,
				{
					post_type: postType,
					taxonomy,
					per_page: config.perPage,
					page,
				}
			),
		} )
			.then( ( response ) => {
				setRelationships( response.relationships || [] );
				setPages( response.pages || 1 );
			} )
			.catch( ( err ) =>
				setError(
					err.message ||
						__(
							'Failed to load relationships.',
							'cpt-taxonomy-syncer'
						)
				)
			)
			.finally( () => setLoading( false ) );
	}, [ postType, taxonomy, page ] );

	const saveOrder = async ( index, relatedPosts ) => {
		const relationship = relationships[ index ];
		const updated = [ ...relationships ];
		updated[ index ] = { ...relationship, related_posts: relatedPosts };
		setRelationships( updated );
		setSaving( true );
		setError( null );
		speak( __( 'Saving order…', 'cpt-taxonomy-syncer' ) );

		try {
			await apiFetch( {
				path: `/${ config.restNamespace }/relationship-order`,
				method: 'POST',
				data: {
					parent_post_id: relationship.post.id,
					taxonomy,
					order: relatedPosts.map( ( post ) => post.id ),
				},
			} );
			speak( __( 'Order saved.', 'cpt-taxonomy-syncer' ) );
		} catch ( err ) {
			const message =
				err.message ||
				__( 'Failed to save order.', 'cpt-taxonomy-syncer' );
			setError( message );
			speak( message, 'assertive' );
		} finally {
			setSaving( false );
		}
	};

	return { relationships, pages, loading, saving, error, saveOrder };
}

/**
 * The relationships table with its notices and pagination.
 *
 * @param {Object} props          Props.
 * @param {string} props.postType The parent post type.
 * @param {string} props.taxonomy The paired taxonomy.
 * @return {Element} The dashboard.
 */
function PostTypeRelationshipsDashboard( { postType, taxonomy } ) {
	const [ page, setPage ] = useState( 1 );
	const { relationships, pages, loading, saving, error, saveOrder } =
		useRelationships( postType, taxonomy, page );

	if ( loading ) {
		return (
			<p className="cpt-tax-syncer-dashboard__loading" role="status">
				<Spinner />
				{ __( 'Loading relationships…', 'cpt-taxonomy-syncer' ) }
			</p>
		);
	}

	return (
		<div className="cpt-tax-syncer-dashboard">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ saving && (
				<Notice status="info" isDismissible={ false }>
					<span role="status">
						{ __( 'Saving order…', 'cpt-taxonomy-syncer' ) }
					</span>
				</Notice>
			) }
			{ relationships.length === 0 ? (
				<p>
					{ __( 'No relationships found.', 'cpt-taxonomy-syncer' ) }
				</p>
			) : (
				<table className="wp-list-table widefat fixed striped cpt-tax-syncer-dashboard__table">
					<thead>
						<tr>
							<th scope="col" className="column-parent">
								{ __( 'Parent Post', 'cpt-taxonomy-syncer' ) }
							</th>
							<th scope="col" className="column-term">
								{ __( 'Term', 'cpt-taxonomy-syncer' ) }
							</th>
							<th scope="col" className="column-related">
								{ __(
									'Related Posts (drag or use the arrows to reorder)',
									'cpt-taxonomy-syncer'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ relationships.map( ( relationship, index ) => (
							<RelationshipRow
								key={ relationship.post.id }
								relationship={ relationship }
								onReorder={ ( related ) =>
									saveOrder( index, related )
								}
							/>
						) ) }
					</tbody>
				</table>
			) }
			<Pagination page={ page } pages={ pages } onChange={ setPage } />
		</div>
	);
}

domReady( () => {
	const container = document.getElementById(
		'cpt-tax-syncer-post-type-relationships-dashboard'
	);

	if ( ! container ) {
		return;
	}

	createRoot( container ).render(
		<PostTypeRelationshipsDashboard
			postType={ container.dataset.postType || '' }
			taxonomy={ container.dataset.taxonomy || '' }
		/>
	);
} );
