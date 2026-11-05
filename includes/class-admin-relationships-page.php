<?php
/**
 * The Relationships dashboard under each synced post type's menu.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Relationships submenu pages and mounts the React dashboard.
 */
class CPT_Tax_Syncer_Admin_Relationships_Page {
	/**
	 * Submenu slug prefix; the post type is appended.
	 */
	const PAGE_SLUG_PREFIX = 'cpt-tax-syncer-relationships-';

	/**
	 * Dashboard script handle.
	 */
	const SCRIPT_HANDLE = 'cpt-tax-syncer-post-type-relationships';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_relationship_dashboard_pages' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard' ) );
	}

	/**
	 * Adds a Relationships page under every synced post type.
	 */
	public function add_relationship_dashboard_pages() {
		foreach ( CPT_Tax_Syncer_Pair::all() as $pair ) {
			$post_type_object = get_post_type_object( $pair->cpt_slug );
			if ( ! $post_type_object ) {
				continue;
			}

			add_submenu_page(
				'post' === $pair->cpt_slug ? 'edit.php' : 'edit.php?post_type=' . $pair->cpt_slug,
				sprintf(
					/* translators: %s: Post type name */
					__( 'Relationships - %s', 'cpt-taxonomy-syncer' ),
					$post_type_object->labels->name
				),
				__( 'Relationships', 'cpt-taxonomy-syncer' ),
				$post_type_object->cap->edit_posts,
				self::PAGE_SLUG_PREFIX . $pair->cpt_slug,
				function () use ( $pair ) {
					$this->render_post_type_relationships_page( $pair );
				}
			);
		}
	}

	/**
	 * Enqueues the dashboard script on the Relationships pages.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_dashboard( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG_PREFIX ) ) {
			return;
		}

		$asset_file = CPT_TAXONOMY_SYNCER_PLUGIN_DIR . 'build/js/post-type-relationships-dashboard.asset.php';

		// The dashboard is only served from build/; without it show the build notice.
		if ( ! file_exists( $asset_file ) ) {
			add_action( 'admin_notices', array( $this, 'render_build_notice' ) );
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/post-type-relationships-dashboard.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( self::SCRIPT_HANDLE, CPT_TAXONOMY_SYNCER_PLUGIN_URL . 'build/js/post-type-relationships-dashboard.css', array( 'wp-components' ), $asset['version'] );
		wp_set_script_translations( self::SCRIPT_HANDLE, 'cpt-taxonomy-syncer' );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var cptTaxSyncerPostTypeRelationships = ' . wp_json_encode(
				array(
					'restNamespace' => CPT_Tax_Syncer_REST_Controller::REST_NAMESPACE,
					'perPage'       => 50,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Tells the administrator the JavaScript has not been built.
	 */
	public function render_build_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'CPT Taxonomy Syncer:', 'cpt-taxonomy-syncer' ); ?></strong>
				<?php
				printf(
					/* translators: %s: npm build command */
					esc_html__( 'The relationships dashboard requires a build step. Please run %s in the plugin directory.', 'cpt-taxonomy-syncer' ),
					'<code>npm install && npm run build</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the page the dashboard mounts into.
	 *
	 * @param CPT_Tax_Syncer_Pair $pair The pair this page belongs to.
	 */
	public function render_post_type_relationships_page( CPT_Tax_Syncer_Pair $pair ) {
		$post_type_object = get_post_type_object( $pair->cpt_slug );

		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_posts ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cpt-taxonomy-syncer' ), 403 );
		}
		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %s: Post type name */
					esc_html__( 'Relationships - %s', 'cpt-taxonomy-syncer' ),
					esc_html( $post_type_object->labels->name )
				);
				?>
			</h1>
			<p>
				<?php
				printf(
					/* translators: %1$s: Post type name, %2$s: Taxonomy name */
					esc_html__( 'View posts of type %1$s and their related posts through the %2$s taxonomy.', 'cpt-taxonomy-syncer' ),
					'<strong>' . esc_html( $post_type_object->labels->name ) . '</strong>',
					'<strong>' . esc_html( $pair->taxonomy_slug ) . '</strong>'
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: Meta key name */
					esc_html__( 'Custom order meta key: %s', 'cpt-taxonomy-syncer' ),
					'<code>' . esc_html( $pair->order_meta_key() ) . '</code>'
				);
				?>
			</p>
			<div
				id="cpt-tax-syncer-post-type-relationships-dashboard"
				data-post-type="<?php echo esc_attr( $pair->cpt_slug ); ?>"
				data-taxonomy="<?php echo esc_attr( $pair->taxonomy_slug ); ?>"
			></div>
		</div>
		<?php
	}
}
