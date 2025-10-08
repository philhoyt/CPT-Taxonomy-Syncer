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

    // Wait for DOM ready and ensure we have the data
    wp.domReady(function() {
        // Register the block variation
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

    // Add custom controls to Query Loop block
    const withRelationshipControls = createHigherOrderComponent((BlockEdit) => {
        return (props) => {
            const { attributes, setAttributes, name } = props;

            // Only add controls to Query Loop blocks
            if (name !== 'core/query') {
                return createElement(BlockEdit, props);
            }

            const { query = {} } = attributes;
            const { 
                useSyncedRelationship = false, 
                relationshipDirection = 'posts_from_terms'
            } = query;

            // Debug logging
            console.log('All attributes:', attributes);
            console.log('Query settings:', query);
            console.log('useSyncedRelationship:', useSyncedRelationship);

            const updateSyncerSetting = (key, value) => {
                console.log('Updating syncer setting:', key, '=', value);
                
                // Store settings directly in the query object for better persistence
                const newQuery = {
                    ...query,
                    [key]: value
                };
                
                if (key === 'useSyncedRelationship' && value) {
                    // When enabling, also set inherit to false
                    newQuery.inherit = false;
                }
                
                console.log('New query object:', newQuery);
                setAttributes({
                    query: newQuery
                });
            };

            // Get available post types from localized data
            const defaultOptions = [{ label: __('Select Post Type', 'cpt-taxonomy-syncer'), value: '' }];
            const serverPostTypes = window.cptTaxSyncerQuery?.postTypes || [];
            const postTypeOptions = [...defaultOptions, ...serverPostTypes];

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
                        
                        useSyncedRelationship && createElement(Fragment, null,
                            createElement(SelectControl, {
                                label: __('Relationship Direction', 'cpt-taxonomy-syncer'),
                                value: relationshipDirection,
                                options: [
                                    { 
                                        label: __('Posts linked to current post\'s terms', 'cpt-taxonomy-syncer'), 
                                        value: 'posts_from_terms' 
                                    },
                                    { 
                                        label: __('Terms linked to current post', 'cpt-taxonomy-syncer'), 
                                        value: 'terms_from_posts' 
                                    }
                                ],
                                onChange: (value) => updateSyncerSetting('relationshipDirection', value),
                                help: __('Choose how to find related content. Use the Post Type setting above to select which post type to query.', 'cpt-taxonomy-syncer')
                            })
                        )
                    )
                )
            );
        };
    }, 'withRelationshipControls');

    // Apply the higher-order component
    addFilter(
        'editor.BlockEdit',
        'cpt-tax-syncer/relationship-controls',
        withRelationshipControls
    );

})();
