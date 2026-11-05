/**
 * Editor flow for the Previous/Next Post (Relationship) blocks.
 *
 * Pairs the built-in post type with post_tag, seeds the same fixture the
 * PHPUnit suite uses (a parent, three related posts with menu_order 3, 1, 2
 * and a saved order of p3, p1, p2), then inserts both blocks through the
 * editor and checks the links the front end renders.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli } = require( './wp-cli' );

const PAIRS_OPTION = 'cpt_tax_syncer_pairs';
const PAIR = 'post|post_tag';
const ORDER_ROUTE = '/cpt-tax-syncer/v1/relationship-order';
const NEXT_BLOCK = 'cpt-tax-syncer/next-post-relationship';
const PREVIOUS_BLOCK = 'cpt-tax-syncer/previous-post-relationship';

/**
 * Saves a custom order on the parent post.
 *
 * @param {Object}   requestUtils Request utils fixture.
 * @param {number}   parentId     The parent post.
 * @param {number[]} order        Related post IDs in the wanted order.
 */
async function saveOrder( requestUtils, parentId, order ) {
	await requestUtils.rest( {
		method: 'POST',
		path: ORDER_ROUTE,
		data: { parent_post_id: parentId, taxonomy: 'post_tag', order },
	} );
}

/**
 * Inserts one adjacent-post block and configures it in the inspector.
 *
 * @param {Object}  editor         Editor fixture.
 * @param {Object}  page           Playwright page.
 * @param {string}  name           Block name.
 * @param {boolean} useCustomOrder Whether to leave "Use Custom Order" on.
 */
async function insertConfiguredBlock( editor, page, name, useCustomOrder ) {
	await editor.insertBlock( { name } );
	await editor.openDocumentSettingsSidebar();
	await page.getByRole( 'tab', { name: 'Block' } ).click();

	await page
		.getByRole( 'combobox', { name: 'Relationship Pair' } )
		.selectOption( PAIR );

	const toggle = page.getByRole( 'checkbox', { name: 'Use Custom Order' } );
	await expect( toggle ).toBeChecked();
	if ( ! useCustomOrder ) {
		await toggle.uncheck();
	}

	await expect(
		editor.canvas.locator( `[data-type="${ name }"]` )
	).toHaveCount( 1 );
}

/**
 * Adds the post being edited to the "Parent" tag through the post sidebar.
 *
 * @param {Object} page Playwright page.
 */
async function addParentTag( page ) {
	await page.getByRole( 'tab', { name: 'Post' } ).click();

	const panel = page.getByRole( 'button', { name: 'Tags', exact: true } );
	if ( ( await panel.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await panel.click();
	}

	// Older WordPress labels the field "Add New Tag", newer releases "Add Tag".
	const input = page.getByRole( 'combobox', { name: /^Add (New )?Tag$/ } );
	await input.fill( 'Parent' );
	await input.press( 'Enter' );
	// The token's screen-reader text names it and its position.
	await expect( page.getByText( 'Parent (1 of 1)' ) ).toBeAttached();
}

/**
 * Writes a post carrying both blocks and the Parent tag, then publishes it.
 *
 * @param {Object}  fixtures        Playwright fixtures.
 * @param {Object}  fixtures.admin  Admin fixture.
 * @param {Object}  fixtures.editor Editor fixture.
 * @param {Object}  fixtures.page   Playwright page.
 * @param {string}  title           Post title.
 * @param {boolean} useCustomOrder  Whether both blocks keep the custom order.
 * @return {Promise<number>} The published post ID.
 */
async function publishPostWithBlocks(
	{ admin, editor, page },
	title,
	useCustomOrder
) {
	await admin.createNewPost( { title } );
	await insertConfiguredBlock( editor, page, PREVIOUS_BLOCK, useCustomOrder );
	await insertConfiguredBlock( editor, page, NEXT_BLOCK, useCustomOrder );
	await addParentTag( page );

	return editor.publishPost();
}

/**
 * Asserts the front-end links of a post.
 *
 * @param {Object} page     Playwright page.
 * @param {number} postId   The post to view.
 * @param {string} previous Expected href of the previous link.
 * @param {string} next     Expected href of the next link.
 */
async function expectNeighbours( page, postId, previous, next ) {
	await page.goto( `/?p=${ postId }` );

	await expect(
		page.locator(
			'.wp-block-cpt-tax-syncer-previous-post-relationship a[rel="prev"]'
		)
	).toHaveAttribute( 'href', previous );
	await expect(
		page.locator(
			'.wp-block-cpt-tax-syncer-next-post-relationship a[rel="next"]'
		)
	).toHaveAttribute( 'href', next );
}

/**
 * Seeds the fixture the PHPUnit suite uses: a published "Parent" post, whose
 * tag the plugin creates, and three tagged posts with menu_order 3, 1, 2.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<{parentId: number, related: Object}>} Parent ID and the related posts by name.
 */
async function seedFixture( requestUtils ) {
	wpCli(
		'option',
		'update',
		PAIRS_OPTION,
		JSON.stringify( [
			{
				cpt_slug: 'post',
				taxonomy_slug: 'post_tag',
				enable_redirect: false,
			},
		] ),
		'--format=json'
	);
	await requestUtils.deleteAllPosts();

	const parentId = (
		await requestUtils.createPost( { title: 'Parent', status: 'publish' } )
	).id;
	const [ term ] = await requestUtils.rest( {
		path: '/wp/v2/tags',
		params: { slug: 'parent' },
	} );
	expect( term ).toBeDefined();

	const related = {};
	for ( const [ name, menuOrder ] of Object.entries( {
		p1: 3,
		p2: 1,
		p3: 2,
	} ) ) {
		related[ name ] = await requestUtils.createPost( {
			title: name,
			status: 'publish',
			tags: [ term.id ],
		} );
		// Posts have no page-attributes support, so menu_order is not writable over REST.
		wpCli(
			'post',
			'update',
			String( related[ name ].id ),
			`--menu_order=${ menuOrder }`
		);
	}

	await saveOrder( requestUtils, parentId, [
		related.p3.id,
		related.p1.id,
		related.p2.id,
	] );

	return { parentId, related };
}

test.describe( 'Adjacent-post blocks', () => {
	let parentId;
	let related;

	test.beforeAll( async ( { requestUtils } ) => {
		( { parentId, related } = await seedFixture( requestUtils ) );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// While the pair is still configured, deleting a post also removes its linked tag.
		await requestUtils.deleteAllPosts();
		wpCli( 'option', 'delete', PAIRS_OPTION );
	} );

	test( 'links follow the saved custom order', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const postId = await publishPostWithBlocks(
			{ admin, editor, page },
			'Custom order post',
			true
		);

		// Slot the new post between p2 and p3; by menu_order alone it would come first.
		await saveOrder( requestUtils, parentId, [
			related.p1.id,
			related.p2.id,
			postId,
			related.p3.id,
		] );

		await expectNeighbours(
			page,
			postId,
			related.p2.link,
			related.p3.link
		);
	} );

	test( 'links fall back to menu_order then ID when custom order is off', async ( {
		admin,
		editor,
		page,
	} ) => {
		const postId = await publishPostWithBlocks(
			{ admin, editor, page },
			'Menu order post',
			false
		);

		// menu_order 2 ties with p3; the lower ID (p3) sorts first, so p3 is
		// previous and p1 (menu_order 3) is next.
		wpCli( 'post', 'update', String( postId ), '--menu_order=2' );

		await expectNeighbours(
			page,
			postId,
			related.p3.link,
			related.p1.link
		);
	} );
} );
