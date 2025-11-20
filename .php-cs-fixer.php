<?php

/**
 * PHP CS Fixer configuration for WordPress coding standards
 *
 * @see https://github.com/FriendsOfPHP/PHP-CS-Fixer
 */

$finder = PhpCsFixer\Finder::create()
	->in( __DIR__ )
	->exclude( 'vendor' )
	->exclude( 'node_modules' )
	->exclude( 'build' )
	->name( '*.php' );

$config = new PhpCsFixer\Config();
return $config
	->setRules( array(
		'@PSR12' => true,
		'array_syntax' => array( 'syntax' => 'short' ),
		'braces' => array(
			'allow_single_line_closure' => true,
		),
		'cast_spaces' => true,
		'class_attributes_separation' => array(
			'elements' => array(
				'method' => 'one',
			),
		),
		'concat_space' => array(
			'spacing' => 'none',
		),
		'declare_equal_normalize' => true,
		'function_typehint_space' => true,
		'hash_to_slash_comment' => true,
		'include' => true,
		'lowercase_cast' => true,
		'no_blank_lines_after_class_opening' => false,
		'no_blank_lines_after_phpdoc' => false,
		'no_empty_statement' => true,
		'no_extra_blank_lines' => array(
			'tokens' => array(
				'curly_brace_block',
				'extra',
				'parenthesis_brace_block',
				'square_brace_block',
				'throw',
				'use',
			),
		),
		'no_leading_namespace_whitespace' => true,
		'no_short_bool_cast' => true,
		'no_singleline_whitespace_before_semicolons' => true,
		'no_spaces_around_offset' => true,
		'no_trailing_comma_in_list_call' => true,
		'no_trailing_comma_in_singleline_array' => true,
		'no_unneeded_control_parentheses' => true,
		'no_unused_imports' => true,
		'no_whitespace_before_comma_in_array' => true,
		'no_whitespace_in_blank_line' => true,
		'object_operator_without_whitespace' => true,
		'ordered_imports' => true,
		'phpdoc_indent' => true,
		'phpdoc_inline_tag' => true,
		'phpdoc_no_access' => true,
		'phpdoc_no_alias_tag' => true,
		'phpdoc_no_empty_return' => true,
		'phpdoc_no_package' => false,
		'phpdoc_scalar' => true,
		'phpdoc_single_line_var_spacing' => true,
		'phpdoc_summary' => true,
		'phpdoc_to_comment' => true,
		'phpdoc_trim' => true,
		'phpdoc_types' => true,
		'phpdoc_var_without_name' => true,
		'self_accessor' => true,
		'short_scalar_cast' => true,
		'single_blank_line_before_namespace' => true,
		'single_quote' => true,
		'space_after_semicolon' => true,
		'standardize_not_equals' => true,
		'trailing_comma_in_multiline' => true,
		'trim_array_spaces' => true,
		'unary_operator_spaces' => true,
		'whitespace_after_comma_in_array' => true,
	) )
	->setFinder( $finder );

