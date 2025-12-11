/**
 * CPT-Taxonomy Syncer - Post Type Relationships Dashboard
 *
 * Displays parent-to-child relationships with drag-and-drop ordering
 */

import { useState, useEffect, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const { createRoot } = wp.element;

function PostTypeRelationshipsDashboard({ postType, taxonomy }) {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [relationships, setRelationships] = useState([]);
	const [draggedItem, setDraggedItem] = useState(null);
	const [draggedOver, setDraggedOver] = useState(null);
	const [saving, setSaving] = useState(false);

	// Fetch relationships on mount
	useEffect(() => {
		if (!postType || !taxonomy) {
			setError(__('Missing post type or taxonomy.', 'cpt-taxonomy-syncer'));
			setLoading(false);
			return;
		}

		fetchRelationships();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [postType, taxonomy]);

	const fetchRelationships = async () => {
		setLoading(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: `/cpt-tax-syncer/v1/post-type-relationships?post_type=${postType}&taxonomy=${taxonomy}&per_page=100&page=1`,
			});

			setRelationships(response.relationships || []);
		} catch (err) {
			setError(
				err.message ||
					__('Failed to load relationships.', 'cpt-taxonomy-syncer')
			);
		} finally {
			setLoading(false);
		}
	};

	const handleDragStart = (e, relationshipIndex, postIndex) => {
		setDraggedItem({ relationshipIndex, postIndex });
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('text/html', e.target.outerHTML);
		e.target.style.opacity = '0.5';
	};

	const handleDragEnd = (e) => {
		e.target.style.opacity = '';
		setDraggedItem(null);
		setDraggedOver(null);
	};

	const handleDragOver = (e, relationshipIndex, postIndex) => {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		setDraggedOver({ relationshipIndex, postIndex });
	};

	const handleDrop = async (e, targetRelationshipIndex, targetPostIndex) => {
		e.preventDefault();

		if (
			!draggedItem ||
			draggedItem.relationshipIndex !== targetRelationshipIndex
		) {
			setDraggedOver(null);
			return;
		}

		const relationship = relationships[targetRelationshipIndex];
		const relatedPosts = [...relationship.related_posts];

		// Remove dragged item from its current position
		const [draggedPost] = relatedPosts.splice(draggedItem.postIndex, 1);

		// Insert at new position
		relatedPosts.splice(targetPostIndex, 0, draggedPost);

		// Update local state
		const updatedRelationships = [...relationships];
		updatedRelationships[targetRelationshipIndex] = {
			...relationship,
			related_posts: relatedPosts,
		};
		setRelationships(updatedRelationships);

		// Save order to server
		await saveOrder(
			relationship.post.id,
			taxonomy,
			relatedPosts.map((p) => p.id)
		);

		setDraggedItem(null);
		setDraggedOver(null);
	};

	const saveOrder = async (parentPostId, taxonomy, order) => {
		setSaving(true);
		try {
			await apiFetch({
				path: '/cpt-tax-syncer/v1/relationship-order',
				method: 'POST',
				data: {
					parent_post_id: parentPostId,
					taxonomy: taxonomy,
					order: order,
				},
			});
		} catch (err) {
			setError(
				err.message ||
					__('Failed to save order.', 'cpt-taxonomy-syncer')
			);
		} finally {
			setSaving(false);
		}
	};

	if (loading) {
		return (
			<div className="cpt-tax-relationships-loading">
				<p>{__('Loading relationships...', 'cpt-taxonomy-syncer')}</p>
			</div>
		);
	}

	if (error) {
		return (
			<div className="cpt-tax-relationships-error notice notice-error">
				<p>
					<strong>{__('Error:', 'cpt-taxonomy-syncer')}</strong> {error}
				</p>
			</div>
		);
	}

	if (relationships.length === 0) {
		return (
			<div className="cpt-tax-relationships-empty">
				<p>
					{__(
						'No relationships found.',
						'cpt-taxonomy-syncer'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="cpt-tax-relationships-dashboard">
			{saving && (
				<div className="notice notice-info is-dismissible">
					<p>{__('Saving order...', 'cpt-taxonomy-syncer')}</p>
				</div>
			)}
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style={{ width: '30%' }}>
							{__('Parent Post', 'cpt-taxonomy-syncer')}
						</th>
						<th style={{ width: '10%' }}>
							{__('Term', 'cpt-taxonomy-syncer')}
						</th>
						<th style={{ width: '60%' }}>
							{__('Related Posts (drag to reorder)', 'cpt-taxonomy-syncer')}
						</th>
					</tr>
				</thead>
				<tbody>
					{relationships.map((relationship, relIndex) => (
						<Fragment key={relationship.post.id}>
							{/* Parent post row */}
							<tr>
								<td>
									<strong>
										<a
											href={relationship.post.edit_url}
											target="_blank"
											rel="noopener noreferrer"
										>
											{relationship.post.title}
										</a>
									</strong>
								</td>
								<td>
									<strong>{relationship.term.name}</strong>
									<br />
									<span className="description">
										{relationship.related_count}{' '}
										{__('related', 'cpt-taxonomy-syncer')}
									</span>
								</td>
								<td>
									{relationship.related_posts.length === 0 ? (
										<span className="description">
											{__('No related posts', 'cpt-taxonomy-syncer')}
										</span>
									) : (
										<ul
											style={{
												listStyle: 'none',
												margin: 0,
												padding: 0,
											}}
										>
											{relationship.related_posts.map(
												(post, postIndex) => {
													const isDragged =
														draggedItem?.relationshipIndex ===
															relIndex &&
														draggedItem?.postIndex === postIndex;
													const isDraggedOver =
														draggedOver?.relationshipIndex ===
															relIndex &&
														draggedOver?.postIndex === postIndex;

													return (
														<li
															key={post.id}
															draggable
															onDragStart={(e) =>
																handleDragStart(
																	e,
																	relIndex,
																	postIndex
																)
															}
															onDragEnd={handleDragEnd}
															onDragOver={(e) =>
																handleDragOver(
																	e,
																	relIndex,
																	postIndex
																)
															}
															onDrop={(e) =>
																handleDrop(
																	e,
																	relIndex,
																	postIndex
																)
															}
															style={{
																padding: '8px',
																margin: '4px 0',
																backgroundColor:
																	isDraggedOver
																		? '#e5f5fa'
																		: isDragged
																		? '#f0f0f0'
																		: '#fff',
																border:
																	isDraggedOver
																		? '2px dashed #0073aa'
																		: '1px solid #ddd',
																borderRadius: '4px',
																cursor: 'move',
																opacity: isDragged
																	? 0.5
																	: 1,
															}}
														>
															<span
																style={{
																	display: 'inline-block',
																	minWidth: '30px',
																	fontWeight: 'bold',
																	color: '#666',
																}}
															>
																{postIndex + 1}.
															</span>
															<a
																href={post.edit_url}
																target="_blank"
																rel="noopener noreferrer"
															>
																{post.title}
															</a>
														</li>
													);
												}
											)}
										</ul>
									)}
								</td>
							</tr>
						</Fragment>
					))}
				</tbody>
			</table>
		</div>
	);
}

// Initialize on DOM ready
wp.domReady(() => {
	const container = document.getElementById(
		'cpt-tax-post-type-relationships-dashboard'
	);

	if (!container) {
		return;
	}

	const postType = container.dataset.postType || '';
	const taxonomy = container.dataset.taxonomy || '';

	const root = createRoot(container);
	root.render(
		<PostTypeRelationshipsDashboard postType={postType} taxonomy={taxonomy} />
	);
});

