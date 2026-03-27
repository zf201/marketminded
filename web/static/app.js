document.addEventListener('alpine:init', () => {

    // Generic SSE stream consumer (used by pipeline pages)
    Alpine.data('streamOutput', () => ({
        content: '',
        loading: false,
        error: '',
        source: null,

        startStream(url) {
            this.content = '';
            this.loading = true;
            this.error = '';
            if (this.source) this.source.close();

            this.source = new EventSource(url);
            this.source.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.type === 'done') {
                    this.source.close();
                    this.source = null;
                    this.loading = false;
                    return;
                }
                if (data.type === 'error') {
                    this.error = data.error;
                    this.source.close();
                    this.source = null;
                    this.loading = false;
                    return;
                }
                if (data.type === 'chunk') {
                    this.content += data.chunk;
                }
            };
            this.source.onerror = () => {
                if (this.source) this.source.close();
                this.source = null;
                this.loading = false;
                if (!this.content) this.error = 'Connection lost. Try again.';
            };
        },

        destroy() {
            if (this.source) this.source.close();
        }
    }));

    // Brainstorm chat with streaming responses
    Alpine.data('brainstormChat', (projectID, chatID) => ({
        input: '',
        pendingMessage: '',
        streamContent: '',
        thinkingContent: '',
        thinkingDone: false,
        streaming: false,
        error: '',
        source: null,

        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.messages;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        async sendMessage() {
            const msg = this.input.trim();
            if (!msg || this.streaming) return;

            // Show user message immediately
            this.input = '';
            this.pendingMessage = msg;
            this.streamContent = '';
            this.thinkingContent = '';
            this.thinkingDone = false;
            this.error = '';
            this.streaming = true;
            this.scrollToBottom();

            try {
                // POST the user message
                const res = await fetch(`/projects/${projectID}/brainstorm/${chatID}/message`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'content=' + encodeURIComponent(msg)
                });

                if (!res.ok) {
                    throw new Error('Failed to send message: ' + res.statusText);
                }

                // Open SSE stream for the AI response
                this.source = new EventSource(
                    `/projects/${projectID}/brainstorm/${chatID}/stream`
                );

                this.source.onmessage = (event) => {
                    const data = JSON.parse(event.data);

                    if (data.type === 'done') {
                        this.source.close();
                        this.source = null;
                        this.streaming = false;
                        // Reload page to get server-rendered messages
                        // (keeps chat state clean)
                        window.location.reload();
                        return;
                    }
                    if (data.type === 'error') {
                        this.error = data.error;
                        this.source.close();
                        this.source = null;
                        this.streaming = false;
                        return;
                    }
                    if (data.type === 'thinking') {
                        this.thinkingContent += data.chunk;
                        this.scrollToBottom();
                    }
                    if (data.type === 'chunk') {
                        if (!this.thinkingDone && this.thinkingContent) {
                            this.thinkingDone = true;
                        }
                        this.streamContent += data.chunk;
                        this.scrollToBottom();
                    }
                    if (data.type === 'tool_start') {
                        this.streamContent += '\n[' + data.summary + '...]\n';
                        this.scrollToBottom();
                    }
                    if (data.type === 'tool_result') {
                        // Tool result received, AI will continue
                    }
                };

                this.source.onerror = () => {
                    if (this.source) this.source.close();
                    this.source = null;
                    this.streaming = false;
                    if (!this.streamContent) {
                        this.error = 'Connection lost. Try again.';
                    }
                };

            } catch (e) {
                this.error = e.message;
                this.streaming = false;
            }
        },

        destroy() {
            if (this.source) this.source.close();
        }
    }));
});

