=== Wapuubot ===
Contributors: mattpearce
Tags: ai, chatbot, assistant, wapuu, administration
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A smart AI assistant that lives in your WordPress dashboard, helping you manage posts and categories via chat.

== Description ==

Wapuubot is an AI-powered assistant integrated directly into your WordPress Admin. Click the Wapuu bubble to open a chat window where you can ask for help with site management tasks.

**Key Features:**

*   **Context Aware:** Wapuubot knows which post you are editing and can help you specifically with that content.
*   **Post Management:** Create, edit, and update posts using natural language commands.
*   **Taxonomy Management:** Create, delete, and assign categories instantly.
*   **Tagging:** Automatically generate and apply tags to your posts based on their content.
*   **Search:** Find posts by title without leaving the chat.
*   **Persistent Chat:** Your conversation history is saved locally, so you can pick up where you left off.

Wapuubot leverages the **WordPress AI Client** and **Abilities API** to discover and execute tasks securely.

== Installation ==

1.  Upload the `wapuubot` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Go to **Settings > AI Credentials** to configure your AI provider (e.g., OpenAI, Anthropic).
4.  You will see the Wapuu chat bubble in the bottom-right corner of your admin dashboard.

== Frequently Asked Questions ==

= Does this require an API Key? =
Yes, you need to provide an API key for your preferred AI provider (like OpenAI or Anthropic) in the settings.

= Can Wapuubot modify my site? =
Yes, Wapuubot has capabilities to create and edit posts and terms. It uses WordPress capabilities (like `edit_posts` and `manage_categories`) to ensure only authorized users can perform these actions.

== Changelog ==

= 1.0.0 =
*   Initial release.
*   Added post creation and editing abilities.
*   Added category management abilities.
*   Implemented chat history persistence.
