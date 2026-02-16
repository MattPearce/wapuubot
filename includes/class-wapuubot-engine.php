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

        // Ensure abilities are registered
        if ( ! did_action( 'wp_abilities_api_init' ) ) {
            do_action( 'wp_abilities_api_init' );
        }

        $abilities = wp_get_abilities();

        if ( ! empty( $abilities ) ) {
            $builder->using_abilities( ...$abilities );
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
            if ( ! empty( $abilities ) ) {
                $builder->using_abilities( ...$abilities );
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