function initProfileChat(projectID) {
    var form = document.getElementById('profile-chat-form');
    var input = document.getElementById('profile-chat-input');
    var btn = document.getElementById('profile-chat-send');
    var messagesEl = document.getElementById('profile-messages');

    if (!form || !projectID) return;

    // Bind edit buttons via event delegation
    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.profile-card-edit-btn');
        if (editBtn) {
            e.preventDefault();
            editCard(editBtn.dataset.section);
        }
    });

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function addChatText(container, text) {
        // Always append to the LAST .chat-text span — or create one if
        // the last child isn't a text span (e.g. after a tool/proposal block)
        var last = container.lastElementChild;
        if (!last || !last.classList.contains('chat-text')) {
            last = document.createElement('span');
            last.className = 'chat-text';
            container.appendChild(last);
        }
        last.textContent += text;
    }

    function createToolIndicator(summary) {
        var el = document.createElement('div');
        el.className = 'tool-indicator';
        var dots = document.createElement('span');
        dots.className = 'typing-indicator';
        dots.textContent = '...';
        el.appendChild(dots);
        el.appendChild(document.createTextNode(' ' + summary));
        return el;
    }

    function createToolResult(summary) {
        var details = document.createElement('details');
        details.className = 'tool-result-block';
        var summaryEl = document.createElement('summary');
        summaryEl.textContent = summary;
        details.appendChild(summaryEl);
        return details;
    }

    function createProposalBlock(section, content) {
        var block = document.createElement('div');
        block.className = 'proposal-block';
        block.dataset.section = section;

        var header = document.createElement('div');
        header.className = 'proposal-block-header';
        header.textContent = 'Proposed update: ' + section;
        block.appendChild(header);

        var contentEl = document.createElement('div');
        contentEl.className = 'proposal-block-content';
        contentEl.textContent = content;
        block.appendChild(contentEl);

        var actions = document.createElement('div');
        actions.className = 'proposal-block-actions';

        var acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn';
        acceptBtn.textContent = 'Accept';
        acceptBtn.style.fontSize = '0.8rem';
        acceptBtn.onclick = function() {
            fetch('/projects/' + projectID + '/profile/sections/' + section, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(content)
            }).then(function() {
                block.className = 'proposal-block proposal-block-accepted';
                actions.remove();
                var badge = document.createElement('div');
                badge.style.cssText = 'color:#059669;font-size:0.8rem;font-weight:600';
                badge.textContent = 'Accepted';
                block.appendChild(badge);
                // Update card on the right
                var card = document.getElementById('card-' + section);
                if (card) {
                    var cardContent = card.querySelector('.profile-card-content');
                    cardContent.textContent = '';
                    var p = document.createElement('p');
                    p.style.whiteSpace = 'pre-wrap';
                    p.textContent = content;
                    cardContent.appendChild(p);
                    card.classList.remove('profile-card-empty');
                }
            });
        };

        var rejectBtn = document.createElement('button');
        rejectBtn.className = 'btn btn-secondary';
        rejectBtn.textContent = 'Reject';
        rejectBtn.style.fontSize = '0.8rem';
        rejectBtn.onclick = function() {
            block.className = 'proposal-block proposal-block-rejected';
            actions.remove();
            var badge = document.createElement('div');
            badge.style.cssText = 'color:#6b7280;font-size:0.8rem;font-weight:600';
            badge.textContent = 'Rejected';
            block.appendChild(badge);
        };

        actions.appendChild(acceptBtn);
        actions.appendChild(rejectBtn);
        block.appendChild(actions);

        return block;
    }

    // Direct card editing
    function editCard(section) {
        var card = document.getElementById('card-' + section);
        var contentEl = card.querySelector('.profile-card-content');
        var currentText = '';
        var p = contentEl.querySelector('p');
        if (p) currentText = p.textContent;

        contentEl.classList.add('profile-card-editing');
        var ta = document.createElement('textarea');
        ta.className = 'profile-card-editing';
        ta.value = currentText;
        ta.style.cssText = 'width:100%;min-height:80px;font-size:0.85rem;margin-bottom:0.5rem';

        var saveBtn = document.createElement('button');
        saveBtn.className = 'btn';
        saveBtn.textContent = 'Save';
        saveBtn.style.fontSize = '0.75rem';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-secondary';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'font-size:0.75rem;margin-left:0.5rem';

        contentEl.textContent = '';
        contentEl.appendChild(ta);
        contentEl.appendChild(saveBtn);
        contentEl.appendChild(cancelBtn);

        saveBtn.onclick = function() {
            var newContent = ta.value;
            fetch('/projects/' + projectID + '/profile/sections/' + section, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(newContent)
            }).then(function() {
                contentEl.textContent = '';
                contentEl.classList.remove('profile-card-editing');
                var np = document.createElement('p');
                np.style.whiteSpace = 'pre-wrap';
                np.textContent = newContent;
                contentEl.appendChild(np);
                if (newContent) card.classList.remove('profile-card-empty');
                else card.classList.add('profile-card-empty');
            });
        };

        cancelBtn.onclick = function() {
            contentEl.textContent = '';
            contentEl.classList.remove('profile-card-editing');
            var np = document.createElement('p');
            np.style.whiteSpace = 'pre-wrap';
            if (currentText) {
                np.textContent = currentText;
            } else {
                np.className = 'text-muted';
                np.style.fontStyle = 'italic';
                np.textContent = 'Not yet filled';
            }
            contentEl.appendChild(np);
        };

        ta.focus();
    }

    // Chat send with typed SSE events
    input.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;

        input.disabled = true;
        btn.disabled = true;
        btn.textContent = 'Thinking...';
        input.value = '';

        // User message
        var userDiv = document.createElement('div');
        userDiv.className = 'chat-msg chat-msg-user';
        var roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = 'user';
        var bodyEl = document.createElement('div');
        bodyEl.style.whiteSpace = 'pre-wrap';
        bodyEl.textContent = msg;
        userDiv.appendChild(roleEl);
        userDiv.appendChild(bodyEl);
        messagesEl.appendChild(userDiv);

        // Assistant bubble
        var assistantDiv = document.createElement('div');
        assistantDiv.className = 'chat-msg chat-msg-assistant';
        var aRoleEl = document.createElement('div');
        aRoleEl.className = 'chat-msg-role';
        aRoleEl.textContent = 'assistant';
        assistantDiv.appendChild(aRoleEl);
        var aBody = document.createElement('div');
        aBody.style.whiteSpace = 'pre-wrap';
        assistantDiv.appendChild(aBody);
        messagesEl.appendChild(assistantDiv);
        scrollToBottom();

        // Stream with typed SSE events
        fetch('/projects/' + projectID + '/profile/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(msg)
        }).then(function() {
            var source = new EventSource('/projects/' + projectID + '/profile/stream');
            var lastToolIndicator = null;

            source.onmessage = function(event) {
                var d = JSON.parse(event.data);

                switch (d.type) {
                case 'chunk':
                    addChatText(aBody, d.chunk);
                    scrollToBottom();
                    break;

                case 'tool_start':
                    lastToolIndicator = createToolIndicator(d.summary);
                    aBody.appendChild(lastToolIndicator);
                    scrollToBottom();
                    break;

                case 'tool_result':
                    if (lastToolIndicator) {
                        var resultBlock = createToolResult(d.summary);
                        lastToolIndicator.replaceWith(resultBlock);
                        lastToolIndicator = null;
                    }
                    scrollToBottom();
                    break;

                case 'proposal':
                    var proposalBlock = createProposalBlock(d.section, d.content);
                    aBody.appendChild(proposalBlock);
                    scrollToBottom();
                    break;

                case 'error':
                    source.close();
                    addChatText(aBody, '\nError: ' + d.error);
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    break;

                case 'done':
                    source.close();
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    scrollToBottom();
                    break;
                }
            };
            source.onerror = function() {
                source.close();
                input.disabled = false;
                btn.disabled = false;
                btn.textContent = 'Send';
            };
        }).catch(function(err) {
            addChatText(aBody, 'Error: ' + err.message);
            input.disabled = false;
            btn.disabled = false;
            btn.textContent = 'Send';
        });
    });

    scrollToBottom();
}

// Auto-init pages
document.addEventListener('DOMContentLoaded', function() {
    var sectionPage = document.getElementById('profile-section-page');
    if (sectionPage) {
        initProfileSectionChat(sectionPage.dataset.projectId, sectionPage.dataset.section);
    }

    var board = document.getElementById('production-board');
    if (board) {
        initProductionBoard(board.dataset.projectId, board.dataset.runId);
    }

    var contextPage = document.getElementById('context-chat-page');
    if (contextPage) {
        initContextChat(contextPage.dataset.projectId, contextPage.dataset.itemId);
    }

    var cornerstonePage = document.getElementById('cornerstone-pipeline-page');
    if (cornerstonePage) {
        initCornerstonePipeline(cornerstonePage.dataset.projectId, cornerstonePage.dataset.runId);
    }

});

