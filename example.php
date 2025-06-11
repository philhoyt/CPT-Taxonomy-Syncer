<?php
/**
 * Example usage class - instantiates the syncer with specific CPT and taxonomy
 */
class CPT_Taxonomy_Syncer_Init {
	/**
	 * Instance of the syncer
	 * 
	 * @var CPT_Taxonomy_Syncer
	 */
	private $syncer;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action('init', array($this, 'init'));
	}
	
	/**
	 * Initialize the syncer
	 */
	public function init() {
		// Create an instance of the syncer with your CPT and taxonomy
		// Replace 'your_cpt' and 'your_taxonomy' with your actual CPT and taxonomy slugs
		$this->syncer = new CPT_Taxonomy_Syncer('your_cpt', 'your_taxonomy');
	}
}

// Initialize the plugin
new CPT_Taxonomy_Syncer_Init();