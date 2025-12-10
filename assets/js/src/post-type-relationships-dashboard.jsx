/**
 * CPT-Taxonomy Syncer - Post Type Relationships Dashboard
 * 
 * Shows posts with their related posts through taxonomy relationships
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Post Type Relationships Dashboard Component
 */
function PostTypeRelationshipsDashboard() {
	const container = document.getElementById('cpt-tax-post-type-relationships-dashboard');
	const postType = container?.dataset.postType || '';
	const taxonomy = container?.dataset.taxonomy || '';

	const [relationships, setRelationships] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [page, setPage] = useState(1);
	const [perPage, setPerPage] = useState(20);
	const [total, setTotal] = useState(0);
	const [search, setSearch] = useState('');

	// Fetch relationships
	useEffect(() => {
		setLoading(true);
		setError(null);

		const params = new URLSearchParams({
			post_type: postType,
			taxonomy: taxonomy,
			page: page.toString(),
			per_page: perPage.toString(),
		});

		if (search) {
			params.append('search', search);
		}

		apiFetch({
			path: `/cpt-tax-syncer/v1/post-type-relationships?${params.toString()}`,
		})
			.then((data) => {
				setRelationships(data.relationships || []);
				setTotal(data.total || 0);
				setLoading(false);
			})
			.catch((err) => {
				const errorMessage = err?.message || err?.code || __('Error loading relationships', 'cpt-taxonomy-syncer');
				setError(errorMessage);
				setLoading(false);
				console.error('CPT Taxonomy Syncer Error:', err);
			});
	}, [postType, taxonomy, page, perPage, search]);

	const totalPages = Math.ceil(total / perPage);

	return (
		<div className="cpt-tax-post-type-relationships-dashboard">
			{/* Search */}
			<div className="cpt-tax-search" style={{ marginBottom: '20px', padding: '15px', background: '#fff', border: '1px solid #c3c4c7' }}>
				<label htmlFor="search-input" style={{ display: 'block', marginBottom: '5px', fontWeight: '600' }}>
					{__('Search Posts', 'cpt-taxonomy-syncer')}
				</label>
				<input
					id="search-input"
					type="text"
					value={search}
					onChange={(e) => {
						setSearch(e.target.value);
						setPage(1);
					}}
					placeholder={__('Search by post title...', 'cpt-taxonomy-syncer')}
					style={{ width: '100%', maxWidth: '400px', padding: '5px' }}
				/>
			</div>

			{/* Loading State */}
			{loading && (
				<div style={{ padding: '20px', textAlign: 'center' }}>
					<p>{__('Loading relationships...', 'cpt-taxonomy-syncer')}</p>
				</div>
			)}

			{/* Error State */}
			{error && (
				<div className="notice notice-error" style={{ padding: '10px' }}>
					<p>{error}</p>
				</div>
			)}

			{/* Relationships List */}
			{!loading && !error && (
				<>
					<div style={{ marginBottom: '10px' }}>
						<p>
							{__('Total posts with relationships:', 'cpt-taxonomy-syncer')} <strong>{total}</strong>
						</p>
					</div>

					{relationships.length === 0 ? (
						<div className="notice notice-info" style={{ padding: '15px' }}>
							<p>{__('No relationships found.', 'cpt-taxonomy-syncer')}</p>
						</div>
					) : (
						<div className="cpt-tax-relationships-list">
							{relationships.map((rel) => (
								<div
									key={rel.post.id}
									className="cpt-tax-relationship-item"
									style={{
										marginBottom: '20px',
										padding: '15px',
										background: '#fff',
										border: '1px solid #c3c4c7',
										borderLeft: '4px solid #2271b1',
									}}
								>
									{/* Main Post */}
									<div className="cpt-tax-main-post" style={{ marginBottom: '15px' }}>
										<h3 style={{ margin: '0 0 10px 0', fontSize: '16px' }}>
											<a
												href={rel.post.edit_url}
												style={{ textDecoration: 'none', color: '#2271b1' }}
											>
												{rel.post.title}
											</a>
											<span
												className="post-state"
												style={{
													marginLeft: '10px',
													fontSize: '12px',
													fontWeight: 'normal',
													color: '#646970',
												}}
											>
												— {rel.post.post_status}
											</span>
										</h3>
										<div style={{ fontSize: '13px', color: '#646970', marginBottom: '10px' }}>
											<strong>{__('Synced Term:', 'cpt-taxonomy-syncer')}</strong>{' '}
											<code>{rel.term.name}</code> (ID: {rel.term.id})
										</div>
										<div style={{ display: 'flex', gap: '5px', flexWrap: 'wrap' }}>
											<a
												href={rel.post.edit_url}
												className="button button-small"
												target="_blank"
												rel="noopener noreferrer"
											>
												{__('Edit Post', 'cpt-taxonomy-syncer')}
											</a>
											<a
												href={rel.post.view_url}
												className="button button-small"
												target="_blank"
												rel="noopener noreferrer"
											>
												{__('View Post', 'cpt-taxonomy-syncer')}
											</a>
										</div>
									</div>

									{/* Related Posts */}
									{rel.related_count > 0 ? (
										<div className="cpt-tax-related-posts" style={{ marginLeft: '20px', paddingLeft: '20px', borderLeft: '2px solid #c3c4c7' }}>
											<h4 style={{ margin: '0 0 10px 0', fontSize: '14px', fontWeight: '600' }}>
												{__('Related Posts', 'cpt-taxonomy-syncer')} ({rel.related_count})
											</h4>
											<ul style={{ margin: '0', paddingLeft: '20px', listStyle: 'disc' }}>
												{rel.related_posts.map((relatedPost) => (
													<li key={relatedPost.id} style={{ marginBottom: '8px' }}>
														<a
															href={relatedPost.edit_url}
															style={{ textDecoration: 'none', color: '#2271b1' }}
															target="_blank"
															rel="noopener noreferrer"
														>
															{relatedPost.title}
														</a>
														<span
															style={{
																marginLeft: '8px',
																fontSize: '12px',
																color: '#646970',
															}}
														>
															({relatedPost.post_type} — {relatedPost.post_status})
														</span>
														<a
															href={relatedPost.view_url}
															className="button button-small"
															style={{ marginLeft: '8px' }}
															target="_blank"
															rel="noopener noreferrer"
														>
															{__('View', 'cpt-taxonomy-syncer')}
														</a>
													</li>
												))}
											</ul>
										</div>
									) : (
										<div
											style={{
												marginLeft: '20px',
												paddingLeft: '20px',
												color: '#646970',
												fontSize: '13px',
												fontStyle: 'italic',
											}}
										>
											{__('No related posts found.', 'cpt-taxonomy-syncer')}
										</div>
									)}
								</div>
							))}
						</div>
					)}

					{/* Pagination */}
					{totalPages > 1 && (
						<div className="tablenav bottom" style={{ marginTop: '20px' }}>
							<div className="tablenav-pages">
								<span className="displaying-num">
									{__('Items per page:', 'cpt-taxonomy-syncer')}{' '}
									<select
										value={perPage}
										onChange={(e) => {
											setPerPage(parseInt(e.target.value, 10));
											setPage(1);
										}}
										style={{ margin: '0 5px' }}
									>
										<option value="10">10</option>
										<option value="20">20</option>
										<option value="50">50</option>
										<option value="100">100</option>
									</select>
								</span>
								<span className="pagination-links">
									<button
										className="button"
										disabled={page === 1}
										onClick={() => setPage(1)}
										style={{ marginRight: '5px' }}
									>
										«
									</button>
									<button
										className="button"
										disabled={page === 1}
										onClick={() => setPage(page - 1)}
										style={{ marginRight: '5px' }}
									>
										‹
									</button>
									<span className="paging-input">
										{__('Page', 'cpt-taxonomy-syncer')} {page} {__('of', 'cpt-taxonomy-syncer')} {totalPages}
									</span>
									<button
										className="button"
										disabled={page >= totalPages}
										onClick={() => setPage(page + 1)}
										style={{ marginLeft: '5px' }}
									>
										›
									</button>
									<button
										className="button"
										disabled={page >= totalPages}
										onClick={() => setPage(totalPages)}
										style={{ marginLeft: '5px' }}
									>
										»
									</button>
								</span>
							</div>
						</div>
					)}
				</>
			)}
		</div>
	);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}

function init() {
	const container = document.getElementById('cpt-tax-post-type-relationships-dashboard');
	if (container) {
		const { render, createElement } = wp.element;
		render(createElement(PostTypeRelationshipsDashboard), container);
	}
}

export default PostTypeRelationshipsDashboard;
