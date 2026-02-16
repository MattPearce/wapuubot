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
    
    // Ensure abilities are registered if the hook hasn't fired yet
    if ( did_action( 'init' ) ) {
        do_action( 'wp_abilities_api_init' );
    }

    // Load Engine
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wapuubot-engine.php';

    // Load Telegram Bridge
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wapuubot-telegram.php';
    Wapuubot_Telegram::init();
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

function wapuubot_admin_menu() {
    add_options_page(
        'Wapuubot Settings',
        'Wapuubot',
        'manage_options',
        'wapuubot',
        'wapuubot_settings_page'
    );
}
add_action( 'admin_menu', 'wapuubot_admin_menu' );

function wapuubot_register_settings() {
    register_setting( 'wapuubot_settings', 'wapuubot_telegram_token' );
    register_setting( 'wapuubot_settings', 'wapuubot_telegram_allowed_users' );
    register_setting( 'wapuubot_settings', 'wapuubot_telegram_pairing_mode' );

    add_settings_section(
        'wapuubot_telegram_section',
        'Telegram Bridge Settings',
        null,
        'wapuubot'
    );

    add_settings_field(
        'wapuubot_telegram_token',
        'Telegram Bot Token',
        'wapuubot_telegram_token_callback',
        'wapuubot',
        'wapuubot_telegram_section'
    );

    add_settings_field(
        'wapuubot_telegram_pairing_mode',
        'Pairing Mode',
        'wapuubot_telegram_pairing_mode_callback',
        'wapuubot',
        'wapuubot_telegram_section'
    );

    add_settings_field(
        'wapuubot_telegram_allowed_users',
        'Allowed Telegram Users',
        'wapuubot_telegram_allowed_users_callback',
        'wapuubot',
        'wapuubot_telegram_section'
    );
}
add_action( 'admin_init', 'wapuubot_register_settings' );

function wapuubot_telegram_token_callback() {
    $token = get_option( 'wapuubot_telegram_token' );
    echo '<input type="text" name="wapuubot_telegram_token" value="' . esc_attr( $token ) . '" class="regular-text" />';
    echo '<p class="description">Enter your Telegram Bot Token from @BotFather.</p>';
}

function wapuubot_telegram_pairing_mode_callback() {
    $pairing = get_option( 'wapuubot_telegram_pairing_mode' );
    echo '<label><input type="checkbox" name="wapuubot_telegram_pairing_mode" value="1" ' . checked( 1, $pairing, false ) . ' /> Enable Pairing Mode</label>';
    echo '<p class="description">When enabled, new users who message the bot will be automatically authorized.</p>';
}

function wapuubot_telegram_allowed_users_callback() {
    $users = get_option( 'wapuubot_telegram_allowed_users' );
    echo '<input type="text" name="wapuubot_telegram_allowed_users" value="' . esc_attr( $users ) . '" class="regular-text" />';
    echo '<p class="description">Comma-separated list of Telegram usernames (@user) or IDs allowed. You can manage these in the Connections panel below.</p>';
}