function initProfileSectionChat(projectID, sectionName) {
    var form = document.getElementById('profile-section-form');
    var input = document.getElementById('profile-section-input');
    var btn = document.getElementById('profile-section-send');
    var messagesEl = document.getElementById('profile-section-messages');
    if (!form) return;

    function scrollToBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }

    function addMsg(role, text) {
        var outer = document.createElement('div');
        outer.className = 'chat-msg chat-msg-' + role;
        var roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = role;
        var bodyEl = document.createElement('div');
        bodyEl.style.whiteSpace = 'pre-wrap';
        bodyEl.textContent = text;
        outer.appendChild(roleEl);
        outer.appendChild(bodyEl);
        messagesEl.appendChild(outer);
        return bodyEl;
    }

    function addChatText(container, text) {
        var last = container.lastElementChild;
        if (!last || !last.classList.contains('chat-text')) {
            last = document.createElement('span');
            last.className = 'chat-text';
            container.appendChild(last);
        }
        last.textContent += text;
    }

    input.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;

        input.disabled = true;
        btn.disabled = true;
        btn.textContent = 'Thinking...';
        input.value = '';

        addMsg('user', msg);
        var aBody = addMsg('assistant', '');
        aBody.textContent = '';
        scrollToBottom();

        fetch('/projects/' + projectID + '/profile/' + sectionName + '/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(msg)
        }).then(function() {
            var source = new EventSource('/projects/' + projectID + '/profile/' + sectionName + '/stream');
            source.onmessage = function(event) {
                var d = JSON.parse(event.data);
                switch (d.type) {
                case 'chunk':
                    addChatText(aBody, d.chunk);
                    scrollToBottom();
                    break;
                case 'tool_start':
                    var indicator = document.createElement('div');
                    indicator.className = 'tool-indicator';
                    indicator.textContent = d.summary + '...';
                    aBody.appendChild(indicator);
                    scrollToBottom();
                    break;
                case 'tool_result':
                    var lastInd = aBody.querySelector('.tool-indicator:last-of-type');
                    if (lastInd) {
                        var details = document.createElement('details');
                        details.className = 'tool-result-block';
                        var sm = document.createElement('summary');
                        sm.textContent = d.summary;
                        details.appendChild(sm);
                        lastInd.replaceWith(details);
                    }
                    break;
                case 'proposal':
                    var block = document.createElement('div');
                    block.className = 'proposal-block';
                    var hdr = document.createElement('div');
                    hdr.className = 'proposal-block-header';
                    hdr.textContent = 'Proposed update: ' + d.section;
                    block.appendChild(hdr);
                    var cEl = document.createElement('div');
                    cEl.className = 'proposal-block-content';
                    cEl.textContent = d.content;
                    block.appendChild(cEl);
                    var acts = document.createElement('div');
                    acts.className = 'proposal-block-actions';
                    var accBtn = document.createElement('button');
                    accBtn.className = 'btn';
                    accBtn.textContent = 'Accept';
                    accBtn.style.fontSize = '0.8rem';
                    accBtn.onclick = function() {
                        fetch('/projects/' + projectID + '/profile/sections/' + d.section, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'content=' + encodeURIComponent(d.content)
                        }).then(function() {
                            block.className = 'proposal-block proposal-block-accepted';
                            acts.remove();
                            var badge = document.createElement('div');
                            badge.style.cssText = 'color:#059669;font-size:0.8rem;font-weight:600';
                            badge.textContent = 'Accepted';
                            block.appendChild(badge);
                        });
                    };
                    var rejBtn = document.createElement('button');
                    rejBtn.className = 'btn btn-secondary';
                    rejBtn.textContent = 'Reject';
                    rejBtn.style.fontSize = '0.8rem';
                    rejBtn.onclick = function() {
                        block.className = 'proposal-block proposal-block-rejected';
                        acts.remove();
                        var badge = document.createElement('div');
                        badge.style.cssText = 'color:#6b7280;font-size:0.8rem;font-weight:600';
                        badge.textContent = 'Rejected';
                        block.appendChild(badge);
                    };
                    acts.appendChild(accBtn);
                    acts.appendChild(rejBtn);
                    block.appendChild(acts);
                    aBody.appendChild(block);
                    scrollToBottom();
                    break;
                case 'error':
                    source.close();
                    addChatText(aBody, '\nError: ' + d.error);
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    break;
                case 'done':
                    source.close();
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    scrollToBottom();
                    break;
                }
            };
            source.onerror = function() {
                source.close();
                input.disabled = false;
                btn.disabled = false;
                btn.textContent = 'Send';
            };
        }).catch(function(err) {
            addChatText(aBody, 'Error: ' + err.message);
            input.disabled = false;
            btn.disabled = false;
            btn.textContent = 'Send';
        });
    });

    scrollToBottom();
}

function initContextChat(projectID, itemID) {
    var form = document.getElementById('context-chat-form');
    var input = document.getElementById('context-chat-input');
    var btn = document.getElementById('context-chat-send');
    var saveBtn = document.getElementById('context-save-btn');
    var messagesEl = document.getElementById('context-messages');
    if (!form) return;

    function scrollToBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }

    function addMsg(role, text) {
        var outer = document.createElement('div');
        outer.className = 'chat-msg chat-msg-' + role;
        var roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = role;
        var bodyEl = document.createElement('div');
        bodyEl.style.whiteSpace = 'pre-wrap';
        bodyEl.textContent = text;
        outer.appendChild(roleEl);
        outer.appendChild(bodyEl);
        messagesEl.appendChild(outer);
        return bodyEl;
    }

    function addChatText(container, text) {
        var last = container.lastElementChild;
        if (!last || !last.classList.contains('chat-text')) {
            last = document.createElement('span');
            last.className = 'chat-text';
            container.appendChild(last);
        }
        last.textContent += text;
    }

    input.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;

        input.disabled = true;
        btn.disabled = true;
        btn.textContent = 'Thinking...';
        input.value = '';

        addMsg('user', msg);
        var aBody = addMsg('assistant', '');
        aBody.textContent = '';
        scrollToBottom();

        fetch('/projects/' + projectID + '/context/' + itemID + '/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(msg)
        }).then(function() {
            var source = new EventSource('/projects/' + projectID + '/context/' + itemID + '/stream');
            source.onmessage = function(event) {
                var d = JSON.parse(event.data);
                if (d.type === 'chunk') { addChatText(aBody, d.chunk); scrollToBottom(); }
                else if (d.type === 'error') { source.close(); addChatText(aBody, '\nError: ' + d.error); input.disabled = false; btn.disabled = false; btn.textContent = 'Send'; }
                else if (d.type === 'done') { source.close(); input.disabled = false; btn.disabled = false; btn.textContent = 'Send'; scrollToBottom(); }
            };
            source.onerror = function() { source.close(); input.disabled = false; btn.disabled = false; btn.textContent = 'Send'; };
        });
    });

    // Save button — collects last assistant message as content, AI generates title
    saveBtn.addEventListener('click', function() {
        // Get all assistant messages, take the last one as the refined content
        var assistantMsgs = messagesEl.querySelectorAll('.chat-msg-assistant');
        var lastMsg = assistantMsgs.length > 0 ? assistantMsgs[assistantMsgs.length - 1] : null;
        var content = '';
        if (lastMsg) {
            var bodyDiv = lastMsg.querySelector('div:last-child');
            if (bodyDiv) content = bodyDiv.textContent.trim();
        }
        // If no assistant message, use the last user message
        if (!content) {
            var userMsgs = messagesEl.querySelectorAll('.chat-msg-user');
            var lastUser = userMsgs.length > 0 ? userMsgs[userMsgs.length - 1] : null;
            if (lastUser) {
                var uBody = lastUser.querySelector('div:last-child');
                if (uBody) content = uBody.textContent.trim();
            }
        }

        if (!content) { alert('Nothing to save yet. Chat first.'); return; }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/projects/' + projectID + '/context/' + itemID + '/save';
        var cInput = document.createElement('input'); cInput.type = 'hidden'; cInput.name = 'content'; cInput.value = content;
        form.appendChild(cInput);
        document.body.appendChild(form);
        form.submit();
    });

    scrollToBottom();
}

// --- Render helpers ---

function renderSection(parent, label, content, opts) {
    opts = opts || {};
    if (!content && !opts.force) return;
    var sec = document.createElement('div');
    sec.className = 'content-field';
    if (opts.minor) sec.className += ' content-field-minor';
    var lbl = document.createElement('div');
    lbl.className = opts.minor ? 'content-field-label-minor' : 'content-field-label';
    lbl.textContent = label;
    sec.appendChild(lbl);
    if (opts.markdown && typeof marked !== 'undefined' && content) {
        var md = document.createElement('div');
        md.className = 'markdown-body';
        // Unescape literal \n to real newlines, then collapse multiple blank lines
        var cleaned = content.replace(/\\n/g, '\n').replace(/\n{3,}/g, '\n\n');
        md.innerHTML = marked.parse(cleaned, { breaks: false, gfm: true });
        sec.appendChild(md);
    } else if (opts.badges && content) {
        var badges = document.createElement('div');
        badges.className = 'content-badges';
        content.split(/\s+/).forEach(function(tag) {
            if (!tag) return;
            var b = document.createElement('span');
            b.className = 'content-badge';
            b.textContent = tag;
            badges.appendChild(b);
        });
        sec.appendChild(badges);
    } else if (content) {
        var txt = document.createElement('div');
        txt.className = 'content-field-value';
        txt.textContent = content;
        sec.appendChild(txt);
    }
    parent.appendChild(sec);
    return sec;
}

