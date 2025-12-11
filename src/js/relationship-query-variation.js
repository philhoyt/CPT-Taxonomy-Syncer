/**
 * CPT-Taxonomy Syncer - Relationship Query Loop Variation
 * 
 * Extends the core Query Loop block to support dynamic synced relationships
 */

(function() {
    'use strict';
    
    // Check if required WordPress objects are available
    if (typeof wp === 'undefined' || !wp.blocks || !wp.i18n || !wp.hooks || !wp.compose || !wp.element || !wp.blockEditor || !wp.components) {
        console.error('CPT-Tax Syncer: Required WordPress dependencies not loaded');
        return;
    }
    const { registerBlockVariation } = wp.blocks;
    const { __ } = wp.i18n;
    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment, createElement } = wp.element;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl } = wp.components;

    // Add custom controls to Query Loop block
    // This must be registered immediately, not in wp.domReady
    const withRelationshipControls = createHigherOrderComponent((BlockEdit) => {
        return (props) => {
            const { attributes, setAttributes, name } = props;

            // Handle Query Loop blocks
            if (name === 'core/query') {
                const { query = {} } = attributes;
                const { 
                    useSyncedRelationship = false,
                    useCustomOrder = false
                } = query;

                const updateSyncerSetting = (key, value) => {
                    // Store settings directly in the query object for better persistence
                    const newQuery = {
                        ...query,
                        [key]: value
                    };
                    
                    if (key === 'useSyncedRelationship' && value) {
                        // When enabling, also set inherit to false
                        newQuery.inherit = false;
                    }
                    
                    setAttributes({
                        query: newQuery
                    });
                };

                return createElement(Fragment, null,
                    createElement(BlockEdit, props),
                    createElement(InspectorControls, null,
                        createElement(PanelBody, {
                            title: __('Synced Relationships', 'cpt-taxonomy-syncer'),
                            initialOpen: false
                        },
                            createElement(ToggleControl, {
                                label: __('Use synced relationship', 'cpt-taxonomy-syncer'),
                                help: __('Automatically query posts related to the current post through synced CPT-taxonomy relationships', 'cpt-taxonomy-syncer'),
                                checked: useSyncedRelationship,
                                onChange: (value) => {
                                    updateSyncerSetting('useSyncedRelationship', value);
                                    if (value) {
                                        // Set inherit to false when using synced relationships
                                        setAttributes({
                                            query: {
                                                ...query,
                                                inherit: false
                                            }
                                        });
                                    }
                                }
                            }),
                            
                            useSyncedRelationship && createElement(ToggleControl, {
                                label: __('Use custom order', 'cpt-taxonomy-syncer'),
                                help: __('Display related posts in the custom order set in the Relationships dashboard', 'cpt-taxonomy-syncer'),
                                checked: useCustomOrder,
                                onChange: (value) => {
                                    updateSyncerSetting('useCustomOrder', value);
                                }
                            }),
                            
                            useSyncedRelationship && createElement('p', {
                                style: { fontStyle: 'italic', color: '#666' }
                            }, __('This will show posts from the selected Post Type that are assigned to the same taxonomy terms as the current post.', 'cpt-taxonomy-syncer'))
                        )
                    )
                );
            }

            return createElement(BlockEdit, props);
        };
    }, 'withRelationshipControls');

    // Apply the higher-order component filter immediately
    addFilter(
        'editor.BlockEdit',
        'cpt-tax-syncer/relationship-controls',
        withRelationshipControls
    );

    // Wait for DOM ready and ensure we have the data
    wp.domReady(function() {
        // Register the block variation for Query Loop
        registerBlockVariation('core/query', {
        name: 'cpt-tax-syncer-relationship-query',
        title: __('Relationship Query Loop', 'cpt-taxonomy-syncer'),
        description: __('Display posts with synced relationships from the current post', 'cpt-taxonomy-syncer'),
        icon: 'networking',
        category: 'design',
        keywords: ['relationship', 'sync', 'taxonomy', 'cpt'],
        attributes: {
            query: {
                perPage: 10,
                pages: 0,
                offset: 0,
                postType: '',
                order: 'desc',
                orderBy: 'date',
                author: '',
                search: '',
                exclude: [],
                sticky: '',
                inherit: false,
                // Custom attributes for relationship query
                useSyncedRelationship: true,
                relationshipDirection: 'posts_from_terms', // or 'terms_from_posts'
                targetPostType: ''
            }
        },
        scope: ['inserter'],
        isActive: (blockAttributes) => {
            return blockAttributes.query?.useSyncedRelationship === true;
        }
        });
    });

})();


