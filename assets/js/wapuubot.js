document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('wapuubot-container');
    if (!container) return;

    const bubble = document.getElementById('wapuubot-bubble');
    const chatWindow = document.getElementById('wapuubot-chat-window');
    const closeBtn = document.getElementById('wapuubot-close');
    const input = document.getElementById('wapuubot-input');
    const sendBtn = document.getElementById('wapuubot-send');
    const messagesContainer = document.getElementById('wapuubot-messages');

    // Add Clear Button to Header Actions
    const headerActions = document.getElementById('wapuubot-header-actions');
    if (headerActions) {
        const clearBtn = document.createElement('span');
        clearBtn.id = 'wapuubot-clear';
        clearBtn.innerHTML = '&#128465;'; // Trash icon
        clearBtn.title = 'Clear Chat History';
        
        // Use prepend to add it as the first child of the actions container (before the close button)
        headerActions.prepend(clearBtn);

        clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (confirm('Clear chat history?')) {
                chatHistory = [];
                localStorage.removeItem('wapuubot_history');
                messagesContainer.innerHTML = '<div class="wapuubot-message bot">Hi! I\'m Wapuubot. How can I help you today?</div>';
            }
        });
    }

    // Load history from localStorage
    let chatHistory = [];
    try {
        const storedHistory = localStorage.getItem('wapuubot_history');
        if (storedHistory) {
            chatHistory = JSON.parse(storedHistory);
            // Re-render messages
            if (chatHistory.length > 0) {
                // Keep default greeting if it's there? No, clear it if we have history.
                // But wait, the default greeting is hardcoded in HTML.
                // Let's clear the container only if history exists.
                messagesContainer.innerHTML = '';
                
                chatHistory.forEach(msg => {
                    // msg structure: { role: 'user'|'model', parts: [{ text: '...' }] }
                    const sender = msg.role === 'user' ? 'user' : 'bot';
                    // We need to handle potential missing text if something went wrong, but basic check:
                    if (msg.parts && msg.parts[0] && msg.parts[0].text) {
                        addMessage(msg.parts[0].text, sender, false); // false = do not save
                    }
                });
            }
        }
    } catch (e) {
        console.error('Failed to load chat history', e);
    }

    // Toggle chat window
    bubble.addEventListener('click', () => {
        chatWindow.classList.toggle('open');
        if (chatWindow.classList.contains('open')) {
            input.focus();
        }
    });

    // Close chat window
    closeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        chatWindow.classList.remove('open');
    });

    // Send message
    async function sendMessage() {
        const text = input.value.trim();
        if (!text) return;

        // Add user message
        addMessage(text, 'user');
        chatHistory.push({ role: 'user', parts: [{ text: text }] });
        saveHistory();
        input.value = '';

        // Extract context
        const urlParams = new URLSearchParams(window.location.search);
        const postId = urlParams.get('post');
        const context = {
            url: window.location.href,
            postId: postId ? parseInt(postId) : null
        };

        // Show thinking indicator
        const thinkingId = showThinking();

        try {
            const response = await fetch(wapuubotData.rest_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wapuubotData.nonce
                },
                body: JSON.stringify({ 
                    message: text,
                    context: context,
                    history: chatHistory.slice(0, -1) 
                })
            });

            const data = await response.json();
            
            // Remove thinking indicator
            removeThinking(thinkingId);

            if (data.response) {
                addMessage(data.response, 'bot');
                chatHistory.push({ role: 'model', parts: [{ text: data.response }] });
                saveHistory();
            } else {
                addMessage("Sorry, I encountered an error.", 'bot');
                chatHistory.pop();
                saveHistory();
            }
        } catch (error) {
            console.error('Wapuubot Error:', error);
            removeThinking(thinkingId);
            addMessage("I'm having trouble connecting to the server.", 'bot');
            chatHistory.pop();
            saveHistory();
        }
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });

    function saveHistory() {
        localStorage.setItem('wapuubot_history', JSON.stringify(chatHistory));
    }

    function addMessage(text, sender, save = true) {
        const msgDiv = document.createElement('div');
        msgDiv.classList.add('wapuubot-message', sender);
        
        // Simple Markdown parsing
        let formattedText = text;

        // Headings (do these before bold/italic to avoid matching inner syntax prematurely)
        formattedText = formattedText.replace(/^### (.*$)/gm, '<h3>$1</h3>');
        formattedText = formattedText.replace(/^## (.*$)/gm, '<h2>$1</h2>');
        formattedText = formattedText.replace(/^# (.*$)/gm, '<h1>$1</h1>');

        // Bold: **text**
        formattedText = formattedText.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italic: *text*
        formattedText = formattedText.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Code: `text`
        formattedText = formattedText.replace(/`(.*?)`/g, '<code>$1</code>');
        
        // Newlines to <br> (but only if not inside a heading tag already)
        // A simpler way is to replace newlines first, then headings, but headings need ^.
        // Let's do it this way:
        formattedText = formattedText.replace(/\n/g, '<br>');

        msgDiv.innerHTML = formattedText;
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function showThinking() {
        const id = 'thinking-' + Date.now();
        const thinkingDiv = document.createElement('div');
        thinkingDiv.id = id;
        thinkingDiv.classList.add('wapuubot-thinking');
        thinkingDiv.innerHTML = `
            <div class="wapuubot-dot"></div>
            <div class="wapuubot-dot"></div>
            <div class="wapuubot-dot"></div>
        `;
        messagesContainer.appendChild(thinkingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        return id;
    }

    function removeThinking(id) {
        const element = document.getElementById(id);
        if (element) {
            element.remove();
        }
    }
});