// backward compat alias
function renderField(parent, label, value, markdown) {
    renderSection(parent, label, value, { markdown: markdown });
}

function renderPlan(el) {
    var raw = el.textContent.trim();
    if (!raw) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    el.textContent = '';

    if (data.cornerstone) {
        var h = document.createElement('div');
        h.style.cssText = 'font-weight:600;margin-bottom:0.5rem;font-size:0.95rem';
        h.textContent = 'Cornerstone: ' + (data.cornerstone.platform || '') + '/' + (data.cornerstone.format || '') + (data.cornerstone.title ? ' — ' + data.cornerstone.title : '');
        el.appendChild(h);
    }

}

// --- Content type renderers ---

function renderContentBody(el, platform, format, bodyText) {
    var data;
    try {
        data = JSON.parse(bodyText);
    } catch (e) {
        // Fallback: plain text
        el.textContent = bodyText;
        return;
    }

    el.textContent = '';
    var key = platform + '_' + format;

    switch (key) {
    case 'blog_post':
        renderBlogPost(el, data); break;
    case 'linkedin_post':
    case 'instagram_post':
    case 'facebook_post':
        renderSimplePost(el, data); break;
    case 'x_post':
        renderXPost(el, data); break;
    case 'x_thread':
        renderXThread(el, data); break;
    case 'linkedin_carousel':
        renderLinkedinCarousel(el, data); break;
    case 'instagram_carousel':
        renderInstagramCarousel(el, data); break;
    case 'instagram_reel':
    case 'youtube_short':
    case 'tiktok_video':
        renderScript(el, data); break;
    case 'youtube_script':
        renderYoutubeScript(el, data); break;
    default:
        el.textContent = bodyText;
    }

    // Always render instructions if present (available on all types)
    if (data && data.instructions) {
        renderSection(el, 'Production Notes', data.instructions, { minor: true, markdown: true });
    }
}

function renderBlogPost(el, data) {
    renderSection(el, 'Title', data.title);
    renderSection(el, 'Body', data.body, { markdown: true });
    if (data.body) {
        var copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-secondary';
        copyBtn.textContent = 'Copy Markdown';
        copyBtn.style.cssText = 'font-size:0.75rem;padding:0.2rem 0.5rem;margin-top:0.25rem';
        copyBtn.onclick = function() {
            navigator.clipboard.writeText(data.body).then(function() {
                copyBtn.textContent = 'Copied!';
                setTimeout(function() { copyBtn.textContent = 'Copy Markdown'; }, 2000);
            });
        };
        el.appendChild(copyBtn);
    }
    renderSection(el, 'Meta Description', data.meta_description, { minor: true });
}

function renderSimplePost(el, data) {
    renderSection(el, 'Caption', data.caption);
    if (data.hashtags) renderSection(el, 'Hashtags', data.hashtags, { badges: true, minor: true });
}

function renderXPost(el, data) {
    renderSection(el, 'Tweet', data.text);
}

function renderXThread(el, data) {
    if (!data.tweets) return;
    var sec = renderSection(el, 'Tweets (' + data.tweets.length + ')', null, { force: true });
    var items = document.createElement('div'); items.className = 'content-items';
    data.tweets.forEach(function(tweet, i) {
        var item = document.createElement('div'); item.className = 'content-item';
        var num = document.createElement('span'); num.className = 'content-item-num'; num.textContent = (i + 1) + '.';
        item.appendChild(num);
        item.appendChild(document.createTextNode(' ' + tweet));
        items.appendChild(item);
    });
    sec.appendChild(items);
}

function renderLinkedinCarousel(el, data) {
    if (data.slides) {
        var sec = renderSection(el, 'Slides (' + data.slides.length + ')', null, { force: true });
        data.slides.forEach(function(slide, i) {
            var card = document.createElement('div'); card.className = 'slide-card';
            var title = document.createElement('div'); title.className = 'slide-card-title'; title.textContent = 'Slide ' + (i + 1) + (slide.title ? ': ' + slide.title : '');
            var body = document.createElement('div'); body.className = 'slide-card-body'; body.textContent = slide.body || '';
            card.appendChild(title); card.appendChild(body); sec.appendChild(card);
        });
    }
    renderSection(el, 'Caption', data.caption);
}

function renderInstagramCarousel(el, data) {
    if (data.slides) {
        var sec = renderSection(el, 'Slides (' + data.slides.length + ')', null, { force: true });
        data.slides.forEach(function(slide, i) {
            var card = document.createElement('div'); card.className = 'slide-card';
            var title = document.createElement('div'); title.className = 'slide-card-title'; title.textContent = 'Slide ' + (i + 1);
            var body = document.createElement('div'); body.className = 'slide-card-body'; body.textContent = slide.text || '';
            card.appendChild(title); card.appendChild(body); sec.appendChild(card);
        });
    }
    renderSection(el, 'Caption', data.caption);
    if (data.hashtags) renderSection(el, 'Hashtags', data.hashtags, { badges: true, minor: true });
}

function renderScript(el, data) {
    var scriptFields = [
        ['hook', 'Hook'],
        ['setup', 'Setup'],
        ['value', 'Value'],
        ['content', 'Content'],
        ['cta', 'CTA']
    ];
    scriptFields.forEach(function(pair) {
        if (data[pair[0]]) renderSection(el, pair[1], data[pair[0]]);
    });
    if (data.caption) renderSection(el, 'Caption', data.caption, { minor: true });
}

function renderYoutubeScript(el, data) {
    renderSection(el, 'Title', data.title);
    if (data.sections) {
        var sec = renderSection(el, 'Script Sections', null, { force: true });
        data.sections.forEach(function(s) {
            var div = document.createElement('div'); div.className = 'content-field';
            var heading = document.createElement('div'); heading.className = 'content-field-label-minor';
            heading.textContent = (s.timestamp ? '[' + s.timestamp + '] ' : '') + s.heading;
            div.appendChild(heading);
            var content = document.createElement('div'); content.className = 'content-field-value';
            content.textContent = s.content;
            div.appendChild(content);
            if (s.notes) { var n = document.createElement('div'); n.className = 'text-muted'; n.style.fontSize = '0.8rem'; n.textContent = '[' + s.notes + ']'; div.appendChild(n); }
            sec.appendChild(div);
        });
    }
}

// --- Unified Content Modal (improve + proofread) ---

