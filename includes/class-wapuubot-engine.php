<?php

use WordPress\AI_Client\AI_Client;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AI_Client\Builders\Helpers\Ability_Function_Resolver;

class Wapuubot_Engine {

    public static function process_chat( $message, $history = array(), $context = array() ) {
        if ( empty( $message ) ) {
            throw new Exception( 'Message is empty.' );
        }

        // Ensure we have a user context for permission checks
        if ( ! is_user_logged_in() ) {
            $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
            if ( ! empty( $admins ) ) {
                wp_set_current_user( $admins[0]->ID );
            }
        }

        if ( ! class_exists( AI_Client::class ) ) {
            throw new Exception( 'AI Client library is not installed or initialized.' );
        }

        $builder = AI_Client::prompt( $message );

        $history_messages = array();
        if ( ! empty( $history ) ) {
            foreach ( $history as $msg_data ) {
                try {
                    if ( is_array( $msg_data ) ) {
                        $history_messages[] = Message::fromArray( $msg_data );
                    } elseif ( $msg_data instanceof Message ) {
                        $history_messages[] = $msg_data;
                    }
                } catch ( Exception $e ) {
                    continue;
                }
            }
        }
        if ( ! empty( $history_messages ) ) {
            $builder->with_history( ...$history_messages );
        }

        // Ensure abilities are registered
        if ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
            if ( function_exists( 'wapuubot_register_categories' ) ) {
                wapuubot_register_categories();
            }
            do_action( 'wp_abilities_api_categories_init' );
        }
        if ( ! did_action( 'wp_abilities_api_init' ) ) {
            if ( function_exists( 'wapuubot_register_all_abilities' ) ) {
                wapuubot_register_all_abilities();
            }
            do_action( 'wp_abilities_api_init' );
        }

        $abilities = wp_get_abilities();
        $allowed_abilities = array();
        $ability_names_for_log = array();
        foreach ( $abilities as $ability ) {
            // Check if the current user has permission to use this ability
            $can = $ability->check_permissions();
            
            $ability_names_for_log[] = $ability->get_name() . ' (' . ( $can === true ? 'allowed' : 'denied' ) . ')';
            if ( $can === true ) {
                $allowed_abilities[] = $ability;
            }
        }
        error_log( 'Wapuubot Engine Debug: Abilities in registry: ' . implode( ', ', $ability_names_for_log ) );

        $system_instruction = 'You are Wapuubot, a powerful WordPress assistant. ' .
            'You HAVE ACCESS to tools (abilities) that allow you to manage the site. ';
        
        if ( ! empty( $allowed_abilities ) ) {
            $system_instruction .= 'Your available tools are: ' . implode( ', ', array_map( function( $a ) { return $a->get_name(); }, $allowed_abilities ) ) . '. ';
        }

        $system_instruction .= 'NEVER say you cannot create or edit posts; instead, always check your available tools and use them. ';

        if ( ! empty( $context['postId'] ) ) {
            $system_instruction .= 'The user is currently viewing/editing post ID ' . $context['postId'] . '. ';
        }

        $system_instruction .= "Use your abilities to fulfill the user's request. " .
            "For example, use 'wapuubot/create-post' to create a new post. " .
            "If you need a post ID but don't have it, use the 'wapuubot/search-posts' ability to find it by title. " .
            "IMPORTANT: When you call a function, the result will be provided to you in the next turn. Use that result to confirm the action to the user.";

        $builder->using_system_instruction( $system_instruction );

        if ( ! empty( $allowed_abilities ) ) {
            $builder->using_abilities( ...$allowed_abilities );
        }

        $current_turn_history = $history_messages;
        $current_turn_history[] = new Message( MessageRoleEnum::user(), array( new MessagePart( $message ) ) );

        $result = $builder->generate_result();
        $message_obj = $result->toMessage();

        $max_turns = 5;
        $turns = 0;
        $action_performed = false;

        while ( Ability_Function_Resolver::has_ability_calls( $message_obj ) && $turns < $max_turns ) {
            $turns++;
            
            $current_turn_history[] = $message_obj;
            
            $response_messages = array();
            foreach ( $message_obj->getParts() as $part ) {
                if ( $part->getFunctionCall() ) {
                    $call = $part->getFunctionCall();
                    if ( Ability_Function_Resolver::is_ability_call( $call ) ) {
                        $function_response = Ability_Function_Resolver::execute_ability( $call );
                        $response_messages[] = new Message( 
                            MessageRoleEnum::user(), 
                            array( new MessagePart( $function_response ) ) 
                        );
                    }
                }
            }
            
            if ( empty( $response_messages ) ) {
                break;
            }

            $action_performed = true;

            foreach ( $response_messages as $msg ) {
                $current_turn_history[] = $msg;
            }
            
            $last_response_message = array_pop( $current_turn_history );
            
            $builder = AI_Client::prompt( $last_response_message );
            $builder->using_system_instruction( $system_instruction );
            if ( ! empty( $allowed_abilities ) ) {
                $builder->using_abilities( ...$allowed_abilities );
            }
            
            $builder->with_history( ...$current_turn_history );
            
            $result = $builder->generate_result();
            $message_obj = $result->toMessage();
            
            $current_turn_history[] = $last_response_message;
        }

        return array(
            'response'         => $result->toText(),
            'action_performed' => $action_performed,
            'history'          => $current_turn_history, // Return updated history if needed
        );
    }
}
