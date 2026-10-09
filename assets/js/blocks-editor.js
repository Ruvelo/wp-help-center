/**
 * Ruvelo Help Center: block editor side of the help center blocks.
 *
 * Every block is dynamic: it is rendered in PHP on the site and previewed here
 * with ServerSideRender. Titles, descriptions and attributes come from each
 * block.json, which WordPress hands to the editor; this file only adds settings
 * panels. Plain JavaScript on the wp.* globals, so there is no build step.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl, Disabled } = wp.components;
	const { createElement: h, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	// Settings panel fields for each block: [attribute, control, label, extra].
	const fields = {
		'ruvelo/help-search': [
			[ 'variant', 'select', __( 'Style', 'ruvelo-help-center' ), { options: [
				{ label: __( 'Hero, with a headline', 'ruvelo-help-center' ), value: 'hero' },
				{ label: __( 'Compact search box', 'ruvelo-help-center' ), value: 'compact' },
			] } ],
			[ 'headline', 'text', __( 'Headline', 'ruvelo-help-center' ), { help: __( 'Leave empty to use the one from the settings.', 'ruvelo-help-center' ), when: ( a ) => a.variant !== 'compact' } ],
			[ 'intro', 'text', __( 'Line under the headline', 'ruvelo-help-center' ), { when: ( a ) => a.variant !== 'compact' } ],
			[ 'placeholder', 'text', __( 'Placeholder', 'ruvelo-help-center' ) ],
			[ 'showPopular', 'toggle', __( 'Show popular articles under the box', 'ruvelo-help-center' ), { when: ( a ) => a.variant !== 'compact' } ],
		],
		'ruvelo/help-categories': [
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ), { placeholder: __( 'Browse by topic', 'ruvelo-help-center' ) } ],
			[ 'parent', 'text', __( 'Only sub-categories of', 'ruvelo-help-center' ), { help: __( 'A category slug. Leave empty for the top level.', 'ruvelo-help-center' ) } ],
			[ 'showDescription', 'toggle', __( 'Show descriptions', 'ruvelo-help-center' ) ],
			[ 'showCount', 'toggle', __( 'Show article counts', 'ruvelo-help-center' ) ],
		],
		'ruvelo/help-popular': [
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ), { placeholder: __( 'Popular articles', 'ruvelo-help-center' ) } ],
			[ 'count', 'range', __( 'Articles (0 = from the settings)', 'ruvelo-help-center' ), { min: 0, max: 24 } ],
		],
		'ruvelo/help-articles': [
			[ 'category', 'text', __( 'Category', 'ruvelo-help-center' ), { help: __( 'A category slug or name, like billing-invoices.', 'ruvelo-help-center' ) } ],
			[ 'search', 'text', __( 'Or a search', 'ruvelo-help-center' ), { help: __( 'Show the best matches for these words instead, like “refund”.', 'ruvelo-help-center' ) } ],
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ) ],
			[ 'limit', 'range', __( 'Articles', 'ruvelo-help-center' ), { min: 1, max: 20 } ],
			[ 'variant', 'select', __( 'Style', 'ruvelo-help-center' ), { options: [
				{ label: __( 'Compact box', 'ruvelo-help-center' ), value: 'compact' },
				{ label: __( 'List with summaries', 'ruvelo-help-center' ), value: 'list' },
			] } ],
		],
		'ruvelo/help-contact': [
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ), { placeholder: __( 'Still need help?', 'ruvelo-help-center' ) } ],
			[ 'text', 'text', __( 'Text', 'ruvelo-help-center' ) ],
			[ 'label', 'text', __( 'Button', 'ruvelo-help-center' ) ],
			[ 'url', 'text', __( 'Link', 'ruvelo-help-center' ), { help: __( 'Leave empty to use the contact page or email from the settings.', 'ruvelo-help-center' ) } ],
		],
		'ruvelo/help-vote': [
			[ 'heading', 'text', __( 'Question', 'ruvelo-help-center' ), { placeholder: __( 'Was this article helpful?', 'ruvelo-help-center' ) } ],
		],
		'ruvelo/help-outline': [
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ), { placeholder: __( 'On this page', 'ruvelo-help-center' ) } ],
			[ 'minHeadings', 'range', __( 'Show from this many headings', 'ruvelo-help-center' ), { min: 1, max: 6 } ],
		],
		'ruvelo/help-navigation': [
			[ 'showSearch', 'toggle', __( 'Show a search box above the menu', 'ruvelo-help-center' ) ],
		],
		'ruvelo/help-related': [
			[ 'heading', 'text', __( 'Heading', 'ruvelo-help-center' ), { placeholder: __( 'Related articles', 'ruvelo-help-center' ) } ],
			[ 'limit', 'range', __( 'Articles', 'ruvelo-help-center' ), { min: 1, max: 12 } ],
		],
		'ruvelo/help-search-results': [
			[ 'perPage', 'range', __( 'Results per page', 'ruvelo-help-center' ), { min: 5, max: 50 } ],
		],
	};

	const control = ( attributes, setAttributes, [ name, type, label, extra = {} ] ) => {
		if ( extra.when && ! extra.when( attributes ) ) {
			return null;
		}

		const props = { key: name, label, help: extra.help, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };
		const set = ( value ) => setAttributes( { [ name ]: value } );

		switch ( type ) {
			case 'toggle':
				return h( ToggleControl, { ...props, checked: !! attributes[ name ], onChange: set } );
			case 'select':
				return h( SelectControl, { ...props, value: attributes[ name ], options: extra.options, onChange: set } );
			case 'range':
				return h( RangeControl, { ...props, value: attributes[ name ], min: extra.min, max: extra.max, onChange: ( value ) => set( Number( value ) || 0 ) } );
			default:
				return h( TextControl, { ...props, value: attributes[ name ] || '', placeholder: extra.placeholder, onChange: set } );
		}
	};

	const names = [
		'help-search', 'help-categories', 'help-popular', 'help-articles', 'help-contact', 'help-vote', 'help-outline',
		'help-navigation', 'help-breadcrumbs', 'help-article-meta', 'help-related', 'help-category-header', 'help-search-results',
	];

	names.forEach( ( short ) => {
		const name = 'ruvelo/' + short;

		registerBlockType( name, {
			edit( { attributes, setAttributes, context } ) {
				const blockProps = useBlockProps();
				const panel = ( fields[ name ] || [] ).map( ( field ) => control( attributes, setAttributes, field ) );
				const query = context && context.postId ? { post_id: context.postId } : undefined;

				return h( Fragment, null,
					panel.length ? h( InspectorControls, null, h( PanelBody, { title: __( 'Settings', 'ruvelo-help-center' ) }, panel ) ) : null,
					h( 'div', blockProps,
						h( Disabled, null,
							h( ServerSideRender, { block: name, attributes, urlQueryArgs: query, skipBlockSupportAttributes: true } )
						)
					)
				);
			},
			save: () => null,
		} );
	} );
}( window.wp ) );