function openContentModal(opts) {
    var mode = opts.mode; // 'improve' or 'proofread'
    var pieceId = opts.pieceId;
    var platform = opts.platform;
    var format = opts.format;
    var basePath = opts.basePath;
    var controller = new AbortController();

    // Build modal DOM
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100;display:flex;align-items:center;justify-content:center';

    var modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:8px;padding:1.5rem;max-width:700px;width:90%;max-height:85vh;display:flex;flex-direction:column';

    var headerRow = document.createElement('div');
    headerRow.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem';
    var titleEl = document.createElement('h3');
    titleEl.textContent = mode === 'proofread' ? 'Proofreading...' : 'Improve Content';
    titleEl.style.margin = '0';
    headerRow.appendChild(titleEl);
    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.fontSize = '0.8rem';
    cancelBtn.onclick = function() {
        controller.abort();
        overlay.remove();
    };
    headerRow.appendChild(cancelBtn);
    modal.appendChild(headerRow);

    // Chat area (improve only)
    var chatArea = document.createElement('div');
    chatArea.style.cssText = 'display:flex;flex-direction:column;flex:1;overflow:hidden';

    var messages = document.createElement('div');
    messages.style.cssText = 'overflow-y:auto;flex:1;margin-bottom:0.75rem;max-height:200px';
    chatArea.appendChild(messages);

    if (mode === 'improve') {
        var textarea = document.createElement('textarea');
        textarea.style.cssText = 'width:100%;min-height:60px;padding:0.5rem;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;resize:vertical;margin-bottom:0.5rem';
        textarea.placeholder = 'What should be improved?';
        chatArea.appendChild(textarea);
        var sendBtn = document.createElement('button');
        sendBtn.className = 'btn';
        sendBtn.textContent = 'Send';
        chatArea.appendChild(sendBtn);
        modal.appendChild(chatArea);

        textarea.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); sendBtn.click(); }
        });

        sendBtn.addEventListener('click', function() {
            var msg = textarea.value.trim();
            if (!msg) return;
            textarea.value = '';
            sendBtn.disabled = true;
            sendBtn.textContent = 'Rewriting...';

            // Show user message
            var userDiv = document.createElement('div');
            userDiv.className = 'chat-msg chat-msg-user';
            userDiv.style.fontSize = '0.85rem';
            var roleDiv = document.createElement('div');
            roleDiv.className = 'chat-msg-role';
            roleDiv.textContent = 'you';
            userDiv.appendChild(roleDiv);
            var bodyDiv = document.createElement('div');
            bodyDiv.textContent = msg;
            userDiv.appendChild(bodyDiv);
            messages.appendChild(userDiv);
            messages.scrollTop = messages.scrollHeight;

            // Hide preview if showing from previous round
            previewArea.style.display = 'none';
            actions.style.display = 'none';

            // Post message then stream rewrite
            fetch(basePath + '/piece/' + pieceId + '/improve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(msg)
            }).then(function() {
                var source = new EventSource(basePath + '/piece/' + pieceId + '/improve/stream');
                var accumulated = '';
                source.onmessage = function(event) {
                    var d = JSON.parse(event.data);
                    if (d.type === 'chunk') {
                        accumulated += d.chunk;
                    } else if (d.type === 'content_written') {
                        // AI used write tool — show preview
                        source.close();
                        showPreview(JSON.stringify(d.data));
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send';
                    } else if (d.type === 'done') {
                        source.close();
                        // If we got text but no write tool, show it as chat
                        if (accumulated && previewArea.style.display === 'none') {
                            var assistDiv = document.createElement('div');
                            assistDiv.className = 'chat-msg chat-msg-assistant';
                            assistDiv.style.fontSize = '0.85rem';
                            var aRole = document.createElement('div');
                            aRole.className = 'chat-msg-role';
                            aRole.textContent = 'assistant';
                            assistDiv.appendChild(aRole);
                            var aBody = document.createElement('div');
                            aBody.style.whiteSpace = 'pre-wrap';
                            aBody.textContent = accumulated;
                            assistDiv.appendChild(aBody);
                            messages.appendChild(assistDiv);
                            messages.scrollTop = messages.scrollHeight;
                        }
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send';
                    } else if (d.type === 'error') {
                        source.close();
                        var errDiv = document.createElement('div');
                        errDiv.style.cssText = 'color:red;font-size:0.85rem';
                        errDiv.textContent = 'Error: ' + d.error;
                        messages.appendChild(errDiv);
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send';
                    }
                };
                source.onerror = function() {
                    source.close();
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send';
                };
            });
        });
    }

    // Loading spinner (proofread)
    var spinnerEl = document.createElement('div');
    spinnerEl.style.cssText = 'text-align:center;padding:2rem;display:none';
    spinnerEl.innerHTML = '<div class="spinner"></div><p class="text-muted" style="margin-top:1rem">Proofreading content...</p>';
    modal.appendChild(spinnerEl);

    // Preview area — uses renderContentBody
    var previewArea = document.createElement('div');
    previewArea.style.cssText = 'overflow-y:auto;flex:1;background:#f9fafb;padding:0.75rem;border-radius:4px;margin-bottom:0.75rem;min-height:150px;max-height:400px;display:none';
    modal.appendChild(previewArea);

    // Actions
    var actions = document.createElement('div');
    actions.style.cssText = 'display:none;gap:0.5rem';
    modal.appendChild(actions);

    // Cancel button is in the header row

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    var pendingContent = '';

    function showPreview(contentJSON) {
        previewArea.style.display = 'block';
        previewArea.textContent = '';
        renderContentBody(previewArea, platform, format, contentJSON);
        pendingContent = contentJSON;
        titleEl.textContent = mode === 'proofread' ? 'Proofread Complete' : 'New Version Ready';

        actions.textContent = '';
        actions.style.display = 'flex';

        var acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn';
        acceptBtn.textContent = 'Accept';
        acceptBtn.onclick = function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = basePath + '/piece/' + pieceId + '/save-proofread';
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'corrected'; inp.value = pendingContent;
            form.appendChild(inp);
            document.body.appendChild(form);
            form.submit();
        };
        actions.appendChild(acceptBtn);

        var rejectBtn = document.createElement('button');
        rejectBtn.className = 'btn btn-secondary';
        rejectBtn.textContent = mode === 'improve' ? 'Try Again' : 'Reject';
        rejectBtn.onclick = function() {
            if (mode === 'improve') {
                // Back to chat
                previewArea.style.display = 'none';
                actions.style.display = 'none';
                titleEl.textContent = 'Improve Content';
                textarea.focus();
            } else {
                overlay.remove();
            }
        };
        actions.appendChild(rejectBtn);

        cancelBtn.style.display = 'none';
    }

    // Start proofread immediately
    if (mode === 'proofread') {
        spinnerEl.style.display = 'block';
        fetch(basePath + '/piece/' + pieceId + '/proofread', { signal: controller.signal })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                spinnerEl.style.display = 'none';
                showPreview(data.corrected);
            })
            .catch(function(err) {
                spinnerEl.style.display = 'none';
                if (err.name !== 'AbortError') {
                    previewArea.style.display = 'block';
                    previewArea.textContent = 'Error: ' + err.message;
                }
                cancelBtn.textContent = 'Close';
            });
    }
}

// --- Cornerstone pipeline auto-chaining ---

