<?php
/**
 * Wapuubot Category Abilities
 *
 * @package Wapuubot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register category-related abilities.
 */
function wapuubot_register_category_abilities() {
    // Get Categories Ability
    wp_register_ability( 'wapuubot/get-categories', array(
        'label'       => 'Get Categories',
        'description' => 'Retrieves a list of all post categories.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_get_categories_ability',
        'permission_callback' => function() { return current_user_can('manage_categories'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => new stdClass(),
        ),
    ) );

    // Create Category Ability
    wp_register_ability( 'wapuubot/create-category', array(
        'label'       => 'Create Category',
        'description' => 'Creates a new post category.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_create_category_ability',
        'permission_callback' => function() { return current_user_can('manage_categories'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'name' => array( 'type' => 'string', 'description' => 'The name of the new category' ),
            ),
            'required' => array( 'name' ),
        ),
    ) );

    // Delete Category Ability
    wp_register_ability( 'wapuubot/delete-category', array(
        'label'       => 'Delete Category',
        'description' => 'Deletes a post category by ID.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_delete_category_ability',
        'permission_callback' => function() { return current_user_can('manage_categories'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'category_id' => array( 'type' => 'integer', 'description' => 'The ID of the category to delete' ),
            ),
            'required' => array( 'category_id' ),
        ),
    ) );

    // Assign Category Ability
    wp_register_ability( 'wapuubot/assign-category', array(
        'label'       => 'Assign Category',
        'description' => 'Assigns a category to a post.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_assign_category_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id'     => array( 'type' => 'integer', 'description' => 'The ID of the post' ),
                'category_id' => array( 'type' => 'integer', 'description' => 'The ID of the category to assign' ),
            ),
            'required' => array( 'post_id', 'category_id' ),
        ),
    ) );
}
add_action( 'wp_abilities_api_init', 'wapuubot_register_category_abilities' );

// Callbacks
function wapuubot_get_categories_ability( $args ) {
    $categories = get_categories( array( 'hide_empty' => false ) );
    $list = array();
    foreach ( $categories as $cat ) {
        $list[] = array( 'id' => $cat->term_id, 'name' => $cat->name );
    }
    return json_encode( $list );
}

function wapuubot_create_category_ability( $args ) {
    if ( ! function_exists( 'wp_create_category' ) ) {
        require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
    }
    
    $id = wp_create_category( $args['name'] );
    if ( is_wp_error( $id ) ) {
        return "Error creating category: " . $id->get_error_message();
    }
    return "Successfully created category '" . $args['name'] . "' with ID: " . $id;
}

function wapuubot_delete_category_ability( $args ) {
    if ( ! function_exists( 'wp_delete_category' ) ) {
        require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
    }

    $result = wp_delete_category( $args['category_id'] );
    if ( is_wp_error( $result ) ) {
        return "Error deleting category: " . $result->get_error_message();
    }
    if ( $result === false ) {
        return "Error deleting category: Category not found or could not be deleted.";
    }
    return "Successfully deleted category ID " . $args['category_id'];
}

function wapuubot_assign_category_ability( $args ) {
    $result = wp_set_post_categories( $args['post_id'], array( $args['category_id'] ), true );
    if ( is_wp_error( $result ) ) {
        return "Error assigning category: " . $result->get_error_message();
    }
    return "Successfully assigned category ID " . $args['category_id'] . " to post " . $args['post_id'];
}
