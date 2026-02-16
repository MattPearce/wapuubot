<?php
/**
 * Wapuubot Post Abilities
 *
 * @package Wapuubot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register post-related abilities.
 */
function wapuubot_register_post_abilities() {
    // Create Post Ability
    wp_register_ability( 'wapuubot/create-post', array(
        'label'       => 'Create Post',
        'description' => 'Creates a new draft post in WordPress.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_create_post_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'title' => array( 'type' => 'string', 'description' => 'The title of the post' ),
                'content' => array( 'type' => 'string', 'description' => 'The content of the post' ),
            ),
            'required' => array( 'title', 'content' ),
        ),
    ) );

    // Edit Post Ability
    wp_register_ability( 'wapuubot/edit-post', array(
        'label'       => 'Edit Post',
        'description' => 'Updates an existing WordPress post (title, content, or status).',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_edit_post_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'The ID of the post to edit' ),
                'title'   => array( 'type' => 'string', 'description' => 'New title (optional)' ),
                'content' => array( 'type' => 'string', 'description' => 'New content (optional)' ),
                'status'  => array( 'type' => 'string', 'description' => 'New status (optional)' ),
            ),
            'required' => array( 'post_id' ),
        ),
    ) );

    // Add Tags Ability
    wp_register_ability( 'wapuubot/add-tags', array(
        'label'       => 'Add Tags',
        'description' => 'Adds tags to a WordPress post.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_add_tags_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'The ID of the post' ),
                'tags'    => array( 'type' => 'string', 'description' => 'Comma-separated list of tags to add' ),
            ),
            'required' => array( 'post_id', 'tags' ),
        ),
    ) );

    // Get Post Content Ability
    wp_register_ability( 'wapuubot/get-post-content', array(
        'label'       => 'Get Post Content',
        'description' => 'Retrieves details and content of a WordPress post by ID.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_get_post_content_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'The ID of the post to retrieve' ),
            ),
            'required' => array( 'post_id' ),
        ),
    ) );

    // Search Posts Ability
    wp_register_ability( 'wapuubot/search-posts', array(
        'label'       => 'Search Posts',
        'description' => 'Searches for posts by title and returns their IDs.',
        'category'    => 'wapuubot',
        'execute_callback' => 'wapuubot_search_posts_ability',
        'permission_callback' => function() { return current_user_can('edit_posts'); },
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'search' => array( 'type' => 'string', 'description' => 'The search term (title)' ),
            ),
            'required' => array( 'search' ),
        ),
    ) );
}
add_action( 'wp_abilities_api_init', 'wapuubot_register_post_abilities' );

// Callbacks
function wapuubot_create_post_ability( $args ) {
    $post_id = wp_insert_post( array(
        'post_title'   => $args['title'],
        'post_content' => $args['content'],
        'post_status'  => 'draft',
    ) );

    if ( is_wp_error( $post_id ) ) {
        return 'Error creating post: ' . $post_id->get_error_message();
    }
    return 'Successfully created draft post with ID: ' . $post_id;
}

function wapuubot_edit_post_ability( $args ) {
    $post_args = array( 'ID' => $args['post_id'] );
    if ( ! empty( $args['title'] ) ) $post_args['post_title'] = $args['title'];
    if ( ! empty( $args['content'] ) ) $post_args['post_content'] = $args['content'];
    if ( ! empty( $args['status'] ) ) $post_args['post_status'] = $args['status'];

    $result = wp_update_post( $post_args, true );
    if ( is_wp_error( $result ) ) {
        return 'Error updating post: ' . $result->get_error_message();
    }
    return 'Successfully updated post ' . $args['post_id'];
}

function wapuubot_add_tags_ability( $args ) {
    $tags = is_string( $args['tags'] ) ? explode( ',', $args['tags'] ) : $args['tags'];
    $result = wp_set_post_tags( $args['post_id'], $tags, true );
    if ( is_wp_error( $result ) ) {
        return 'Error adding tags: ' . $result->get_error_message();
    }
    return 'Successfully added tags to post ' . $args['post_id'];
}

function wapuubot_get_post_content_ability( $args ) {
    $post = get_post( $args['post_id'] );
    if ( ! $post ) {
        return 'Post not found.';
    }
    return json_encode( array(
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'status'  => $post->post_status,
        'link'    => get_permalink( $post->ID ),
    ) );
}

function wapuubot_search_posts_ability( $args ) {
    $query = new WP_Query( array(
        's'              => $args['search'],
        'posts_per_page' => 5,
        'post_type'      => 'any',
        'post_status'    => 'any',
    ) );

    $results = array();
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $results[] = array(
                'id'    => get_the_ID(),
                'title' => get_the_title(),
                'type'  => get_post_type(),
            );
        }
    }
    wp_reset_postdata();
    return ! empty( $results ) ? json_encode( $results ) : "No posts found for that search term.";
}