function initCornerstonePipeline(projectID, runID) {
    var basePath = '/projects/' + projectID + '/pipeline/' + runID;
    var runBtn = document.getElementById('run-pipeline-btn');

    function setBadge(card, text, cls) {
        var badges = card.querySelectorAll('.badge');
        var badge = badges[badges.length - 1];
        if (!badge) return;
        badge.textContent = text;
        badge.className = 'badge ' + (cls || '');
    }

    function addToolPill(card, type, value) {
        var pillsEl = card.querySelector('.step-tool-pills');
        if (!pillsEl) return;
        if (type === 'search') {
            var pill = document.createElement('span');
            pill.className = 'tool-pill tool-pill-search';
            var icon = document.createElement('span');
            icon.textContent = '\uD83D\uDD0D';
            icon.style.opacity = '0.5';
            pill.appendChild(icon);
            var text = document.createTextNode(' ' + (value.length > 30 ? value.substring(0, 30) + '\u2026' : value));
            pill.appendChild(text);
            pill.title = value;
            pillsEl.appendChild(pill);
        } else if (type === 'fetch') {
            var a = document.createElement('a');
            a.className = 'tool-pill tool-pill-fetch';
            a.href = value;
            a.target = '_blank';
            var icon = document.createElement('span');
            icon.textContent = '\uD83C\uDF10';
            icon.style.opacity = '0.6';
            a.appendChild(icon);
            var host = value;
            try { host = new URL(value).hostname; } catch(e) { host = value.substring(0, 25); }
            var text = document.createTextNode(' ' + host);
            a.appendChild(text);
            a.title = value;
            pillsEl.appendChild(a);
        }
    }

    function streamStep(stepID, card, onDone, onError) {
        var streamEl = card.querySelector('.step-stream');
        var outputEl = card.querySelector('.step-output');
        var tickerEl = card.querySelector('.step-thinking-ticker');

        if (streamEl) streamEl.textContent = '';
        if (outputEl) outputEl.textContent = '';
        if (tickerEl) tickerEl.textContent = '';

        var url = basePath + '/stream/step/' + stepID;
        var source = new EventSource(url);

        source.onmessage = function(event) {
            var d = JSON.parse(event.data);

            if (d.type === 'thinking') {
                // Thinking ticker — show last ~3 lines, auto-scrolls
                if (tickerEl) {
                    tickerEl.textContent += d.chunk;
                    tickerEl.scrollTop = tickerEl.scrollHeight;
                }
            } else if (d.type === 'chunk') {
                // Streamed output — visible while running
                if (streamEl) {
                    streamEl.textContent += d.chunk;
                    streamEl.scrollTop = streamEl.scrollHeight;
                }
            } else if (d.type === 'tool_start') {
                if (d.tool === 'web_search' && d.query) {
                    addToolPill(card, 'search', d.query);
                } else if (d.tool === 'fetch_url' && d.url) {
                    addToolPill(card, 'fetch', d.url);
                }
            } else if (d.type === 'content_written') {
                if (outputEl) renderContentBody(outputEl, d.platform, d.format, JSON.stringify(d.data));
            } else if (d.type === 'done') {
                source.close();
                if (tickerEl) tickerEl.classList.add('done');
                setBadge(card, 'completed', 'badge-completed');
                card.dataset.status = 'completed';
                if (onDone) onDone();
            } else if (d.type === 'error') {
                source.close();
                if (tickerEl) tickerEl.classList.add('done');
                if (streamEl) streamEl.textContent += '\nError: ' + d.error;
                setBadge(card, 'failed', 'badge-failed');
                card.dataset.status = 'failed';
                if (onError) onError(d.error);
            }
        };

        source.onerror = function() {
            source.close();
            setBadge(card, 'failed', 'badge-failed');
            card.dataset.status = 'failed';
            if (onError) onError('Connection lost');
        };

        return source;
    }

    function runNextStep(cards, index) {
        if (index >= cards.length) {
            // All done — reload to show the cornerstone piece
            window.location.reload();
            return;
        }

        var card = cards[index];
        var status = card.dataset.status;

        if (status === 'completed') {
            runNextStep(cards, index + 1);
            return;
        }

        if (status !== 'pending' && status !== 'failed') {
            // Skip non-runnable statuses
            runNextStep(cards, index + 1);
            return;
        }

        var stepID = card.dataset.stepId;
        setBadge(card, 'running', 'badge-running');
        card.dataset.status = 'running';

        streamStep(stepID, card, function() {
            runNextStep(cards, index + 1);
        }, function() {
            // On error, stop chaining
        });
    }

    if (runBtn) {
        runBtn.addEventListener('click', function() {
            runBtn.disabled = true;
            runBtn.textContent = 'Running...';

            var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
            console.log('[pipeline] Run Pipeline clicked, found', cards.length, 'step cards');
            cards.forEach(function(c) { console.log('[pipeline] card:', c.dataset.stepId, c.dataset.status); });
            runNextStep(cards, 0);
        });
    }

    // Retry buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.step-retry-btn');
        if (!btn) return;

        var stepIndex = parseInt(btn.dataset.stepIndex) || 0;
        var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
        if (stepIndex < cards.length) {
            var card = cards[stepIndex];
            var stepID = card.dataset.stepId;
            setBadge(card, 'running', 'badge-running');
            card.dataset.status = 'running';
            btn.disabled = true;

            streamStep(stepID, card, function() {
                // After retry success, continue with next steps
                runNextStep(cards, stepIndex + 1);
            }, function() {
                btn.disabled = false;
            });
        }
    });

    // Approve button
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.piece-approve-btn');
        if (!btn) return;

        var pieceId = btn.dataset.pieceId;
        if (!pieceId) return;

        btn.disabled = true;
        btn.textContent = 'Approving...';

        fetch(basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
            .then(function() { window.location.reload(); })
            .catch(function() { window.location.reload(); });
    });

    // Reject button
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.piece-reject-btn');
        if (!btn) return;
        var pieceId = btn.dataset.pieceId;
        if (!pieceId) return;
        var reason = prompt('Why should this be rejected?');
        if (reason === null) return;
        fetch(basePath + '/piece/' + pieceId + '/reject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'reason=' + encodeURIComponent(reason)
        }).then(function() { window.location.reload(); });
    });

    // Render existing content bodies (cornerstone piece after page load)
    document.querySelectorAll('.board-card-body').forEach(function(el) {
        var text = el.textContent.trim();
        if (text && el.dataset.platform) {
            renderContentBody(el, el.dataset.platform, el.dataset.format, text);
        }
    });

    // Topic card expand/collapse
    var topicCard = document.querySelector('.topic-card');
    if (topicCard) {
        var topicHeader = topicCard.querySelector('.board-card-header');
        var topicBrief = topicCard.querySelector('.topic-brief');
        var rightGroup = document.createElement('div');
        rightGroup.style.cssText = 'display:flex;align-items:center;gap:0.3rem';
        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'step-toggle-btn';
        toggleBtn.textContent = '+';
        toggleBtn.title = 'Expand';
        rightGroup.appendChild(toggleBtn);
        topicHeader.appendChild(rightGroup);
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var isCollapsed = topicCard.dataset.collapsed === 'true';
            if (topicBrief) topicBrief.style.display = isCollapsed ? '' : 'none';
            topicCard.dataset.collapsed = isCollapsed ? 'false' : 'true';
            toggleBtn.textContent = isCollapsed ? '\u2212' : '+';
            topicHeader.style.marginBottom = isCollapsed ? '' : '0';
        });
    }

    // Render tool pills from data attribute on page load
    document.querySelectorAll('.step-card[data-tool-calls]').forEach(function(card) {
        var raw = card.dataset.toolCalls;
        if (!raw) return;
        try {
            var calls = JSON.parse(raw);
            calls.forEach(function(tc) {
                addToolPill(card, tc.type, tc.value);
            });
        } catch(e) {}
    });

    // Render step outputs nicely and collapse completed non-last steps
    var stepCards = document.querySelectorAll('.step-card[data-step-id]');
    var allCompleted = true;
    stepCards.forEach(function(card) {
        if (card.dataset.status !== 'completed') allCompleted = false;
    });

    stepCards.forEach(function(card, idx) {
        var stepType = card.querySelector('.board-card-header strong');
        var outputEl = card.querySelector('.step-output');
        if (!outputEl) return;
        var raw = outputEl.textContent.trim();
        if (!raw) return;
        var typeName = stepType ? stepType.textContent.trim() : '';

        // Writer output is shown in the piece card below — hide the raw JSON
        if (typeName === 'Writer') {
            outputEl.style.display = 'none';
        } else {
            try {
                var data = JSON.parse(raw);
                renderStepOutput(outputEl, typeName, data);
            } catch (e) {
                // Leave as text
            }
        }

        // Collapse completed steps except the last one when all are done
        if (allCompleted && card.dataset.status === 'completed' && idx < stepCards.length - 1) {
            var output = card.querySelector('.step-output');
            var pills = card.querySelector('.step-tool-pills');
            if (output) output.style.display = 'none';
            if (pills) pills.style.display = 'none';
            card.dataset.collapsed = 'true';

            // Wrap badge + toggle button in a container for right alignment
            var headerDiv = card.querySelector('.board-card-header');
            var badge = headerDiv.querySelector('.badge');
            var rightGroup = document.createElement('div');
            rightGroup.style.cssText = 'display:flex;align-items:center;gap:0.3rem';
            if (badge) {
                badge.parentNode.removeChild(badge);
                rightGroup.appendChild(badge);
            }
            var toggleBtn = document.createElement('button');
            toggleBtn.className = 'step-toggle-btn';
            toggleBtn.textContent = '+';
            toggleBtn.title = 'Expand';
            rightGroup.appendChild(toggleBtn);
            headerDiv.appendChild(rightGroup);
            headerDiv.style.marginBottom = '0';

            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var o = card.querySelector('.step-output');
                var p = card.querySelector('.step-tool-pills');
                var isCollapsed = card.dataset.collapsed === 'true';
                if (o) o.style.display = isCollapsed ? '' : 'none';
                if (p) p.style.display = isCollapsed ? '' : 'none';
                card.dataset.collapsed = isCollapsed ? 'false' : 'true';
                toggleBtn.textContent = isCollapsed ? '\u2212' : '+';
                toggleBtn.title = isCollapsed ? 'Collapse' : 'Expand';
                headerDiv.style.marginBottom = isCollapsed ? '' : '0';
            });
        }
    });

    // Proofread buttons
    document.querySelectorAll('.proofread-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var pieceId = btn.dataset.pieceId;
            var bodyEl = document.getElementById('piece-body-' + pieceId);
            openContentModal({
                mode: 'proofread',
                pieceId: pieceId,
                platform: bodyEl ? bodyEl.dataset.platform : '',
                format: bodyEl ? bodyEl.dataset.format : '',
                basePath: basePath
            });
        });
    });

    // Improve buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.piece-improve-btn');
        if (!btn) return;
        var pieceId = btn.dataset.pieceId;
        if (!pieceId) return;
        var bodyEl = document.getElementById('piece-body-' + pieceId);
        openContentModal({
            mode: 'improve',
            pieceId: pieceId,
            platform: bodyEl ? bodyEl.dataset.platform : '',
            format: bodyEl ? bodyEl.dataset.format : '',
            basePath: basePath
        });
    });
}

