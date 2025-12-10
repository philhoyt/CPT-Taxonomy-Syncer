/**
 * CPT-Taxonomy Syncer - Admin JavaScript
 * 
 * Handles admin UI interactions
 */

(function($) {
	$(document).ready(function() {
		// Handle adding new pairs
		$('.add-pair').on('click', function() {
			// Remove the "no pairs" row if it exists
			$('.no-pairs').remove();
			
			// Get the template
			const template = $('#pair-template').html();
			
			// Get the current number of pairs
			const index = $('#cpt-tax-pairs tbody tr').length;
			
			// Replace the index placeholder
			const newRow = template.replace(/{{index}}/g, index);
			
			// Add the new row
			$('#cpt-tax-pairs tbody').append(newRow);
		});
		
		// Handle removing pairs
		$(document).on('click', '.remove-pair', function() {
			$(this).closest('tr').remove();
			
			// If there are no pairs, add the "no pairs" row
			if ($('#cpt-tax-pairs tbody tr').length === 0) {
				$('#cpt-tax-pairs tbody').append('<tr class="no-pairs"><td colspan="3">No pairs configured yet.</td></tr>');
			}
			
			// Reindex the pairs
			$('#cpt-tax-pairs tbody tr').each(function(index) {
				$(this).find('select, input').each(function() {
					const name = $(this).attr('name');
					if (name) {
						$(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
					}
				});
			});
		});
		
		// Handle syncing posts to terms (with batch processing)
		$('.sync-posts-to-terms').on('click', function() {
			const button = $(this);
			const cptSlug = button.data('cpt');
			const taxonomySlug = button.data('taxonomy');
			
			// Disable the button
			button.prop('disabled', true).text('Initializing...');
			
			// Show progress container
			const progressId = 'progress-' + cptSlug + '-' + taxonomySlug + '-posts';
			let $progressContainer = $('#' + progressId);
			if ($progressContainer.length === 0) {
				$progressContainer = $('<div id="' + progressId + '" class="cpt-tax-sync-progress" style="margin-top: 10px;"></div>');
				button.closest('td').append($progressContainer);
			}
			$progressContainer.html(
				'<div class="progress-bar-container" style="background: #f0f0f1; border-radius: 4px; height: 24px; margin-bottom: 8px; overflow: hidden;">' +
				'<div class="progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>' +
				'</div>' +
				'<div class="progress-text" style="font-size: 12px; color: #646970;"></div>'
			);
			
			// Initialize batch
			$.ajax({
				url: cptTaxSyncerAdmin.restBase + '/batch-sync/init',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
				},
				data: {
					cpt_slug: cptSlug,
					taxonomy_slug: taxonomySlug,
					operation: 'posts-to-terms'
				},
				success: function(response) {
					processBatch(response.batch_id, button, $progressContainer, 'posts-to-terms');
				},
				error: function(xhr) {
					showError(button, xhr, 'Sync Posts to Terms');
					$progressContainer.remove();
				}
			});
		});
		
		// Handle syncing terms to posts (with batch processing)
		$('.sync-terms-to-posts').on('click', function() {
			const button = $(this);
			const cptSlug = button.data('cpt');
			const taxonomySlug = button.data('taxonomy');
			
			// Disable the button
			button.prop('disabled', true).text('Initializing...');
			
			// Show progress container
			const progressId = 'progress-' + cptSlug + '-' + taxonomySlug + '-terms';
			let $progressContainer = $('#' + progressId);
			if ($progressContainer.length === 0) {
				$progressContainer = $('<div id="' + progressId + '" class="cpt-tax-sync-progress" style="margin-top: 10px;"></div>');
				button.closest('td').append($progressContainer);
			}
			$progressContainer.html(
				'<div class="progress-bar-container" style="background: #f0f0f1; border-radius: 4px; height: 24px; margin-bottom: 8px; overflow: hidden;">' +
				'<div class="progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>' +
				'</div>' +
				'<div class="progress-text" style="font-size: 12px; color: #646970;"></div>'
			);
			
			// Initialize batch
			$.ajax({
				url: cptTaxSyncerAdmin.restBase + '/batch-sync/init',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
				},
				data: {
					cpt_slug: cptSlug,
					taxonomy_slug: taxonomySlug,
					operation: 'terms-to-posts'
				},
				success: function(response) {
					processBatch(response.batch_id, button, $progressContainer, 'terms-to-posts');
				},
				error: function(xhr) {
					showError(button, xhr, 'Sync Terms to Posts');
					$progressContainer.remove();
				}
			});
		});
		
		// Process batch with progress tracking
		function processBatch(batchId, button, $progressContainer, operation) {
			const buttonText = operation === 'posts-to-terms' ? 'Sync Posts to Terms' : 'Sync Terms to Posts';
			
			// Process one batch
			$.ajax({
				url: cptTaxSyncerAdmin.restBase + '/batch-sync/process',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
				},
				data: {
					batch_id: batchId
				},
				success: function(response) {
					// Update progress bar
					const percentage = response.percentage || 0;
					$progressContainer.find('.progress-bar').css('width', percentage + '%');
					$progressContainer.find('.progress-text').text(
						response.message + ' (' + response.processed + '/' + response.total + ')'
					);
					
					button.text('Syncing... ' + Math.round(percentage) + '%');
					
					if (response.complete) {
						// Show success message
						$('#sync-result')
							.removeClass('notice-error')
							.addClass('notice-success')
							.show()
							.find('p')
							.text(response.message);
						
						// Cleanup
						$.ajax({
							url: cptTaxSyncerAdmin.restBase + '/batch-sync/cleanup',
							method: 'POST',
							beforeSend: function(xhr) {
								xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
							},
							data: {
								batch_id: batchId
							}
						});
						
						// Re-enable button and remove progress
						button.prop('disabled', false).text(buttonText);
						setTimeout(function() {
							$progressContainer.fadeOut(function() {
								$(this).remove();
							});
						}, 2000);
					} else {
						// Process next batch (with small delay to prevent overwhelming the server)
						setTimeout(function() {
							processBatch(batchId, button, $progressContainer, operation);
						}, 100);
					}
				},
				error: function(xhr) {
					showError(button, xhr, buttonText);
					$progressContainer.remove();
					
					// Cleanup on error
					$.ajax({
						url: cptTaxSyncerAdmin.restBase + '/batch-sync/cleanup',
						method: 'POST',
						beforeSend: function(xhr) {
							xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
						},
						data: {
							batch_id: batchId
						}
					});
				}
			});
		}
		
		// Show error message
		function showError(button, xhr, buttonText) {
			$('#sync-result')
				.removeClass('notice-success')
				.addClass('notice-error')
				.show()
				.find('p')
				.text('Error: ' + (xhr.responseJSON ? xhr.responseJSON.message : 'Unknown error'));
			
			button.prop('disabled', false).text(buttonText);
		}
	});
})(jQuery);