function wapuubot_settings_page() {
    // Handle actions
    if ( isset( $_GET['action'] ) && isset( $_GET['user_id'] ) && check_admin_referer( 'wapuubot_connection_action' ) ) {
        $user_id = sanitize_text_field( $_GET['user_id'] );
        $pending = get_option( 'wapuubot_pending_connections', array() );
        
        if ( $_GET['action'] === 'approve' ) {
            $allowed_users = get_option( 'wapuubot_telegram_allowed_users', '' );
            $allowed_array = array_filter( array_map( 'trim', explode( ',', $allowed_users ) ) );
            if ( ! in_array( $user_id, $allowed_array ) ) {
                $allowed_array[] = $user_id;
                update_option( 'wapuubot_telegram_allowed_users', implode( ', ', $allowed_array ) );
            }
            unset( $pending[$user_id] );
        } elseif ( $_GET['action'] === 'ignore' ) {
            unset( $pending[$user_id] );
        }
        update_option( 'wapuubot_pending_connections', $pending );
        wp_redirect( admin_url( 'options-general.php?page=wapuubot&settings-updated=true' ) );
        exit;
    }

    ?>
    <div class="wrap">
        <h1>Wapuubot Settings</h1>
        
        <div class="welcome-panel" style="padding: 20px; display: flex; align-items: center; gap: 30px;">
            <div class="welcome-panel-content">
                <h2>Connect to Wapuubot</h2>
                <p>Scan the QR code or click the link to start chatting with your bot on Telegram.</p>
                <?php
                $token = get_option( 'wapuubot_telegram_token' );
                if ( $token ) {
                    $bot_username = Wapuubot_Telegram::get_bot_username();
                    if ( $bot_username ) {
                        $bot_url = 'https://t.me/' . $bot_username;
                        echo '<p><a href="' . esc_url( $bot_url ) . '" class="button button-primary" target="_blank">Open @' . esc_html( $bot_username ) . '</a></p>';
                        echo '<p><small>Username: @' . esc_html( $bot_username ) . '</small></p>';
                    } else {
                        echo '<p><strong>1.</strong> Find your bot on Telegram.</p>';
                        echo '<p><strong>2.</strong> Send <code>/start</code>.</p>';
                        echo '<p><strong>3.</strong> Approve the connection in the panel below.</p>';
                    }
                }
                ?>
            </div>
            <?php if ( $token && isset($bot_url) ) : ?>
                <div style="text-align: center;">
                    <img src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $bot_url ) ); ?>" alt="Bot QR Code" style="border: 1px solid #ccc; padding: 5px; background: #fff;" />
                </div>
            <?php elseif ( $token ) : ?>
                <div style="text-align: center;">
                    <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/images/wapuu.svg' ); ?>" style="width: 100px; height: auto;" />
                </div>
            <?php endif; ?>
        </div>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'wapuubot_settings' );
            do_settings_sections( 'wapuubot' );
            submit_button();
            ?>
        </form>

        <hr />

        <h2>Connections</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pending = get_option( 'wapuubot_pending_connections', array() );
                $allowed_users = get_option( 'wapuubot_telegram_allowed_users', '' );
                $allowed_array = array_filter( array_map( 'trim', explode( ',', $allowed_users ) ) );

                if ( empty( $pending ) && empty( $allowed_array ) ) {
                    echo '<tr><td colspan="3">No connection attempts yet.</td></tr>';
                } else {
                    // Show Pending
                    foreach ( $pending as $id => $data ) {
                        $name = isset( $data['first_name'] ) ? $data['first_name'] : '';
                        $username = isset( $data['username'] ) ? ' (@' . $data['username'] . ')' : '';
                        echo '<tr>';
                        echo '<td><strong>' . esc_html( $name . $username ) . '</strong><br/><small>ID: ' . esc_html( $id ) . '</small></td>';
                        echo '<td><span class="status-waiting" style="color: #d63638;">Pending</span></td>';
                        echo '<td>';
                        $approve_url = wp_nonce_url( admin_url( 'options-general.php?page=wapuubot&action=approve&user_id=' . $id ), 'wapuubot_connection_action' );
                        $ignore_url = wp_nonce_url( admin_url( 'options-general.php?page=wapuubot&action=ignore&user_id=' . $id ), 'wapuubot_connection_action' );
                        echo '<a href="' . esc_url( $approve_url ) . '" class="button button-primary">Approve</a> ';
                        echo '<a href="' . esc_url( $ignore_url ) . '" class="button">Ignore</a>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    // Show Approved (Simplified)
                    foreach ( $allowed_array as $user ) {
                        echo '<tr>';
                        echo '<td><strong>' . esc_html( $user ) . '</strong></td>';
                        echo '<td><span class="status-approved" style="color: #46b450;">Authorized</span></td>';
                        echo '<td><small>To remove, edit the Allowed Users field above.</small></td>';
                        echo '</tr>';
                    }
                }
                ?>
            </tbody>
        </table>

        <hr />
        <h2>Webhook Status</h2>
        <?php
        if ( $token ) {
            $webhook_url = rest_url( 'wapuubot/v1/telegram-webhook' );
            echo '<p>Your Webhook URL is: <code>' . esc_url( $webhook_url ) . '</code></p>';
            echo '<p><a href="' . esc_url( 'https://api.telegram.org/bot' . $token . '/setWebhook?url=' . $webhook_url ) . '" class="button" target="_blank">Set Webhook</a></p>';
        } else {
            echo '<p>Please enter a bot token first.</p>';
        }
        ?>
    </div>
    <?php
}

// Ability Registration is now handled in abilities/posts.php and abilities/categories.php

function wapuubot_handle_chat( $request ) {
	$params = $request->get_json_params();
	$message = isset( $params['message'] ) ? sanitize_text_field( $params['message'] ) : '';
	$context = isset( $params['context'] ) ? $params['context'] : array();
	$history = isset( $params['history'] ) ? $params['history'] : array();

	if ( empty( $message ) ) {
		return new WP_REST_Response( array( 'response' => 'I didn\'t catch that. Could you repeat it?' ), 400 );
	}

	try {
        $result = Wapuubot_Engine::process_chat( $message, $history, $context );

		return new WP_REST_Response(
			array(
				'response'         => $result['response'],
				'action_performed' => $result['action_performed'],
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