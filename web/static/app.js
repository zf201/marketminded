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
                    if (data.type === 'chunk') {
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
        md.innerHTML = marked.parse(content);
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

    if (data.waterfall && data.waterfall.length > 0) {
        var wh = document.createElement('div');
        wh.style.cssText = 'font-weight:600;margin-top:0.75rem;margin-bottom:0.5rem;font-size:0.9rem';
        wh.textContent = 'Waterfall pieces:';
        el.appendChild(wh);
        var list = document.createElement('div');
        list.className = 'content-items';
        data.waterfall.forEach(function(w) {
            var item = document.createElement('div');
            item.className = 'content-item';
            item.textContent = (w.count || 1) + 'x ' + w.platform + ' ' + w.format;
            list.appendChild(item);
        });
        el.appendChild(list);
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
}

function renderBlogPost(el, data) {
    renderSection(el, 'Title', data.title);
    renderSection(el, 'Body', data.body, { markdown: true });
    renderSection(el, 'Meta Description', data.meta_description, { minor: true });
}

function renderSimplePost(el, data) {
    renderSection(el, 'Caption', data.caption);
    if (data.hashtags) renderSection(el, 'Hashtags', data.hashtags, { badges: true, minor: true });
    if (data.image_instructions) renderSection(el, 'Image Instructions', data.image_instructions, { minor: true });
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

// --- Production board ---

function initProductionBoard(projectID, runID) {
    var basePath = '/projects/' + projectID + '/pipeline/' + runID;
    var board = document.getElementById('production-board');
    var nextPieceID = parseInt(board.dataset.nextPieceId) || 0;

    // Helper: connect SSE stream to an element
    function streamToElement(url, el, onDone) {
        el.textContent = '';
        var source = new EventSource(url);
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            if (d.type === 'chunk') {
                el.textContent += d.chunk;
                el.scrollTop = el.scrollHeight;
            } else if (d.type === 'tool_start') {
                el.textContent += '\n[' + d.summary + '...]\n';
            } else if (d.type === 'content_written') {
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

        // Approve button
        if (btn.classList.contains('piece-approve-btn')) {
            var pieceId = btn.dataset.pieceId;
            btn.disabled = true;
            fetch(basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.complete) {
                        window.location.reload();
                    } else if (data.next_piece_id) {
                        // Update card status visually
                        var card = btn.closest('.board-card');
                        card.className = card.className.replace(/board-card-(draft)/g, '') + ' board-card-approved';
                        var actionsEl = document.getElementById('piece-actions-' + pieceId);
                        actionsEl.innerHTML = '<span class="badge badge-approved">approved</span>';
                        // Auto-generate next piece
                        streamPiece(data.next_piece_id);
                    } else {
                        window.location.reload();
                    }
                });
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

        // Improve button
        if (btn.classList.contains('piece-improve-btn')) {
            var pieceId = btn.dataset.pieceId;
            var chatEl = document.getElementById('improve-chat-' + pieceId);
            chatEl.style.display = chatEl.style.display === 'none' ? 'block' : 'none';
            return;
        }
    });

    // Improve form submissions
    board.addEventListener('submit', function(e) {
        if (!e.target.classList.contains('improve-form')) return;
        e.preventDefault();
        var form = e.target;
        var pieceId = form.dataset.pieceId;
        var textarea = form.querySelector('textarea');
        var content = textarea.value.trim();
        if (!content) return;

        var submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Rewriting...';

        // Post message
        fetch(basePath + '/piece/' + pieceId + '/improve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(content)
        }).then(function() {
            textarea.value = '';
            // Show user message in chat
            var messagesEl = document.getElementById('improve-messages-' + pieceId);
            var userMsg = document.createElement('div');
            userMsg.className = 'chat-msg chat-msg-user';
            userMsg.style.fontSize = '0.85rem';
            userMsg.textContent = content;
            messagesEl.appendChild(userMsg);

            // Stream rewrite
            var bodyEl = document.getElementById('piece-body-' + pieceId);
            streamToElement(basePath + '/piece/' + pieceId + '/improve/stream', bodyEl, function() {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Send & Rewrite';
                window.location.reload();
            });
        });
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

    // Auto-start plan generation if plan is empty and status is planning
    // (checked via presence of generate-plan-btn)

    // Auto-generate first pending piece if we just entered producing state
    // The next piece ID is set in the template data attribute
}