function makeSubcard(title, contentEl) {
    var card = document.createElement('div');
    card.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:0.6rem 0.75rem;margin-bottom:0.5rem;background:#fff';
    if (title) {
        var h = document.createElement('div');
        h.style.cssText = 'font-weight:600;font-size:0.8rem;color:#374151;margin-bottom:0.35rem';
        h.textContent = title;
        card.appendChild(h);
    }
    card.appendChild(contentEl);
    return card;
}

function renderSourcesSubcard(sources) {
    var wrapper = document.createElement('div');
    var list = document.createElement('ul');
    list.style.cssText = 'font-size:0.8rem;padding-left:1.2rem;margin:0';
    sources.forEach(function(s) {
        var li = document.createElement('li');
        li.style.marginBottom = '0.4rem';
        var a = document.createElement('a');
        a.href = s.url;
        a.textContent = s.title || s.url;
        a.target = '_blank';
        a.style.fontWeight = '600';
        li.appendChild(a);
        if (s.date) {
            var dateSpan = document.createElement('span');
            dateSpan.textContent = ' (' + s.date + ')';
            dateSpan.style.color = '#888';
            li.appendChild(dateSpan);
        }
        if (s.summary) {
            var sumDiv = document.createElement('div');
            sumDiv.textContent = s.summary;
            sumDiv.style.color = '#555';
            li.appendChild(sumDiv);
        }
        list.appendChild(li);
    });
    wrapper.appendChild(list);
    return makeSubcard('Sources (' + sources.length + ')', wrapper);
}

function renderMarkdown(text) {
    var div = document.createElement('div');
    div.className = 'markdown-body';
    div.style.fontSize = '0.85rem';
    try {
        div.innerHTML = marked.parse(text.replace(/\\n/g, '\n'), { breaks: false, gfm: true });
    } catch(e) {
        div.textContent = text;
    }
    return div;
}

function renderStepOutput(el, typeName, data) {
    el.textContent = '';
    el.style.whiteSpace = 'normal';

    if (typeName === 'Researcher') {
        if (data.brief) el.appendChild(makeSubcard('Research Brief', renderMarkdown(data.brief)));
        if (data.sources && data.sources.length > 0) el.appendChild(renderSourcesSubcard(data.sources));
    } else if (typeName === 'Brand Enricher') {
        if (data.enriched_brief) el.appendChild(makeSubcard('Enriched Brief', renderMarkdown(data.enriched_brief)));
        if (data.sources && data.sources.length > 0) el.appendChild(renderSourcesSubcard(data.sources));
    } else if (typeName === 'Tone Analyzer') {
        if (data.tone_guide) el.appendChild(makeSubcard('Tone Guide', renderMarkdown(data.tone_guide)));
        if (data.posts && data.posts.length > 0) {
            var list = document.createElement('ul');
            list.style.cssText = 'font-size:0.8rem;padding-left:1.2rem;margin:0';
            data.posts.forEach(function(p) {
                var li = document.createElement('li');
                li.style.marginBottom = '0.3rem';
                var a = document.createElement('a');
                a.href = p.url;
                a.textContent = p.title || p.url;
                a.target = '_blank';
                li.appendChild(a);
                list.appendChild(li);
            });
            var wrapper = document.createElement('div');
            wrapper.appendChild(list);
            el.appendChild(makeSubcard('Posts Analyzed (' + data.posts.length + ')', wrapper));
        }
    } else if (typeName === 'Fact-Checker') {
        // Issues subcard
        var issuesContent = document.createElement('div');
        if (data.issues_found && data.issues_found.length > 0) {
            data.issues_found.forEach(function(issue) {
                var row = document.createElement('div');
                row.style.cssText = 'margin-bottom:0.4rem;font-size:0.8rem';
                var claim = document.createElement('strong');
                claim.textContent = issue.claim;
                row.appendChild(claim);
                if (issue.problem) {
                    var prob = document.createElement('div');
                    prob.textContent = issue.problem;
                    prob.style.color = '#dc2626';
                    row.appendChild(prob);
                }
                if (issue.resolution) {
                    var res = document.createElement('div');
                    res.textContent = issue.resolution;
                    res.style.color = '#059669';
                    row.appendChild(res);
                }
                issuesContent.appendChild(row);
            });
            el.appendChild(makeSubcard('Issues (' + data.issues_found.length + ')', issuesContent));
        } else {
            var ok = document.createElement('div');
            ok.textContent = 'No issues found.';
            ok.style.cssText = 'color:#059669;font-weight:600;font-size:0.85rem';
            el.appendChild(makeSubcard('Issues', ok));
        }
        if (data.enriched_brief) el.appendChild(makeSubcard('Enriched Brief', renderMarkdown(data.enriched_brief)));
        if (data.sources && data.sources.length > 0) el.appendChild(renderSourcesSubcard(data.sources));
    } else if (typeName === 'Editor') {
        // Angle subcard
        if (data.angle) {
            var angleDiv = document.createElement('div');
            angleDiv.style.cssText = 'font-size:0.85rem';
            angleDiv.textContent = data.angle;
            el.appendChild(makeSubcard('Angle', angleDiv));
        }
        // Sections subcard
        if (data.sections && data.sections.length > 0) {
            var sectionsContent = document.createElement('div');
            data.sections.forEach(function(sec) {
                var row = document.createElement('div');
                row.style.cssText = 'margin-bottom:0.5rem;padding:0.4rem;background:#f9fafb;border-radius:4px;font-size:0.8rem';
                var heading = document.createElement('strong');
                heading.textContent = sec.heading;
                if (sec.framework_beat) heading.textContent += ' (' + sec.framework_beat + ')';
                row.appendChild(heading);
                if (sec.key_points && sec.key_points.length > 0) {
                    var ul = document.createElement('ul');
                    ul.style.cssText = 'margin:0.25rem 0 0;padding-left:1rem';
                    sec.key_points.forEach(function(pt) {
                        var li = document.createElement('li');
                        li.textContent = pt;
                        ul.appendChild(li);
                    });
                    row.appendChild(ul);
                }
                if (sec.editorial_notes) {
                    var note = document.createElement('div');
                    note.textContent = sec.editorial_notes;
                    note.style.cssText = 'color:#6b7280;font-style:italic;margin-top:0.25rem';
                    row.appendChild(note);
                }
                sectionsContent.appendChild(row);
            });
            el.appendChild(makeSubcard('Sections (' + data.sections.length + ')', sectionsContent));
        }
        // Conclusion subcard
        if (data.conclusion_strategy) {
            var concDiv = document.createElement('div');
            concDiv.style.cssText = 'font-size:0.85rem';
            concDiv.textContent = data.conclusion_strategy;
            el.appendChild(makeSubcard('Conclusion', concDiv));
        }
    } else {
        el.textContent = JSON.stringify(data, null, 2);
        el.style.whiteSpace = 'pre-wrap';
        el.style.fontSize = '0.8rem';
    }
}


