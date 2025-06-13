/**
 * CPT-Taxonomy Syncer - Block Editor Integration
 * 
 * Handles integration with the WordPress block editor for term creation and syncing
 */

(function() {
    // Wait for WordPress and the block editor to be available
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof wp === 'undefined' || !wp.data || !wp.data.select('core/editor')) {
            console.log('CPT-Taxonomy Syncer: Block editor not detected');
            return;
        }
        
        console.log('CPT-Taxonomy Syncer: Block editor detected');
        
        // Get the data passed from PHP
        const taxonomySlug = cptTaxSyncerData.taxonomySlug;
        const restBase = cptTaxSyncerData.restBase;
        const nonce = cptTaxSyncerData.nonce;
        
        // Set up error tracking
        window.addEventListener('error', function(event) {
            // Only report errors related to taxonomy terms
            if (event.error && event.error.message && 
                (event.error.message.includes('toLowerCase') || 
                 event.error.message.includes('taxonomy') || 
                 event.error.message.includes('term'))) {
                
                console.error('CPT-Taxonomy Syncer: Caught JS error', event.error);
                
                // Report the error via REST API
                fetch(`${restBase}/log-error`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    body: JSON.stringify({
                        message: event.error.message,
                        stack: event.error.stack,
                        url: window.location.href
                    })
                }).catch(err => {
                    console.error('CPT-Taxonomy Syncer: Error reporting failed', err);
                });
            }
        });
        
        // Add custom term creation function
        window.cptTaxSyncerCreateTerm = async function(name, description = '') {
            try {
                console.log(`CPT-Taxonomy Syncer: Creating term "${name}" via REST API`);
                
                const response = await fetch(`${restBase}/create-term`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    body: JSON.stringify({
                        name: name,
                        description: description
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`Error: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('CPT-Taxonomy Syncer: Term creation response', data);
                
                // Refresh the terms in the block editor
                wp.data.dispatch('core').invalidateResolution('getEntityRecords', ['taxonomy', taxonomySlug]);
                
                return data;
            } catch (error) {
                console.error('CPT-Taxonomy Syncer: Error creating term', error);
                throw error;
            }
        };
        
        // Enhance the block editor UI for term creation
        enhanceBlockEditorUI();
    });
    
    /**
     * Enhance the block editor UI for term creation
     */
    function enhanceBlockEditorUI() {
        // Wait for the block editor to be fully loaded
        const checkInterval = setInterval(function() {
            // Check if the taxonomy panel is loaded
            const taxonomyPanel = document.querySelector('.editor-post-taxonomies__hierarchical-terms-list');
            if (taxonomyPanel) {
                clearInterval(checkInterval);
                setupTermCreationEnhancement();
            }
        }, 500);
        
        // Stop checking after 10 seconds to avoid infinite loops
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 10000);
    }
    
    /**
     * Set up term creation enhancement
     */
    function setupTermCreationEnhancement() {
        console.log('CPT-Taxonomy Syncer: Setting up term creation enhancement');
        
        // Intercept term creation in the block editor
        const originalFetch = window.fetch;
        window.fetch = async function(url, options) {
            // Clone the options to avoid modifying the original
            const clonedOptions = options ? { ...options } : {};
            
            // Check if this is a term creation request
            if (typeof url === 'string' && url.includes('/wp/v2/') && 
                url.includes(cptTaxSyncerData.taxonomySlug) && 
                options && options.method === 'POST') {
                
                console.log('CPT-Taxonomy Syncer: Intercepted term creation request', url);
                
                try {
                    // Parse the request body to get the term name
                    let termName = '';
                    if (options.body) {
                        if (typeof options.body === 'string') {
                            try {
                                const bodyData = JSON.parse(options.body);
                                termName = bodyData.name || '';
                            } catch (e) {
                                console.error('CPT-Taxonomy Syncer: Error parsing request body', e);
                            }
                        } else if (options.body instanceof FormData) {
                            termName = options.body.get('name') || '';
                        }
                    }
                    
                    console.log(`CPT-Taxonomy Syncer: Creating term "${termName}" via intercepted request`);
                    
                    // Let the original request go through
                    const response = await originalFetch(url, options);
                    
                    // Clone the response so we can read it multiple times
                    const clonedResponse = response.clone();
                    
                    // Process the response
                    try {
                        const data = await clonedResponse.json();
                        console.log('CPT-Taxonomy Syncer: Term creation response', data);
                        
                        // No need to make another request - our PHP hooks will handle the syncing
                        console.log('CPT-Taxonomy Syncer: Term synced automatically via PHP hooks');
                    } catch (error) {
                        console.error('CPT-Taxonomy Syncer: Error processing term creation response', error);
                    }
                    
                    return response;
                } catch (error) {
                    console.error('CPT-Taxonomy Syncer: Error in fetch intercept', error);
                    throw error;
                }
            }
            
            // For all other requests, pass through to the original fetch
            return originalFetch(url, clonedOptions);
        };
        
        // Subscribe to term changes in the block editor
        if (wp.data && wp.data.subscribe) {
            wp.data.subscribe(function() {
                const editor = wp.data.select('core/editor');
                if (!editor) return;
                
                const currentPost = editor.getCurrentPost();
                if (!currentPost || !currentPost.id) return;
                
                // Get the current terms for our taxonomy
                const terms = editor.getEditedPostAttribute(cptTaxSyncerData.taxonomySlug);
                
                // Log the current terms for debugging
                if (terms && terms.length > 0) {
                    console.log(`CPT-Taxonomy Syncer: Current ${cptTaxSyncerData.taxonomySlug} terms`, terms);
                }
            });
        }
        
        // Patch the term selector component to ensure term objects have all required fields
        patchTermSelector();
    }
    
    /**
     * Patch the term selector component
     */
    function patchTermSelector() {
        // Wait for the wp.data store to be available
        if (!wp.data || !wp.data.select || !wp.data.select('core')) {
            console.log('CPT-Taxonomy Syncer: wp.data.select not available yet');
            setTimeout(patchTermSelector, 500);
            return;
        }
        
        console.log('CPT-Taxonomy Syncer: Patching term selector');
        
        // Patch the FormTokenField component to prevent toLocaleLowerCase error
        patchFormTokenField();
        
        // Get the original selector
        const originalGetEntityRecords = wp.data.select('core').getEntityRecords;
        
        // Patch the selector
        if (originalGetEntityRecords) {
            wp.data.select('core').getEntityRecords = function(...args) {
                const [entityType, entityName, query] = args;
                
                // Only process taxonomy terms
                if (entityType === 'taxonomy' && entityName === cptTaxSyncerData.taxonomySlug) {
                    console.log('CPT-Taxonomy Syncer: Patching getEntityRecords for', entityName);
                    
                    // Get the original result
                    const result = originalGetEntityRecords.apply(this, args);
                    
                    // If the result is an array, ensure all terms have the required fields
                    if (Array.isArray(result)) {
                        return result.map(term => {
                            if (!term) return term;
                            
                            // Ensure all required fields are present
                            return {
                                id: term.id || 0,
                                name: term.name || '',
                                slug: term.slug || '',
                                taxonomy: term.taxonomy || cptTaxSyncerData.taxonomySlug,
                                link: term.link || '',
                                count: term.count || 0,
                                description: term.description || '',
                                ...term
                            };
                        });
                    }
                    
                    return result;
                }
                
                // For all other entities, use the original selector
                return originalGetEntityRecords.apply(this, args);
            };
        }
    }
    
    /**
     * Patch the FormTokenField component to prevent toLocaleLowerCase error
     */
    function patchFormTokenField() {
        // Wait for the components to be available
        if (!wp.components || !wp.components.FormTokenField) {
            console.log('CPT-Taxonomy Syncer: wp.components.FormTokenField not available yet');
            setTimeout(patchFormTokenField, 500);
            return;
        }
        
        console.log('CPT-Taxonomy Syncer: Patching FormTokenField component');
        
        // Monkey patch the FormTokenField component
        const originalFormTokenField = wp.components.FormTokenField;
        
        wp.components.FormTokenField = function(props) {
            // Create a safe copy of the suggestions
            const safeSuggestions = Array.isArray(props.suggestions) ? 
                props.suggestions.map(suggestion => {
                    // If suggestion is an object, ensure it has a name property
                    if (suggestion && typeof suggestion === 'object') {
                        return {
                            ...suggestion,
                            name: suggestion.name || suggestion.label || suggestion.value || ''
                        };
                    }
                    // If suggestion is a string, return it directly
                    return suggestion || '';
                }) : 
                [];
            
            // Create a safe copy of the value
            const safeValue = Array.isArray(props.value) ? 
                props.value.map(value => {
                    // If value is an object, ensure it has required properties
                    if (value && typeof value === 'object') {
                        return {
                            ...value,
                            id: value.id || 0,
                            name: value.name || '',
                            slug: value.slug || ''
                        };
                    }
                    // If value is a string, return it directly
                    return value || '';
                }) : 
                [];
            
            // Create a safe copy of the props
            const safeProps = {
                ...props,
                suggestions: safeSuggestions,
                value: safeValue
            };
            
            // Call the original component with safe props
            return originalFormTokenField(safeProps);
        };
    }
})();
