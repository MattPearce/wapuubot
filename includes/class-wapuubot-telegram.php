<?php

class Wapuubot_Telegram {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function get_bot_username() {
        $token = get_option( 'wapuubot_telegram_token' );
        if ( ! $token ) {
            return false;
        }

        $username = get_transient( 'wapuubot_tg_bot_username' );
        if ( $username ) {
            return $username;
        }

        $response = wp_remote_get( "https://api.telegram.org/bot$token/getMe" );
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['result']['username'] ) ) {
            $username = $body['result']['username'];
            set_transient( 'wapuubot_tg_bot_username', $username, DAY_IN_SECONDS );
            return $username;
        }

        return false;
    }

    public static function register_routes() {
        register_rest_route( 'wapuubot/v1', '/telegram-webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle_webhook( $request ) {
        $update = $request->get_json_params();

        if ( ! isset( $update['message'] ) ) {
            return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
        }

        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text    = isset( $message['text'] ) ? $message['text'] : '';
        $from    = isset( $message['from'] ) ? $message['from'] : array();

        if ( empty( $text ) ) {
            return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
        }

        // Handle /start command
        if ( strpos( $text, '/start' ) === 0 ) {
            self::send_message( $chat_id, "Hi! I'm Wapuubot, your WordPress assistant. How can I help you today?" );
            return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
        }

        // Check if user is allowed
        if ( ! self::is_user_allowed( $from ) ) {
            $is_pairing = get_option( 'wapuubot_telegram_pairing_mode' );
            
            if ( $is_pairing ) {
                // Auto-authorize
                $allowed_users = get_option( 'wapuubot_telegram_allowed_users', '' );
                $allowed_array = array_filter( array_map( 'trim', explode( ',', $allowed_users ) ) );
                $user_identifier = isset( $from['username'] ) ? '@' . $from['username'] : (string) $from['id'];
                
                if ( ! in_array( $user_identifier, $allowed_array ) ) {
                    $allowed_array[] = $user_identifier;
                    update_option( 'wapuubot_telegram_allowed_users', implode( ', ', $allowed_array ) );
                }
            } else {
                // Log as pending
                $pending = get_option( 'wapuubot_pending_connections', array() );
                $user_id = (string) $from['id'];
                if ( ! isset( $pending[$user_id] ) ) {
                    $pending[$user_id] = array(
                        'username'   => isset( $from['username'] ) ? $from['username'] : '',
                        'first_name' => isset( $from['first_name'] ) ? $from['first_name'] : '',
                        'last_name'  => isset( $from['last_name'] ) ? $from['last_name'] : '',
                        'timestamp'  => time(),
                    );
                    update_option( 'wapuubot_pending_connections', $pending );
                }

                self::send_message( $chat_id, "Hi! I'm Wapuubot. You are not authorized to use me yet. Please ask your administrator to approve your connection (ID: $user_id)." );
                return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
            }
        }

        // Set current user to an admin so abilities can be executed
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
        if ( ! empty( $admins ) ) {
            wp_set_current_user( $admins[0]->ID );
        }

        // Get history for this chat
        $history_key = 'wapuubot_tg_history_' . $chat_id;
        $history = get_transient( $history_key );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        try {
            // Process the chat
            $result = Wapuubot_Engine::process_chat( $text, $history );

            // Send response back to Telegram
            self::send_message( $chat_id, $result['response'] );

            // Update history (simplified for now, just keep last 10 messages)
            // Note: process_chat returns full history including AI Client objects, 
            // but we need to store them in a way that can be re-instantiated.
            // Wapuubot_Engine::process_chat already returns history as Message objects if it can.
            
            // To store in transient, we should probably convert to array
            $new_history = array();
            foreach ( $result['history'] as $msg ) {
                if ( method_exists( $msg, 'toArray' ) ) {
                    $new_history[] = $msg->toArray();
                }
            }
            
            // Limit history
            if ( count( $new_history ) > 20 ) {
                $new_history = array_slice( $new_history, -20 );
            }
            
            set_transient( $history_key, $new_history, HOUR_IN_SECONDS );

        } catch ( Exception $e ) {
            self::send_message( $chat_id, "Error: " . $e->getMessage() );
        }

        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    private static function is_user_allowed( $from ) {
        $allowed_users_str = get_option( 'wapuubot_telegram_allowed_users' );
        if ( empty( $allowed_users_str ) ) {
            return true; // If empty, allow all (risky but user's choice if they left it empty)
        }

        $allowed_users = array_map( 'trim', explode( ',', $allowed_users_str ) );
        $username = isset( $from['username'] ) ? '@' . $from['username'] : '';
        $user_id  = isset( $from['id'] ) ? (string) $from['id'] : '';

        foreach ( $allowed_users as $allowed ) {
            if ( $allowed === $username || $allowed === $user_id ) {
                return true;
            }
        }

        return false;
    }

    private static function send_message( $chat_id, $text ) {
        $token = get_option( 'wapuubot_telegram_token' );
        if ( ! $token ) {
            return;
        }

        $url = "https://api.telegram.org/bot$token/sendMessage";
        
        // Telegram has a 4096 character limit per message
        if ( strlen( $text ) > 4000 ) {
            $parts = str_split( $text, 4000 );
            foreach ( $parts as $part ) {
                wp_remote_post( $url, array(
                    'body' => array(
                        'chat_id' => $chat_id,
                        'text'    => $part,
                    ),
                ) );
            }
        } else {
            wp_remote_post( $url, array(
                'body' => array(
                    'chat_id' => $chat_id,
                    'text'    => $text,
                ),
            ) );
        }
    }
}