// --- Production board ---

function initProductionBoard(projectID, runID) {
    var basePath = '/projects/' + projectID + '/pipeline/' + runID;
    var board = document.getElementById('production-board');
    var nextPieceID = parseInt(board.dataset.nextPieceId) || 0;

    // Helper: connect SSE stream to an element
    function streamToElement(url, el, onDone) {
        el.textContent = '';
        var thinkingEl = null;
        var thinkingPre = null;
        var contentStarted = false;
        var source = new EventSource(url);
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            if (d.type === 'thinking') {
                if (!thinkingEl) {
                    thinkingEl = document.createElement('details');
                    thinkingEl.className = 'thinking-details';
                    thinkingEl.setAttribute('open', '');
                    var summary = document.createElement('summary');
                    summary.textContent = 'Thinking...';
                    thinkingEl.appendChild(summary);
                    thinkingPre = document.createElement('pre');
                    thinkingEl.appendChild(thinkingPre);
                    el.parentNode.insertBefore(thinkingEl, el);
                }
                thinkingPre.textContent += d.chunk;
                thinkingPre.scrollTop = thinkingPre.scrollHeight;
            } else if (d.type === 'chunk') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                el.textContent += d.chunk;
                el.scrollTop = el.scrollHeight;
            } else if (d.type === 'tool_start') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                el.textContent += '\n[' + d.summary + '...]\n';
            } else if (d.type === 'content_written') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                renderContentBody(el, d.platform, d.format, JSON.stringify(d.data));
            } else if (d.type === 'done') {
                source.close();
                if (onDone) onDone();
            } else if (d.type === 'error') {
                source.close();
                el.textContent += '\nError: ' + d.error;
            }
        };
        source.onerror = function() {
            source.close();
        };
        return source;
    }

    // Plan generation
    var genPlanBtn = document.getElementById('generate-plan-btn');
    if (genPlanBtn) {
        genPlanBtn.addEventListener('click', function() {
            genPlanBtn.disabled = true;
            genPlanBtn.textContent = 'Generating...';
            var planBody = document.getElementById('plan-body');
            streamToElement(basePath + '/stream/plan', planBody, function() {
                window.location.reload();
            });
        });
    }

    // Piece generation
    function streamPiece(pieceId) {
        var bodyEl = document.getElementById('piece-body-' + pieceId);
        var card = bodyEl.closest('.board-card');
        card.className = card.className.replace(/board-card-(pending|rejected)/g, '') + ' board-card-generating';
        bodyEl.classList.remove('collapsed');

        streamToElement(basePath + '/stream/piece/' + pieceId, bodyEl, function() {
            window.location.reload();
        });
    }

    // Event delegation for piece buttons
    board.addEventListener('click', function(e) {
        var btn = e.target;

        // Generate button
        if (btn.classList.contains('piece-generate-btn')) {
            var pieceId = btn.dataset.pieceId;
            btn.disabled = true;
            btn.textContent = 'Generating...';
            streamPiece(pieceId);
            return;
        }

        // Approve button — POST then reload
        if (btn.classList.contains('piece-approve-btn')) {
            var pieceId = btn.dataset.pieceId;
            btn.disabled = true;
            btn.textContent = 'Approving...';
            fetch(basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
                .then(function() { window.location.reload(); })
                .catch(function() { window.location.reload(); });
            return;
        }

        // Reject button
        if (btn.classList.contains('piece-reject-btn')) {
            var pieceId = btn.dataset.pieceId;
            var reason = prompt('Why should this be rejected?');
            if (reason === null) return;
            fetch(basePath + '/piece/' + pieceId + '/reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'reason=' + encodeURIComponent(reason)
            }).then(function() {
                window.location.reload();
            });
            return;
        }

        // Improve button — open content modal in improve mode
        if (btn.classList.contains('piece-improve-btn')) {
            var pieceId = btn.dataset.pieceId;
            var bodyEl = document.getElementById('piece-body-' + pieceId);
            openContentModal({
                mode: 'improve',
                pieceId: pieceId,
                platform: bodyEl ? bodyEl.dataset.platform : '',
                format: bodyEl ? bodyEl.dataset.format : '',
                basePath: basePath
            });
            return;
        }
    });

    // Render plan card as human-readable
    var planBody = document.getElementById('plan-body');
    if (planBody) {
        renderPlan(planBody);
    }

    // Render existing content bodies with type-specific renderers
    document.querySelectorAll('.board-card-body').forEach(function(el) {
        var text = el.textContent.trim();
        if (text && el.dataset.platform) {
            renderContentBody(el, el.dataset.platform, el.dataset.format, text);
        }
    });

    // Proofread buttons — open content modal in proofread mode
    document.querySelectorAll('.proofread-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var pieceId = btn.dataset.pieceId;
            var bodyEl = document.getElementById('piece-body-' + pieceId);
            openContentModal({
                mode: 'proofread',
                pieceId: pieceId,
                platform: bodyEl ? bodyEl.dataset.platform : '',
                format: bodyEl ? bodyEl.dataset.format : '',
                basePath: basePath
            });
        });
    });
}
