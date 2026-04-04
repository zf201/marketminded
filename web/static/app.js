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
        acceptBtn.className = 'btn btn-success btn-sm';
        acceptBtn.textContent = 'Accept';
        acceptBtn.onclick = function() {
            fetch('/projects/' + projectID + '/profile/sections/' + section, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(content)
            }).then(function() {
                block.className = 'proposal-block proposal-block-accepted';
                actions.remove();
                var badge = document.createElement('div');
                badge.className = 'text-success font-semibold text-sm';
                badge.textContent = 'Accepted';
                block.appendChild(badge);
                // Update card on the right
                var card = document.getElementById('card-' + section);
                if (card) {
                    var cardContent = card.querySelector('.profile-card-content');
                    cardContent.textContent = '';
                    var p = document.createElement('p');
                    p.className = 'whitespace-pre-wrap';
                    p.textContent = content;
                    cardContent.appendChild(p);
                    card.classList.remove('profile-card-empty');
                }
            });
        };

        var rejectBtn = document.createElement('button');
        rejectBtn.className = 'btn btn-ghost btn-sm';
        rejectBtn.textContent = 'Reject';
        rejectBtn.onclick = function() {
            block.className = 'proposal-block proposal-block-rejected';
            actions.remove();
            var badge = document.createElement('div');
            badge.className = 'text-neutral font-semibold text-sm';
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
        ta.className = 'textarea textarea-bordered w-full text-sm mb-2';
        ta.value = currentText;

        var saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-primary btn-sm';
        saveBtn.textContent = 'Save';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-ghost btn-sm ml-2';
        cancelBtn.textContent = 'Cancel';

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
                np.className = 'whitespace-pre-wrap';
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
            np.className = 'whitespace-pre-wrap';
            if (currentText) {
                np.textContent = currentText;
            } else {
                np.className = 'opacity-60 italic';
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
        bodyEl.className = 'whitespace-pre-wrap';
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
        aBody.className = 'whitespace-pre-wrap';
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
    var profilePage = document.getElementById('profile-page');
    if (profilePage) {
        initProfilePage(profilePage.dataset.projectId);
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

function initProfilePage(projectId) {
    var activeSection = null;

    // Render markdown content and set up expand/collapse
    document.querySelectorAll('.profile-md-content').forEach(function(el) {
        var md = el.textContent;
        if (md && typeof renderMarkdown === 'function') {
            var rendered = renderMarkdown(md);
            el.textContent = '';
            while (rendered.firstChild) el.appendChild(rendered.firstChild);
            el.classList.add('markdown-body');
        }
    });

    document.querySelectorAll('.profile-expand-btn').forEach(function(btn) {
        var section = btn.dataset.section;
        var preview = document.querySelector('.profile-content-preview[data-section="' + section + '"]');
        var fade = preview ? preview.querySelector('.profile-fade') : null;
        var expanded = false;

        // Hide expand button if content is short enough
        if (preview && preview.scrollHeight <= preview.offsetHeight + 4) {
            btn.classList.add('hidden');
            if (fade) fade.classList.add('hidden');
        }

        btn.addEventListener('click', function() {
            expanded = !expanded;
            if (expanded) {
                preview.style.maxHeight = 'none';
                if (fade) fade.classList.add('hidden');
                btn.textContent = 'Show less';
            } else {
                preview.style.maxHeight = '';
                if (fade) fade.classList.remove('hidden');
                btn.textContent = 'Show more';
            }
        });
    });

    // --- Add Context modal ---
    document.querySelectorAll('.profile-context-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeSection = btn.dataset.section;
            var modal = document.getElementById('context-modal');
            var container = document.getElementById('context-urls-container');
            var title = document.getElementById('context-modal-title');
            var notesArea = document.getElementById('context-notes');
            title.textContent = btn.dataset.title + ' — Edit Context';
            container.textContent = '';
            notesArea.value = '';

            fetch('/projects/' + projectId + '/profile/' + activeSection + '/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var urls = data.urls || [];
                    if (urls.length === 0) urls = [{url: '', notes: ''}];
                    urls.forEach(function(u) { addContextURLRow(container, u.url || '', u.notes || ''); });
                    notesArea.value = data.notes || '';
                });
            modal.showModal();
        });
    });

    document.getElementById('context-add-url-btn').addEventListener('click', function() {
        addContextURLRow(document.getElementById('context-urls-container'), '', '');
    });

    document.getElementById('context-save-btn').addEventListener('click', function() {
        var rows = document.getElementById('context-urls-container').querySelectorAll('.source-url-row');
        var urls = [];
        rows.forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            var url = inputs[0].value.trim();
            if (url) urls.push({url: url, notes: inputs[1].value.trim()});
        });
        var notes = document.getElementById('context-notes').value.trim();
        fetch('/projects/' + projectId + '/profile/' + activeSection + '/save-context', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({urls: urls, notes: notes})
        }).then(function() { location.reload(); });
    });

    document.getElementById('context-cancel-btn').addEventListener('click', function() {
        document.getElementById('context-modal').close();
    });

    // --- Build modal ---
    document.querySelectorAll('.profile-build-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeSection = btn.dataset.section;
            var modal = document.getElementById('build-modal');
            var title = document.getElementById('build-modal-title');
            var summary = document.getElementById('build-context-summary');
            var actions = document.getElementById('build-actions');
            var textarea = document.getElementById('build-content');
            var resultActions = document.getElementById('build-result-actions');
            var genBtn = document.getElementById('build-generate-btn');

            title.textContent = btn.dataset.title + ' — Build';
            textarea.value = '';
            textarea.classList.add('hidden');
            resultActions.classList.add('hidden');
            actions.classList.remove('hidden');
            genBtn.disabled = false;
            genBtn.textContent = 'Generate';

            // Show context summary
            summary.textContent = '';
            fetch('/projects/' + projectId + '/profile/' + activeSection + '/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var urls = data.urls || [];
                    if (urls.length > 0) {
                        var h = document.createElement('p');
                        h.className = 'text-sm font-semibold mb-1';
                        h.textContent = 'Source URLs to fetch:';
                        summary.appendChild(h);
                        urls.forEach(function(u) {
                            var p = document.createElement('p');
                            p.className = 'text-xs text-zinc-400 font-mono';
                            p.textContent = u.url + (u.notes ? ' — ' + u.notes : '');
                            summary.appendChild(p);
                        });
                    }
                    if (data.notes) {
                        var nh = document.createElement('p');
                        nh.className = 'text-sm font-semibold mt-2 mb-1';
                        nh.textContent = 'Additional notes:';
                        summary.appendChild(nh);
                        var np = document.createElement('p');
                        np.className = 'text-xs text-zinc-400';
                        np.textContent = data.notes;
                        summary.appendChild(np);
                    }
                    if (data.content) {
                        var note = document.createElement('p');
                        note.className = 'text-sm text-zinc-500 mt-2';
                        note.textContent = 'Existing content will be used as a base to improve upon.';
                        summary.appendChild(note);
                    }
                    if (!urls.length && !data.content && !data.notes) {
                        var empty = document.createElement('p');
                        empty.className = 'text-sm text-zinc-500';
                        empty.textContent = 'No context available. Add source URLs first for better results.';
                        summary.appendChild(empty);
                    }
                });

            modal.showModal();
        });
    });

    var buildResultData = null; // stores structured result from tool call

    document.getElementById('build-generate-btn').addEventListener('click', function() {
        var genBtn = document.getElementById('build-generate-btn');
        var actions = document.getElementById('build-actions');
        var textarea = document.getElementById('build-content');
        var resultActions = document.getElementById('build-result-actions');

        genBtn.disabled = true;
        genBtn.textContent = 'Generating...';
        textarea.value = '';
        textarea.classList.remove('hidden');
        textarea.readOnly = true;
        buildResultData = null;
        document.getElementById('build-url-guide').classList.add('hidden');

        var source = new EventSource('/projects/' + projectId + '/profile/' + activeSection + '/generate');
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            switch (d.type) {
            case 'status':
                genBtn.textContent = d.status;
                break;
            case 'chunk':
                textarea.value += d.chunk;
                textarea.scrollTop = textarea.scrollHeight;
                break;
            case 'result':
                // Structured result from tool call (P&P with URLs)
                var parsed = typeof d.data === 'string' ? JSON.parse(d.data) : d.data;
                buildResultData = parsed;
                textarea.value = parsed.content || '';
                textarea.scrollTop = 0;
                if (parsed.url_guide) {
                    var guideDiv = document.getElementById('build-url-guide');
                    var guidePre = document.getElementById('build-url-guide-content');
                    guidePre.textContent = parsed.url_guide;
                    guideDiv.classList.remove('hidden');
                }
                break;
            case 'error':
                source.close();
                genBtn.disabled = false;
                genBtn.textContent = 'Retry';
                break;
            case 'done':
                source.close();
                actions.classList.add('hidden');
                textarea.readOnly = false;
                resultActions.classList.remove('hidden');
                break;
            }
        };
        source.onerror = function() {
            source.close();
            genBtn.disabled = false;
            genBtn.textContent = 'Retry';
        };
    });

    document.getElementById('build-save-btn').addEventListener('click', function() {
        var content = document.getElementById('build-content').value;
        var payload = {content: content};
        // Include url_guide if we got a structured result
        if (buildResultData && buildResultData.url_guide) {
            payload.url_guide = buildResultData.url_guide;
        }
        fetch('/projects/' + projectId + '/profile/' + activeSection + '/save', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        }).then(function() { location.reload(); });
    });

    document.getElementById('build-discard-btn').addEventListener('click', function() {
        document.getElementById('build-modal').close();
    });

    // --- Edit modal ---
    document.querySelectorAll('.profile-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeSection = btn.dataset.section;
            var modal = document.getElementById('edit-modal');
            var title = document.getElementById('edit-modal-title');
            var textarea = document.getElementById('edit-content');

            title.textContent = btn.dataset.title + ' — Edit';

            fetch('/projects/' + projectId + '/profile/' + activeSection + '/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    textarea.value = data.content || '';
                });

            modal.showModal();
        });
    });

    document.getElementById('edit-save-btn').addEventListener('click', function() {
        var content = document.getElementById('edit-content').value;
        fetch('/projects/' + projectId + '/profile/' + activeSection + '/save', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({content: content})
        }).then(function() { location.reload(); });
    });

    document.getElementById('edit-cancel-btn').addEventListener('click', function() {
        document.getElementById('edit-modal').close();
    });

    // --- History (sub-modal from Edit) ---
    document.getElementById('edit-history-btn').addEventListener('click', function() {
        var editModal = document.getElementById('edit-modal');
        var histModal = document.getElementById('history-modal');
        var content = document.getElementById('history-content');
        editModal.close();

        content.textContent = '';
        var loading = document.createElement('p');
        loading.className = 'text-zinc-500';
        loading.textContent = 'Loading...';
        content.appendChild(loading);
        histModal.showModal();

        fetch('/projects/' + projectId + '/profile/' + activeSection + '/versions')
            .then(function(r) { return r.json(); })
            .then(function(versions) {
                content.textContent = '';
                if (!versions || versions.length === 0) {
                    var empty = document.createElement('p');
                    empty.className = 'text-zinc-500';
                    empty.textContent = 'No previous versions.';
                    content.appendChild(empty);
                    return;
                }
                versions.forEach(function(v, i) {
                    var details = document.createElement('details');
                    details.className = 'bg-zinc-800/50 border border-zinc-800 rounded-lg mb-2';
                    if (i === 0) details.open = true;

                    var summary = document.createElement('summary');
                    summary.className = 'px-4 py-3 text-sm font-medium text-zinc-300 cursor-pointer hover:text-zinc-100';
                    summary.textContent = v.created_at;
                    details.appendChild(summary);

                    var body = document.createElement('div');
                    body.className = 'px-4 pb-3';

                    var pre = document.createElement('pre');
                    pre.className = 'whitespace-pre-wrap text-xs max-h-48 overflow-y-auto text-zinc-400';
                    pre.textContent = v.content;
                    body.appendChild(pre);

                    var restoreBtn = document.createElement('button');
                    restoreBtn.type = 'button';
                    restoreBtn.className = 'btn btn-ghost btn-sm mt-2';
                    restoreBtn.textContent = 'Restore';
                    restoreBtn.addEventListener('click', function() {
                        document.getElementById('edit-content').value = v.content;
                        histModal.close();
                        editModal.showModal();
                    });
                    body.appendChild(restoreBtn);

                    details.appendChild(body);
                    content.appendChild(details);
                });
            });
    });

    document.getElementById('history-back-btn').addEventListener('click', function() {
        document.getElementById('history-modal').close();
        document.getElementById('edit-modal').showModal();
    });

    // --- Audience Context modal ---
    document.querySelectorAll('.audience-context-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('audience-context-modal');
            var locInput = document.getElementById('audience-location');
            var notesArea = document.getElementById('audience-notes');
            locInput.value = '';
            notesArea.value = '';

            fetch('/projects/' + projectId + '/profile/audience/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    locInput.value = data.location || '';
                    notesArea.value = data.notes || '';
                });
            modal.showModal();
        });
    });

    document.getElementById('audience-context-save-btn').addEventListener('click', function() {
        var loc = document.getElementById('audience-location').value.trim();
        var notes = document.getElementById('audience-notes').value.trim();
        fetch('/projects/' + projectId + '/profile/audience/save-context', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({location: loc, notes: notes})
        }).then(function() { window.location.reload(); });
    });

    document.getElementById('audience-context-cancel-btn').addEventListener('click', function() {
        document.getElementById('audience-context-modal').close();
    });

    // --- Audience Build modal ---
    var audienceGeneratedPersonas = [];

    document.querySelectorAll('.audience-build-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('audience-build-modal');
            var contextDiv = document.getElementById('audience-build-context');
            var actions = document.getElementById('audience-build-actions');
            var results = document.getElementById('audience-build-results');
            var resultActions = document.getElementById('audience-build-result-actions');
            var genBtn = document.getElementById('audience-generate-btn');
            var container = document.getElementById('audience-personas-container');

            contextDiv.textContent = '';
            container.textContent = '';
            results.classList.add('hidden');
            resultActions.classList.add('hidden');
            actions.classList.remove('hidden');
            genBtn.disabled = false;
            genBtn.textContent = 'Generate';
            audienceGeneratedPersonas = [];

            // Show context summary
            fetch('/projects/' + projectId + '/profile/audience/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.location) {
                        var p = document.createElement('p');
                        p.className = 'text-sm';
                        p.textContent = 'Location: ' + data.location;
                        contextDiv.appendChild(p);
                    }
                    if (data.notes) {
                        var np = document.createElement('p');
                        np.className = 'text-sm text-zinc-400 mt-1';
                        np.textContent = 'Notes: ' + data.notes;
                        contextDiv.appendChild(np);
                    }
                    if (!data.location && !data.notes) {
                        var empty = document.createElement('p');
                        empty.className = 'text-sm text-zinc-500';
                        empty.textContent = 'No audience context set. Add context for better results.';
                        contextDiv.appendChild(empty);
                    }
                });

            modal.showModal();
        });
    });

    document.getElementById('audience-generate-btn').addEventListener('click', function() {
        var genBtn = document.getElementById('audience-generate-btn');
        var actions = document.getElementById('audience-build-actions');
        var results = document.getElementById('audience-build-results');
        var resultActions = document.getElementById('audience-build-result-actions');
        var container = document.getElementById('audience-personas-container');

        genBtn.disabled = true;
        genBtn.textContent = 'Generating...';
        container.textContent = '';
        audienceGeneratedPersonas = [];

        var source = new EventSource('/projects/' + projectId + '/profile/audience/generate');
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            switch (d.type) {
            case 'status':
                genBtn.textContent = d.status;
                break;
            case 'personas':
                var parsed = typeof d.data === 'string' ? JSON.parse(d.data) : d.data;
                var personas = parsed.personas || [];
                audienceGeneratedPersonas = personas;
                container.textContent = '';
                personas.forEach(function(persona, idx) {
                    var card = buildAudienceResultCard(persona, idx);
                    container.appendChild(card);
                });
                break;
            case 'error':
                source.close();
                genBtn.disabled = false;
                genBtn.textContent = 'Retry';
                break;
            case 'done':
                source.close();
                if (audienceGeneratedPersonas.length > 0) {
                    actions.classList.add('hidden');
                    results.classList.remove('hidden');
                    resultActions.classList.remove('hidden');
                } else {
                    genBtn.disabled = false;
                    genBtn.textContent = 'Retry';
                }
                break;
            }
        };
        source.onerror = function() {
            source.close();
            genBtn.disabled = false;
            genBtn.textContent = 'Retry';
        };
    });

    function buildAudienceResultCard(persona, idx) {
        var statusColors = {new: 'success', updated: 'warning', unchanged: 'ghost', removed: 'error'};
        var card = document.createElement('div');
        card.className = 'card bg-zinc-800/50 border border-zinc-800';
        card.dataset.idx = idx;

        var body = document.createElement('div');
        body.className = 'card-body p-3';

        var header = document.createElement('div');
        header.className = 'flex justify-between items-center';

        var left = document.createElement('div');
        left.className = 'flex items-center gap-2';

        var label = document.createElement('strong');
        label.textContent = persona.label || '';
        left.appendChild(label);

        var badge = document.createElement('span');
        badge.className = 'badge badge-sm badge-' + (statusColors[persona.status] || 'ghost');
        badge.textContent = persona.status || '';
        left.appendChild(badge);

        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'btn btn-ghost btn-xs persona-toggle-btn';
        toggleBtn.dataset.accepted = 'true';
        toggleBtn.textContent = 'Accepted';
        toggleBtn.addEventListener('click', function() {
            var accepted = toggleBtn.dataset.accepted === 'true';
            toggleBtn.dataset.accepted = accepted ? 'false' : 'true';
            toggleBtn.textContent = accepted ? 'Rejected' : 'Accepted';
            card.classList.toggle('opacity-50', accepted);
        });

        header.appendChild(left);
        header.appendChild(toggleBtn);
        body.appendChild(header);

        // All fields
        var fields = [
            {label: 'Description', value: persona.description, required: true},
            {label: 'Pain points', value: persona.pain_points, required: true},
            {label: 'Push', value: persona.push, required: true},
            {label: 'Pull', value: persona.pull, required: true},
            {label: 'Anxiety', value: persona.anxiety, required: true},
            {label: 'Habit', value: persona.habit, required: true},
            {label: 'Role', value: persona.role},
            {label: 'Demographics', value: persona.demographics},
            {label: 'Company', value: persona.company_info},
            {label: 'Content habits', value: persona.content_habits},
            {label: 'Buying triggers', value: persona.buying_triggers}
        ];

        var fieldsDiv = document.createElement('div');
        fieldsDiv.className = 'mt-2 space-y-1';
        fields.forEach(function(f) {
            if (!f.value) return;
            var row = document.createElement('div');
            row.className = 'text-xs';
            var lbl = document.createElement('span');
            lbl.className = 'font-semibold text-zinc-500';
            lbl.textContent = f.label + ': ';
            var val = document.createElement('span');
            val.className = 'text-zinc-300';
            val.textContent = f.value;
            row.appendChild(lbl);
            row.appendChild(val);
            fieldsDiv.appendChild(row);
        });
        body.appendChild(fieldsDiv);

        card.appendChild(body);
        return card;
    }

    document.getElementById('audience-save-generated-btn').addEventListener('click', function() {
        var container = document.getElementById('audience-personas-container');
        var cards = container.querySelectorAll('.card');
        var accepted = [];
        cards.forEach(function(card) {
            var idx = parseInt(card.dataset.idx);
            var toggleBtn = card.querySelector('.persona-toggle-btn');
            if (toggleBtn && toggleBtn.dataset.accepted === 'true') {
                accepted.push(audienceGeneratedPersonas[idx]);
            }
        });
        fetch('/projects/' + projectId + '/profile/audience/save-generated', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({personas: accepted})
        }).then(function() { window.location.reload(); });
    });

    document.getElementById('audience-discard-btn').addEventListener('click', function() {
        document.getElementById('audience-build-modal').close();
    });

    // --- Audience Edit Persona modal ---
    var editingPersonaId = null;

    document.querySelectorAll('.audience-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            editingPersonaId = parseInt(btn.dataset.personaId);
            var modal = document.getElementById('audience-edit-modal');
            var title = document.getElementById('audience-edit-title');
            var fieldsContainer = document.getElementById('audience-edit-fields');

            title.textContent = 'Edit Persona';
            fieldsContainer.textContent = '';

            fetch('/projects/' + projectId + '/profile/audience/personas')
                .then(function(r) { return r.json(); })
                .then(function(personas) {
                    var persona = null;
                    for (var i = 0; i < personas.length; i++) {
                        if (personas[i].ID === editingPersonaId) {
                            persona = personas[i];
                            break;
                        }
                    }
                    if (!persona) return;

                    title.textContent = 'Edit: ' + persona.Label;

                    var mandatoryFields = [
                        {key: 'Label', label: 'Label', type: 'input'},
                        {key: 'Description', label: 'Description', type: 'textarea', rows: 4},
                        {key: 'PainPoints', label: 'Pain Points', type: 'textarea', rows: 3},
                        {key: 'Push', label: 'Push', type: 'textarea', rows: 2},
                        {key: 'Pull', label: 'Pull', type: 'textarea', rows: 2},
                        {key: 'Anxiety', label: 'Anxiety', type: 'textarea', rows: 2},
                        {key: 'Habit', label: 'Habit', type: 'textarea', rows: 2}
                    ];

                    var optionalFields = [
                        {key: 'Role', label: 'Role', type: 'input'},
                        {key: 'Demographics', label: 'Demographics', type: 'input'},
                        {key: 'CompanyInfo', label: 'Company Info', type: 'input'},
                        {key: 'ContentHabits', label: 'Content Habits', type: 'textarea', rows: 2},
                        {key: 'BuyingTriggers', label: 'Buying Triggers', type: 'textarea', rows: 2}
                    ];

                    mandatoryFields.forEach(function(f) {
                        fieldsContainer.appendChild(buildEditField(f, persona[f.key] || ''));
                    });

                    var optionalContainer = document.createElement('div');
                    optionalContainer.className = 'mt-2';
                    var hasHidden = false;

                    optionalFields.forEach(function(f) {
                        var wrapper = buildEditField(f, persona[f.key] || '');
                        if (!persona[f.key]) {
                            wrapper.classList.add('hidden');
                            wrapper.dataset.optional = 'true';
                            hasHidden = true;
                        }
                        optionalContainer.appendChild(wrapper);
                    });

                    fieldsContainer.appendChild(optionalContainer);

                    if (hasHidden) {
                        var addBtn = document.createElement('button');
                        addBtn.type = 'button';
                        addBtn.className = 'btn btn-ghost btn-sm mt-2';
                        addBtn.textContent = '+ Add field';
                        addBtn.addEventListener('click', function() {
                            var hidden = optionalContainer.querySelectorAll('[data-optional="true"].hidden');
                            if (hidden.length > 0) {
                                hidden[0].classList.remove('hidden');
                            }
                            if (optionalContainer.querySelectorAll('[data-optional="true"].hidden').length === 0) {
                                addBtn.classList.add('hidden');
                            }
                        });
                        fieldsContainer.appendChild(addBtn);
                    }
                });

            modal.showModal();
        });
    });

    function buildEditField(field, value) {
        var wrapper = document.createElement('div');
        wrapper.className = 'mb-3';

        var lbl = document.createElement('label');
        lbl.className = 'label';
        var span = document.createElement('span');
        span.className = 'label-text font-semibold text-sm';
        span.textContent = field.label;
        lbl.appendChild(span);
        wrapper.appendChild(lbl);

        var input;
        if (field.type === 'textarea') {
            input = document.createElement('textarea');
            input.className = 'textarea textarea-bordered w-full text-sm';
            input.rows = field.rows || 2;
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'input input-bordered w-full text-sm';
        }
        input.value = value;
        input.dataset.field = field.key;
        wrapper.appendChild(input);

        return wrapper;
    }

    document.getElementById('audience-edit-save-btn').addEventListener('click', function() {
        var fieldsContainer = document.getElementById('audience-edit-fields');
        var inputs = fieldsContainer.querySelectorAll('[data-field]');
        var data = {ID: editingPersonaId};
        inputs.forEach(function(input) {
            data[input.dataset.field] = input.value;
        });
        fetch('/projects/' + projectId + '/profile/audience/personas', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        }).then(function() { window.location.reload(); });
    });

    document.getElementById('audience-edit-cancel-btn').addEventListener('click', function() {
        document.getElementById('audience-edit-modal').close();
    });

    // --- Audience Delete ---
    document.querySelectorAll('.audience-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Delete this persona?')) return;
            var id = btn.dataset.personaId;
            fetch('/projects/' + projectId + '/profile/audience/personas/' + id, {
                method: 'DELETE'
            }).then(function() { window.location.reload(); });
        });
    });

    // --- Voice & Tone Context modal ---
    document.querySelectorAll('.vt-context-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('vt-context-modal');
            var blogContainer = document.getElementById('vt-blog-urls');
            var likedContainer = document.getElementById('vt-liked-articles');
            var inspirationContainer = document.getElementById('vt-inspiration');
            var notesArea = document.getElementById('vt-context-notes');

            blogContainer.textContent = '';
            likedContainer.textContent = '';
            inspirationContainer.textContent = '';
            notesArea.value = '';

            fetch('/projects/' + projectId + '/profile/voice_and_tone/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var blogs = data.blog_urls || [];
                    if (blogs.length === 0) blogs = [{url: '', notes: ''}];
                    blogs.forEach(function(u) { addContextURLRow(blogContainer, u.url || '', u.notes || ''); });

                    var liked = data.liked_articles || [];
                    if (liked.length === 0) liked = [{url: '', notes: ''}];
                    liked.forEach(function(u) { addContextURLRow(likedContainer, u.url || '', u.notes || ''); });

                    var insp = data.inspiration || [];
                    if (insp.length === 0) insp = [{url: '', notes: ''}];
                    insp.forEach(function(u) { addContextURLRow(inspirationContainer, u.url || '', u.notes || ''); });

                    notesArea.value = data.notes || '';
                });
            modal.showModal();
        });
    });

    document.querySelector('.vt-add-blog-url').addEventListener('click', function() {
        addContextURLRow(document.getElementById('vt-blog-urls'), '', '');
    });
    document.querySelector('.vt-add-liked-url').addEventListener('click', function() {
        addContextURLRow(document.getElementById('vt-liked-articles'), '', '');
    });
    document.querySelector('.vt-add-inspiration-url').addEventListener('click', function() {
        addContextURLRow(document.getElementById('vt-inspiration'), '', '');
    });

    function collectURLsFromContainer(container) {
        var rows = container.querySelectorAll('.source-url-row');
        var urls = [];
        rows.forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            var url = inputs[0].value.trim();
            if (url) urls.push({url: url, notes: inputs[1].value.trim()});
        });
        return urls;
    }

    document.getElementById('vt-context-save-btn').addEventListener('click', function() {
        var blogURLs = collectURLsFromContainer(document.getElementById('vt-blog-urls'));
        var likedArticles = collectURLsFromContainer(document.getElementById('vt-liked-articles'));
        var inspiration = collectURLsFromContainer(document.getElementById('vt-inspiration'));
        var notes = document.getElementById('vt-context-notes').value.trim();
        fetch('/projects/' + projectId + '/profile/voice_and_tone/save-context', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({blog_urls: blogURLs, liked_articles: likedArticles, inspiration: inspiration, notes: notes})
        }).then(function() { location.reload(); });
    });

    document.getElementById('vt-context-cancel-btn').addEventListener('click', function() {
        document.getElementById('vt-context-modal').close();
    });

    // --- Voice & Tone Build modal ---
    var vtGeneratedResult = null;

    document.querySelectorAll('.vt-build-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('vt-build-modal');
            var contextDiv = document.getElementById('vt-build-context');
            var actions = document.getElementById('vt-build-actions');
            var textarea = document.getElementById('vt-build-content');
            var resultActions = document.getElementById('vt-build-result-actions');
            var genBtn = document.getElementById('vt-generate-btn');

            contextDiv.textContent = '';
            textarea.value = '';
            textarea.classList.add('hidden');
            resultActions.classList.add('hidden');
            actions.classList.remove('hidden');
            genBtn.disabled = false;
            genBtn.textContent = 'Generate';
            vtGeneratedResult = null;

            // Show context summary
            fetch('/projects/' + projectId + '/profile/voice_and_tone/context')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var blogs = data.blog_urls || [];
                    var liked = data.liked_articles || [];
                    var insp = data.inspiration || [];
                    var hasContext = blogs.length > 0 || liked.length > 0 || insp.length > 0 || data.notes;

                    if (blogs.length > 0) {
                        var h = document.createElement('p');
                        h.className = 'text-sm font-semibold mb-1';
                        h.textContent = 'Blog URLs (' + blogs.length + '):';
                        contextDiv.appendChild(h);
                        blogs.forEach(function(u) {
                            var p = document.createElement('p');
                            p.className = 'text-xs text-zinc-400 font-mono';
                            p.textContent = u.url + (u.notes ? ' - ' + u.notes : '');
                            contextDiv.appendChild(p);
                        });
                    }
                    if (liked.length > 0) {
                        var h2 = document.createElement('p');
                        h2.className = 'text-sm font-semibold mt-2 mb-1';
                        h2.textContent = 'Liked Articles (' + liked.length + '):';
                        contextDiv.appendChild(h2);
                        liked.forEach(function(u) {
                            var p = document.createElement('p');
                            p.className = 'text-xs text-zinc-400 font-mono';
                            p.textContent = u.url + (u.notes ? ' - ' + u.notes : '');
                            contextDiv.appendChild(p);
                        });
                    }
                    if (insp.length > 0) {
                        var h3 = document.createElement('p');
                        h3.className = 'text-sm font-semibold mt-2 mb-1';
                        h3.textContent = 'Inspiration (' + insp.length + '):';
                        contextDiv.appendChild(h3);
                        insp.forEach(function(u) {
                            var p = document.createElement('p');
                            p.className = 'text-xs text-zinc-400 font-mono';
                            p.textContent = u.url + (u.notes ? ' - ' + u.notes : '');
                            contextDiv.appendChild(p);
                        });
                    }
                    if (data.notes) {
                        var nh = document.createElement('p');
                        nh.className = 'text-sm font-semibold mt-2 mb-1';
                        nh.textContent = 'Additional notes:';
                        contextDiv.appendChild(nh);
                        var np = document.createElement('p');
                        np.className = 'text-xs text-zinc-400';
                        np.textContent = data.notes;
                        contextDiv.appendChild(np);
                    }
                    if (!hasContext) {
                        var empty = document.createElement('p');
                        empty.className = 'text-sm text-zinc-500';
                        empty.textContent = 'No context available. Add blog URLs, liked articles, or inspiration sources for better results.';
                        contextDiv.appendChild(empty);
                    }
                });

            modal.showModal();
        });
    });

    document.getElementById('vt-generate-btn').addEventListener('click', function() {
        var genBtn = document.getElementById('vt-generate-btn');
        var actions = document.getElementById('vt-build-actions');
        var textarea = document.getElementById('vt-build-content');
        var resultActions = document.getElementById('vt-build-result-actions');

        genBtn.disabled = true;
        genBtn.textContent = 'Generating...';
        textarea.value = '';
        textarea.classList.remove('hidden');
        textarea.readOnly = true;
        vtGeneratedResult = null;

        var source = new EventSource('/projects/' + projectId + '/profile/voice_and_tone/generate');
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            switch (d.type) {
            case 'status':
                genBtn.textContent = d.status;
                break;
            case 'result':
                var parsed = typeof d.data === 'string' ? JSON.parse(d.data) : d.data;
                vtGeneratedResult = parsed;
                // Format into textarea with headers
                var text = '';
                if (parsed.voice_analysis) text += '## Voice Analysis\n' + parsed.voice_analysis + '\n\n';
                if (parsed.content_types) text += '## Content Types\n' + parsed.content_types + '\n\n';
                if (parsed.should_avoid) text += '## Should Avoid\n' + parsed.should_avoid + '\n\n';
                if (parsed.should_use) text += '## Should Use\n' + parsed.should_use + '\n\n';
                if (parsed.style_inspiration) text += '## Style Inspiration\n' + parsed.style_inspiration + '\n\n';
                textarea.value = text.trim();
                textarea.scrollTop = 0;
                break;
            case 'error':
                source.close();
                genBtn.disabled = false;
                genBtn.textContent = 'Retry';
                textarea.value = 'Error: ' + (d.error || 'Unknown error');
                break;
            case 'done':
                source.close();
                if (vtGeneratedResult) {
                    actions.classList.add('hidden');
                    textarea.readOnly = false;
                    resultActions.classList.remove('hidden');
                } else {
                    genBtn.disabled = false;
                    genBtn.textContent = 'Retry';
                }
                break;
            }
        };
        source.onerror = function() {
            source.close();
            genBtn.disabled = false;
            genBtn.textContent = 'Retry';
        };
    });

    document.getElementById('vt-save-generated-btn').addEventListener('click', function() {
        if (!vtGeneratedResult) return;
        fetch('/projects/' + projectId + '/profile/voice_and_tone/profile', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(vtGeneratedResult)
        }).then(function() { window.location.reload(); });
    });

    document.getElementById('vt-discard-btn').addEventListener('click', function() {
        document.getElementById('vt-build-modal').close();
    });

    // --- Voice & Tone Edit modal ---
    document.querySelectorAll('.vt-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById('vt-edit-modal');
            var fieldsContainer = document.getElementById('vt-edit-fields');
            fieldsContainer.textContent = '';

            fetch('/projects/' + projectId + '/profile/voice_and_tone/profile')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var fields = [
                        {key: 'voice_analysis', label: 'Voice Analysis', rows: 4},
                        {key: 'content_types', label: 'Content Types', rows: 4},
                        {key: 'should_avoid', label: 'Should Avoid', rows: 4},
                        {key: 'should_use', label: 'Should Use', rows: 4},
                        {key: 'style_inspiration', label: 'Style Inspiration', rows: 4}
                    ];
                    fields.forEach(function(f) {
                        var wrapper = document.createElement('div');
                        wrapper.className = 'mb-3';

                        var lbl = document.createElement('label');
                        lbl.className = 'label';
                        var span = document.createElement('span');
                        span.className = 'label-text font-semibold text-sm';
                        span.textContent = f.label;
                        lbl.appendChild(span);
                        wrapper.appendChild(lbl);

                        var textarea = document.createElement('textarea');
                        textarea.className = 'textarea textarea-bordered w-full text-sm';
                        textarea.rows = f.rows;
                        textarea.value = data[f.key] || '';
                        textarea.dataset.field = f.key;
                        wrapper.appendChild(textarea);

                        fieldsContainer.appendChild(wrapper);
                    });
                });

            modal.showModal();
        });
    });

    document.getElementById('vt-edit-save-btn').addEventListener('click', function() {
        var fieldsContainer = document.getElementById('vt-edit-fields');
        var inputs = fieldsContainer.querySelectorAll('[data-field]');
        var data = {};
        inputs.forEach(function(input) {
            data[input.dataset.field] = input.value;
        });
        fetch('/projects/' + projectId + '/profile/voice_and_tone/profile', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        }).then(function() { window.location.reload(); });
    });

    document.getElementById('vt-edit-cancel-btn').addEventListener('click', function() {
        document.getElementById('vt-edit-modal').close();
    });
}

