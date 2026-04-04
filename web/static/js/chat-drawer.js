// Chat drawer — floating chat panel for project pages
(function() {
    var drawer, fab, listView, chatView, messagesEl, inputEl, sendBtn, titleEl, backBtn;
    var projectID = 0;
    var activeChatID = 0;
    var source = null;
    var streaming = false;

    function init() {
        drawer = document.getElementById('chat-drawer');
        fab = document.getElementById('chat-fab');
        if (!drawer) return;

        projectID = parseInt(drawer.dataset.projectId, 10);
        listView = document.getElementById('chat-drawer-list');
        chatView = document.getElementById('chat-drawer-chat');
        messagesEl = document.getElementById('chat-drawer-messages');
        inputEl = document.getElementById('chat-drawer-input');
        sendBtn = document.getElementById('chat-drawer-send');
        titleEl = document.getElementById('chat-drawer-title');
        backBtn = document.getElementById('chat-drawer-back');

        inputEl.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                window.chatDrawer.send();
            }
        });

        // Block page navigation while drawer is open
        window.addEventListener('beforeunload', function(e) {
            if (drawer && !drawer.classList.contains('hidden')) {
                // If streaming, close the source
                if (source) {
                    source.close();
                    source = null;
                }
            }
        });

        // Intercept link clicks while drawer is open
        document.addEventListener('click', function(e) {
            if (drawer.classList.contains('hidden')) return;
            var link = e.target.closest('a[href]');
            if (link && !link.closest('#chat-drawer')) {
                e.preventDefault();
                // Flash the drawer to signal "close me first"
                var panel = drawer.querySelector('.bg-zinc-900');
                panel.classList.add('animate-pulse');
                setTimeout(function() { panel.classList.remove('animate-pulse'); }, 500);
            }
        });

        // Also intercept form submits
        document.addEventListener('submit', function(e) {
            if (drawer.classList.contains('hidden')) return;
            var form = e.target;
            if (!form.closest('#chat-drawer')) {
                e.preventDefault();
                var panel = drawer.querySelector('.bg-zinc-900');
                panel.classList.add('animate-pulse');
                setTimeout(function() { panel.classList.remove('animate-pulse'); }, 500);
            }
        });
    }

    function open() {
        if (!drawer) return;
        drawer.classList.remove('hidden');
        fab.classList.add('hidden');
        showList();
    }

    function close() {
        if (!drawer) return;
        if (source) {
            source.close();
            source = null;
        }
        streaming = false;
        drawer.classList.add('hidden');
        fab.classList.remove('hidden');
        activeChatID = 0;
    }

    function showList() {
        activeChatID = 0;
        if (source) { source.close(); source = null; }
        streaming = false;
        titleEl.textContent = 'Chats';
        backBtn.classList.add('hidden');
        listView.classList.remove('hidden');
        chatView.classList.add('hidden');
        chatView.classList.remove('flex');
        loadChatList();
    }

    function loadChatList() {
        listView.textContent = '';
        var loading = document.createElement('p');
        loading.className = 'text-base-content/60 text-sm';
        loading.textContent = 'Loading...';
        listView.appendChild(loading);

        fetch('/projects/' + projectID + '/brainstorm/list-json')
            .then(function(res) { return res.json(); })
            .then(function(chats) {
                listView.textContent = '';

                // New chat button
                var newBtn = document.createElement('button');
                newBtn.className = 'btn btn-primary btn-sm w-full mb-3';
                newBtn.textContent = 'New Chat';
                newBtn.onclick = function() {
                    fetch('/projects/' + projectID + '/brainstorm', { method: 'POST' })
                        .then(function(res) {
                            // Extract chat ID from redirect URL
                            var url = res.url || res.headers.get('Location');
                            if (url) {
                                var parts = url.split('/');
                                var chatID = parseInt(parts[parts.length - 1], 10);
                                if (chatID) {
                                    openChat(chatID, 'New Chat');
                                    return;
                                }
                            }
                            // Fallback: reload list
                            loadChatList();
                        });
                };
                listView.appendChild(newBtn);

                if (chats.length === 0) {
                    var empty = document.createElement('p');
                    empty.className = 'text-base-content/60 text-sm';
                    empty.textContent = 'No chats yet.';
                    listView.appendChild(empty);
                    return;
                }

                chats.forEach(function(chat) {
                    var card = document.createElement('div');
                    card.className = 'card bg-zinc-800/50 shadow-sm mb-2 cursor-pointer hover:bg-zinc-700 transition-colors';
                    var body = document.createElement('div');
                    body.className = 'card-body p-3';
                    var title = document.createElement('div');
                    title.className = 'font-semibold text-sm';
                    title.textContent = chat.title || 'Untitled';
                    body.appendChild(title);
                    if (chat.preview) {
                        var preview = document.createElement('div');
                        preview.className = 'text-xs text-base-content/60 mt-1 line-clamp-2';
                        preview.textContent = chat.preview;
                        body.appendChild(preview);
                    }
                    card.appendChild(body);
                    card.onclick = function() { openChat(chat.id, chat.title); };
                    listView.appendChild(card);
                });
            })
            .catch(function() {
                listView.textContent = '';
                var err = document.createElement('p');
                err.className = 'text-error text-sm';
                err.textContent = 'Failed to load chats.';
                listView.appendChild(err);
            });
    }

    function openChat(chatID, title) {
        activeChatID = chatID;
        titleEl.textContent = title || 'Chat';
        backBtn.classList.remove('hidden');
        listView.classList.add('hidden');
        chatView.classList.remove('hidden');
        chatView.classList.add('flex');
        loadMessages(chatID);
    }

    function loadMessages(chatID) {
        messagesEl.textContent = '';
        fetch('/projects/' + projectID + '/brainstorm/' + chatID + '/messages-json')
            .then(function(res) { return res.json(); })
            .then(function(messages) {
                messagesEl.textContent = '';
                messages.forEach(function(msg) {
                    appendMessage(msg.role, msg.content);
                });
                messagesEl.scrollTop = messagesEl.scrollHeight;
            });
    }

    function appendMessage(role, content) {
        var bubble = document.createElement('div');
        bubble.className = role === 'user'
            ? 'chat chat-end'
            : 'chat chat-start';
        var header = document.createElement('div');
        header.className = 'chat-header text-xs opacity-60';
        header.textContent = role;
        bubble.appendChild(header);
        var msg = document.createElement('div');
        msg.className = role === 'user'
            ? 'chat-bubble chat-bubble-primary'
            : 'chat-bubble';
        msg.textContent = content;
        bubble.appendChild(msg);
        messagesEl.appendChild(bubble);
        return msg;
    }

    function send() {
        if (!activeChatID || streaming) return;
        var msg = inputEl.value.trim();
        if (!msg) return;

        inputEl.value = '';
        streaming = true;
        sendBtn.disabled = true;
        sendBtn.textContent = 'Thinking...';

        // Show user message
        appendMessage('user', msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        // Create assistant bubble for streaming
        var assistBubble = document.createElement('div');
        assistBubble.className = 'chat chat-start';
        var assistHeader = document.createElement('div');
        assistHeader.className = 'chat-header text-xs opacity-60';
        assistHeader.textContent = 'assistant';
        assistBubble.appendChild(assistHeader);
        var assistMsg = document.createElement('div');
        assistMsg.className = 'chat-bubble';
        assistMsg.textContent = '';
        assistBubble.appendChild(assistMsg);
        messagesEl.appendChild(assistBubble);

        // POST message
        fetch('/projects/' + projectID + '/brainstorm/' + activeChatID + '/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(msg)
        }).then(function() {
            // Stream response
            source = new EventSource('/projects/' + projectID + '/brainstorm/' + activeChatID + '/stream');

            source.onmessage = function(event) {
                var d = JSON.parse(event.data);
                if (d.type === 'done') {
                    source.close();
                    source = null;
                    streaming = false;
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send';
                    return;
                }
                if (d.type === 'error') {
                    source.close();
                    source = null;
                    streaming = false;
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send';
                    assistMsg.textContent += '\nError: ' + d.error;
                    return;
                }
                if (d.type === 'chunk') {
                    assistMsg.textContent += d.chunk;
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                }
                if (d.type === 'thinking') {
                    // Skip thinking in drawer — keep it clean
                }
                if (d.type === 'tool_start') {
                    assistMsg.textContent += '\n[' + d.summary + '...]\n';
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                }
            };

            source.onerror = function() {
                if (source) source.close();
                source = null;
                streaming = false;
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
            };
        });
    }

    // Public API
    window.chatDrawer = {
        open: open,
        close: close,
        showList: showList,
        send: send
    };

    document.addEventListener('DOMContentLoaded', init);
})();
