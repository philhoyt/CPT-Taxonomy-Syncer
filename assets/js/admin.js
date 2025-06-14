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
        
        // Handle syncing posts to terms
        $('.sync-posts-to-terms').on('click', function() {
            const button = $(this);
            const cptSlug = button.data('cpt');
            const taxonomySlug = button.data('taxonomy');
            
            // Disable the button
            button.prop('disabled', true).text('Syncing...');
            
            // Make the REST API request
            $.ajax({
                url: cptTaxSyncerAdmin.restBase + '/sync-posts-to-terms',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
                },
                data: {
                    cpt_slug: cptSlug,
                    taxonomy_slug: taxonomySlug
                },
                success: function(response) {
                    // Show the result
                    $('#sync-result')
                        .removeClass('notice-error')
                        .addClass('notice-success')
                        .show()
                        .find('p')
                        .text(response.message);
                    
                    // Re-enable the button
                    button.prop('disabled', false).text('Sync Posts to Terms');
                },
                error: function(xhr) {
                    // Show the error
                    $('#sync-result')
                        .removeClass('notice-success')
                        .addClass('notice-error')
                        .show()
                        .find('p')
                        .text('Error: ' + (xhr.responseJSON ? xhr.responseJSON.message : 'Unknown error'));
                    
                    // Re-enable the button
                    button.prop('disabled', false).text('Sync Posts to Terms');
                }
            });
        });
        
        // Handle syncing terms to posts
        $('.sync-terms-to-posts').on('click', function() {
            const button = $(this);
            const cptSlug = button.data('cpt');
            const taxonomySlug = button.data('taxonomy');
            
            // Disable the button
            button.prop('disabled', true).text('Syncing...');
            
            // Make the REST API request
            $.ajax({
                url: cptTaxSyncerAdmin.restBase + '/sync-terms-to-posts',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', cptTaxSyncerAdmin.nonce);
                },
                data: {
                    cpt_slug: cptSlug,
                    taxonomy_slug: taxonomySlug
                },
                success: function(response) {
                    // Show the result
                    $('#sync-result')
                        .removeClass('notice-error')
                        .addClass('notice-success')
                        .show()
                        .find('p')
                        .text(response.message);
                    
                    // Re-enable the button
                    button.prop('disabled', false).text('Sync Terms to Posts');
                },
                error: function(xhr) {
                    // Show the error
                    $('#sync-result')
                        .removeClass('notice-success')
                        .addClass('notice-error')
                        .show()
                        .find('p')
                        .text('Error: ' + (xhr.responseJSON ? xhr.responseJSON.message : 'Unknown error'));
                    
                    // Re-enable the button
                    button.prop('disabled', false).text('Sync Terms to Posts');
                }
            });
        });
    });
})(jQuery);