function addContextURLRow(container, url, notes) {
    var row = document.createElement('div');
    row.className = 'flex gap-2 mb-2 source-url-row';

    var urlInput = document.createElement('input');
    urlInput.type = 'text';
    urlInput.value = url;
    urlInput.placeholder = 'https://example.com';
    urlInput.className = 'input input-bordered input-sm flex-1';

    var notesInput = document.createElement('input');
    notesInput.type = 'text';
    notesInput.value = notes;
    notesInput.placeholder = 'Usage notes...';
    notesInput.className = 'input input-bordered input-sm flex-1';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-ghost btn-sm btn-square';
    removeBtn.textContent = 'x';
    removeBtn.addEventListener('click', function() {
        var rows = container.querySelectorAll('.source-url-row');
        if (rows.length > 1) { row.remove(); }
        else { urlInput.value = ''; notesInput.value = ''; }
    });

    row.appendChild(urlInput);
    row.appendChild(notesInput);
    row.appendChild(removeBtn);
    container.appendChild(row);
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
        bodyEl.className = 'whitespace-pre-wrap';
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
    overlay.className = 'modal modal-open';

    var modal = document.createElement('div');
    modal.className = 'modal-box max-w-2xl flex flex-col max-h-[85vh]';

    var headerRow = document.createElement('div');
    headerRow.className = 'flex justify-between items-center mb-4';
    var titleEl = document.createElement('h3');
    titleEl.textContent = mode === 'proofread' ? 'Proofreading...' : 'Improve Content';
    headerRow.appendChild(titleEl);
    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-ghost btn-sm';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = function() {
        controller.abort();
        overlay.remove();
    };
    headerRow.appendChild(cancelBtn);
    modal.appendChild(headerRow);

    // Chat area (improve only)
    var chatArea = document.createElement('div');
    chatArea.className = 'flex flex-col flex-1 overflow-hidden';

    var messages = document.createElement('div');
    messages.className = 'overflow-y-auto flex-1 mb-3 max-h-52';
    chatArea.appendChild(messages);

    if (mode === 'improve') {
        var textarea = document.createElement('textarea');
        textarea.className = 'textarea textarea-bordered w-full text-sm mb-2';
        textarea.placeholder = 'What should be improved?';
        chatArea.appendChild(textarea);
        var sendBtn = document.createElement('button');
        sendBtn.className = 'btn btn-primary btn-sm';
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
            userDiv.className += ' text-sm';
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
            previewArea.classList.add('hidden');
            actions.classList.add('hidden');

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
                        if (accumulated && previewArea.classList.contains('hidden')) {
                            var assistDiv = document.createElement('div');
                            assistDiv.className = 'chat-msg chat-msg-assistant';
                            assistDiv.className += ' text-sm';
                            var aRole = document.createElement('div');
                            aRole.className = 'chat-msg-role';
                            aRole.textContent = 'assistant';
                            assistDiv.appendChild(aRole);
                            var aBody = document.createElement('div');
                            aBody.className = 'whitespace-pre-wrap';
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
                        errDiv.className = 'text-error text-sm';
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
    spinnerEl.className = 'text-center p-8 hidden';
    var spinnerIcon = document.createElement('span');
    spinnerIcon.className = 'loading loading-spinner loading-lg';
    spinnerEl.appendChild(spinnerIcon);
    var spinnerText = document.createElement('p');
    spinnerText.className = 'opacity-60 mt-4';
    spinnerText.textContent = 'Proofreading content...';
    spinnerEl.appendChild(spinnerText);
    modal.appendChild(spinnerEl);

    // Preview area — uses renderContentBody
    var previewArea = document.createElement('div');
    previewArea.className = 'overflow-y-auto flex-1 bg-zinc-800/50 p-3 rounded-lg mb-3 min-h-36 max-h-96 hidden';
    modal.appendChild(previewArea);

    // Actions
    var actions = document.createElement('div');
    actions.className = 'hidden gap-2';
    modal.appendChild(actions);

    // Cancel button is in the header row

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    var pendingContent = '';

    function showPreview(contentJSON) {
        previewArea.classList.remove('hidden');
        previewArea.textContent = '';
        renderContentBody(previewArea, platform, format, contentJSON);
        pendingContent = contentJSON;
        titleEl.textContent = mode === 'proofread' ? 'Proofread Complete' : 'New Version Ready';

        actions.textContent = '';
        actions.classList.remove('hidden'); actions.classList.add('flex');

        var acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn btn-success btn-sm';
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
        rejectBtn.className = 'btn btn-ghost btn-sm';
        rejectBtn.textContent = mode === 'improve' ? 'Try Again' : 'Reject';
        rejectBtn.onclick = function() {
            if (mode === 'improve') {
                // Back to chat
                previewArea.classList.add('hidden');
                actions.classList.add('hidden');
                titleEl.textContent = 'Improve Content';
                textarea.focus();
            } else {
                overlay.remove();
            }
        };
        actions.appendChild(rejectBtn);

        cancelBtn.classList.add('hidden');
    }

    // Start proofread immediately
    if (mode === 'proofread') {
        spinnerEl.classList.remove('hidden');
        fetch(basePath + '/piece/' + pieceId + '/proofread', { signal: controller.signal })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                spinnerEl.classList.add('hidden');
                showPreview(data.corrected);
            })
            .catch(function(err) {
                spinnerEl.classList.add('hidden');
                if (err.name !== 'AbortError') {
                    previewArea.classList.remove('hidden');
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
            pill.className = 'badge badge-sm badge-secondary gap-1';
            pill.textContent = '\uD83D\uDD0D ' + (value.length > 30 ? value.substring(0, 30) + '\u2026' : value);
            pill.title = value;
            pillsEl.appendChild(pill);
        } else if (type === 'fetch') {
            var a = document.createElement('a');
            a.className = 'badge badge-sm badge-accent gap-1';
            a.href = value;
            a.target = '_blank';
            var host = value;
            try { host = new URL(value).hostname; } catch(e) { host = value.substring(0, 25); }
            a.textContent = '\uD83C\uDF10 ' + host;
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

            var headerDiv = card.querySelector('.board-card-header');
            var toggleBtn = document.createElement('button');
            toggleBtn.className = 'btn btn-secondary btn-xs';
            toggleBtn.textContent = '+';
            toggleBtn.title = 'Expand';
            headerDiv.insertBefore(toggleBtn, headerDiv.firstChild);

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
