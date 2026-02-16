<?php
/**
 * Plugin Name: Wapuubot
 * Description: A Wapuu chat bubble to perform actions on your site using the WordPress AI Client.
 * Version: 1.0.0
 * Author: Matt Pearce
 * License: GPLv2 or later
 * Text Domain: wapuubot
 */

use WordPress\AI_Client\AI_Client;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AI_Client\Builders\Helpers\Ability_Function_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wapuubot_init() {
    load_plugin_textdomain( 'wapuubot', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

	if ( class_exists( AI_Client::class ) ) {
		AI_Client::init();
	}
    
    // Manually bootstrap Abilities API if not active as plugin
    if ( ! defined( 'WP_ABILITIES_API_VERSION' ) && file_exists( plugin_dir_path( __FILE__ ) . 'vendor/wordpress/abilities-api/abilities-api.php' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'vendor/wordpress/abilities-api/abilities-api.php';
    }

    // Load Abilities
    require_once plugin_dir_path( __FILE__ ) . 'abilities/posts.php';
    require_once plugin_dir_path( __FILE__ ) . 'abilities/categories.php';
}
add_action( 'init', 'wapuubot_init' );

function wapuubot_enqueue_scripts() {
    wp_enqueue_style( 'wapuubot-css', plugin_dir_url( __FILE__ ) . 'assets/css/wapuubot.css', array(), '1.0.2' );
    wp_enqueue_script( 'wapuubot-js', plugin_dir_url( __FILE__ ) . 'assets/js/wapuubot.js', array(), '1.0.2', true );

    wp_localize_script( 'wapuubot-js', 'wapuubotData', array(
        'rest_url' => esc_url_raw( rest_url( 'wapuubot/v1/chat' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'wapuubot_enqueue_scripts' );

function wapuubot_register_rest_routes() {
    register_rest_route( 'wapuubot/v1', '/chat', array(
        'methods'             => 'POST',
        'callback'            => 'wapuubot_handle_chat',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
    ) );
}
add_action( 'rest_api_init', 'wapuubot_register_rest_routes' );

// Ability Registration is now handled in abilities/posts.php and abilities/categories.php

function wapuubot_handle_chat( $request ) {
	$params = $request->get_json_params();
	$message = isset( $params['message'] ) ? sanitize_text_field( $params['message'] ) : '';
	$context = isset( $params['context'] ) ? $params['context'] : array();
	$history = isset( $params['history'] ) ? $params['history'] : array();

	if ( empty( $message ) ) {
		return new WP_REST_Response( array( 'response' => 'I didn\'t catch that. Could you repeat it?' ), 400 );
	}

	if ( ! class_exists( AI_Client::class ) ) {
		return new WP_REST_Response( array( 'response' => 'AI Client library is not installed or initialized.' ), 500 );
	}

	try {
		$builder = AI_Client::prompt( $message );

		if ( ! empty( $history ) ) {
			$history_messages = array();
			foreach ( $history as $msg_data ) {
				try {
					$history_messages[] = Message::fromArray( $msg_data );
				} catch ( Exception $e ) {
					continue;
				}
			}
			if ( ! empty( $history_messages ) ) {
				$builder->with_history( ...$history_messages );
			}
		}

		$system_instruction = 'You are Wapuubot, a helpful WordPress assistant. ' .
			'You can manage the site by calling functions (abilities). ';

		if ( ! empty( $context['postId'] ) ) {
			$system_instruction .= 'The user is currently viewing/editing post ID ' . $context['postId'] . '. ';
		}

		$system_instruction .= "Use available abilities to fulfill the user's request. " .
            "If you need a post ID but don't have it, use the 'search-posts' ability to find it by title. " .
			"IMPORTANT: When you call a function, I will return the result to you. Use that result to answer the user's question.";

		$builder->using_system_instruction( $system_instruction );

        // Automatically discover and use ALL registered abilities!
        $abilities = wp_get_abilities();
        
        // Debug: Log discovered abilities
        $ability_names = array_map( function( $a ) { return $a->get_name(); }, $abilities );
        error_log( 'Wapuubot: Discovered abilities: ' . implode( ', ', $ability_names ) );

        if ( ! empty( $abilities ) ) {
            $builder->using_abilities( ...$abilities );
        }

        // Initialize history tracking for this session
        $current_turn_history = array();
        if ( ! empty( $history_messages ) ) {
            $current_turn_history = array_merge( $current_turn_history, $history_messages );
        }
        // Add current user prompt to history tracking
        $current_turn_history[] = new Message( MessageRoleEnum::user(), array( new MessagePart( $message ) ) );

		error_log( 'Wapuubot: generating initial result...' );
		$result = $builder->generate_result();
		$message_obj = $result->toMessage();

        $max_turns = 5; // Prevent infinite loops
        $turns = 0;
        $action_performed = false;

        // Loop as long as the model wants to call tools
		while ( Ability_Function_Resolver::has_ability_calls( $message_obj ) && $turns < $max_turns ) {
            $turns++;
            error_log( "Wapuubot: Turn $turns - Detected ability calls. Executing..." );
            
            // Add the model's call message to history
            $current_turn_history[] = $message_obj;
            
            // Execute abilities manually to handle multiple calls as separate messages
            $response_messages = array();
            foreach ( $message_obj->getParts() as $part ) {
                if ( $part->getFunctionCall() ) {
                    $call = $part->getFunctionCall();
                    if ( Ability_Function_Resolver::is_ability_call( $call ) ) {
                        $function_response = Ability_Function_Resolver::execute_ability( $call );
                        // Create a separate message for EACH function response
                        $response_messages[] = new Message( 
                            MessageRoleEnum::user(), 
                            array( new MessagePart( $function_response ) ) 
                        );
                    }
                }
            }
            
            if ( empty( $response_messages ) ) {
                // Should not happen if has_ability_calls was true
                break;
            }

            $action_performed = true;

            // Add all response messages to history
            foreach ( $response_messages as $msg ) {
                $current_turn_history[] = $msg;
            }
            
            // Use the LAST response message as the prompt for the next turn
            $last_response_message = array_pop( $current_turn_history );
            
            $builder = AI_Client::prompt( $last_response_message );
            $builder->using_system_instruction( $system_instruction );
            if ( ! empty( $abilities ) ) {
                $builder->using_abilities( ...$abilities );
            }
            
            $builder->with_history( ...$current_turn_history );
            
            $result = $builder->generate_result();
            $message_obj = $result->toMessage();
            
            // Add the last response back to history for the next iteration (if any)
            $current_turn_history[] = $last_response_message;
		}

        $response_text = $result->toText();

		return new WP_REST_Response(
			array(
				'response'         => $response_text,
				'action_performed' => $action_performed,
			),
			200
		);

	} catch ( Exception $e ) {
		return new WP_REST_Response(
			array(
				'response' => 'I encountered an error: ' . $e->getMessage(),
			),
			500
		);
	}
}

function wapuubot_footer_html() {
	?>
	<div id="wapuubot-container">
		<div id="wapuubot-chat-window">
			<div id="wapuubot-header">
				<span>Wapuubot</span>
                <div id="wapuubot-header-actions">
				    <span id="wapuubot-close">&times;</span>
                </div>
			</div>
			<div id="wapuubot-messages">
				<div class="wapuubot-message bot">Hi! I'm Wapuubot. How can I help you today?</div>
			</div>
			<div id="wapuubot-input-area">
				<input type="text" id="wapuubot-input" placeholder="Type a message..." />
				<button id="wapuubot-send">Send</button>
			</div>
		</div>
		<div id="wapuubot-bubble">
			<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/images/wapuu.svg' ); ?>" alt="Wapuu" />
		</div>
	</div>
	<?php
}

add_action( 'admin_footer', 'wapuubot_footer_html' );