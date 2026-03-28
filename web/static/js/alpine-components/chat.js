// Alpine.js component: streamingChat
// Consolidates initProfileChat, initProfileSectionChat, initContextChat,
// and brainstormChat into a single reusable component.
//
// Config shape:
// {
//   sendURL: string,          // POST endpoint for user messages
//   streamURL: string,        // SSE endpoint for AI responses
//   projectID: number|string, // for proposal accept URLs
//   hasProposals: boolean,    // profile chat shows accept/reject proposals
//   hasEditCards: boolean,    // profile chat has inline card editing
//   hasSave: boolean,         // context chat has a save button
//   saveURL: string,          // POST endpoint for save (form submit)
//   reloadOnDone: boolean,    // brainstorm chat reloads page on done
// }

document.addEventListener('alpine:init', function() {
    Alpine.data('streamingChat', function(config) {
        return {
            input: '',
            streaming: false,
            error: '',
            // Brainstorm-specific streaming state
            pendingMessage: '',
            streamContent: '',
            thinkingContent: '',
            thinkingDone: false,
            source: null,

            // --- Refs ---
            // Expects: x-ref="messages" on the messages container
            // Expects: x-ref="input" on the textarea
            // Expects: x-ref="sendBtn" on the send button

            scrollToBottom() {
                var self = this;
                this.$nextTick(function() {
                    var el = self.$refs.messages;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            },

            // --- DOM helpers (imperative, matching original app.js) ---

            addChatText(container, text) {
                var last = container.lastElementChild;
                if (!last || !last.classList.contains('chat-text')) {
                    last = document.createElement('span');
                    last.className = 'chat-text';
                    container.appendChild(last);
                }
                last.textContent += text;
            },

            createToolIndicator(summary) {
                var el = document.createElement('div');
                el.className = 'tool-indicator';
                var dots = document.createElement('span');
                dots.className = 'typing-indicator';
                dots.textContent = '...';
                el.appendChild(dots);
                el.appendChild(document.createTextNode(' ' + summary));
                return el;
            },

            createToolResult(summary) {
                var details = document.createElement('details');
                details.className = 'tool-result-block';
                var summaryEl = document.createElement('summary');
                summaryEl.textContent = summary;
                details.appendChild(summaryEl);
                return details;
            },

            createProposalBlock(section, content) {
                var projectID = config.projectID;
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
            },

            addMsg(role, text) {
                var messagesEl = this.$refs.messages;
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
            },

            // --- Card editing (profile chat) ---

            editCard(section) {
                var projectID = config.projectID;
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
            },

            // --- Keyboard shortcut ---

            handleKeydown(e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                    e.preventDefault();
                    this.sendMessage();
                }
            },

            // --- Send message (unified) ---

            async sendMessage() {
                var msg = this.input.trim();
                if (!msg || this.streaming) return;

                var self = this;
                var messagesEl = this.$refs.messages;
                var inputEl = this.$refs.input;
                var sendBtnEl = this.$refs.sendBtn;

                this.input = '';
                this.error = '';
                this.streaming = true;

                // Brainstorm mode uses Alpine reactive state for rendering
                if (config.reloadOnDone) {
                    this.pendingMessage = msg;
                    this.streamContent = '';
                    this.thinkingContent = '';
                    this.thinkingDone = false;
                    this.scrollToBottom();

                    try {
                        var res = await fetch(config.sendURL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'content=' + encodeURIComponent(msg)
                        });

                        if (!res.ok) {
                            throw new Error('Failed to send message: ' + res.statusText);
                        }

                        self.source = new EventSource(config.streamURL);

                        self.source.onmessage = function(event) {
                            var data = JSON.parse(event.data);

                            if (data.type === 'done') {
                                self.source.close();
                                self.source = null;
                                self.streaming = false;
                                window.location.reload();
                                return;
                            }
                            if (data.type === 'error') {
                                self.error = data.error;
                                self.source.close();
                                self.source = null;
                                self.streaming = false;
                                return;
                            }
                            if (data.type === 'thinking') {
                                self.thinkingContent += data.chunk;
                                self.scrollToBottom();
                            }
                            if (data.type === 'chunk') {
                                if (!self.thinkingDone && self.thinkingContent) {
                                    self.thinkingDone = true;
                                }
                                self.streamContent += data.chunk;
                                self.scrollToBottom();
                            }
                            if (data.type === 'tool_start') {
                                self.streamContent += '\n[' + data.summary + '...]\n';
                                self.scrollToBottom();
                            }
                            if (data.type === 'tool_result') {
                                // Tool result received, AI will continue
                            }
                        };

                        self.source.onerror = function() {
                            if (self.source) self.source.close();
                            self.source = null;
                            self.streaming = false;
                            if (!self.streamContent) {
                                self.error = 'Connection lost. Try again.';
                            }
                        };

                    } catch (e) {
                        self.error = e.message;
                        self.streaming = false;
                    }
                    return;
                }

                // Imperative DOM mode (profile, section, context chats)
                if (inputEl) inputEl.disabled = true;
                if (sendBtnEl) {
                    sendBtnEl.disabled = true;
                    sendBtnEl.textContent = 'Thinking...';
                }

                // User message bubble
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
                self.scrollToBottom();

                function resetControls() {
                    if (inputEl) inputEl.disabled = false;
                    if (sendBtnEl) {
                        sendBtnEl.disabled = false;
                        sendBtnEl.textContent = 'Send';
                    }
                    self.streaming = false;
                }

                fetch(config.sendURL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'content=' + encodeURIComponent(msg)
                }).then(function() {
                    var source = new EventSource(config.streamURL);
                    var lastToolIndicator = null;

                    source.onmessage = function(event) {
                        var d = JSON.parse(event.data);

                        switch (d.type) {
                        case 'chunk':
                            self.addChatText(aBody, d.chunk);
                            self.scrollToBottom();
                            break;

                        case 'tool_start':
                            if (config.hasProposals) {
                                // Profile chat: animated tool indicator
                                lastToolIndicator = self.createToolIndicator(d.summary);
                                aBody.appendChild(lastToolIndicator);
                            } else {
                                // Section/context chat: simple indicator
                                var indicator = document.createElement('div');
                                indicator.className = 'tool-indicator';
                                indicator.textContent = d.summary + '...';
                                aBody.appendChild(indicator);
                            }
                            self.scrollToBottom();
                            break;

                        case 'tool_result':
                            if (config.hasProposals && lastToolIndicator) {
                                var resultBlock = self.createToolResult(d.summary);
                                lastToolIndicator.replaceWith(resultBlock);
                                lastToolIndicator = null;
                            } else {
                                var lastInd = aBody.querySelector('.tool-indicator:last-of-type');
                                if (lastInd) {
                                    var details = document.createElement('details');
                                    details.className = 'tool-result-block';
                                    var sm = document.createElement('summary');
                                    sm.textContent = d.summary;
                                    details.appendChild(sm);
                                    lastInd.replaceWith(details);
                                }
                            }
                            self.scrollToBottom();
                            break;

                        case 'proposal':
                            if (config.hasProposals) {
                                var proposalBlock = self.createProposalBlock(d.section, d.content);
                                aBody.appendChild(proposalBlock);
                                self.scrollToBottom();
                            }
                            break;

                        case 'error':
                            source.close();
                            self.addChatText(aBody, '\nError: ' + d.error);
                            resetControls();
                            break;

                        case 'done':
                            source.close();
                            resetControls();
                            self.scrollToBottom();
                            break;
                        }
                    };

                    source.onerror = function() {
                        source.close();
                        resetControls();
                    };
                }).catch(function(err) {
                    self.addChatText(aBody, 'Error: ' + err.message);
                    resetControls();
                });
            },

            // --- Save button (context chat) ---

            saveContent() {
                if (!config.hasSave || !config.saveURL) return;

                var messagesEl = this.$refs.messages;
                var saveBtnEl = this.$refs.saveBtn;

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

                if (saveBtnEl) {
                    saveBtnEl.disabled = true;
                    saveBtnEl.textContent = 'Saving...';
                }

                var form = document.createElement('form');
                form.method = 'POST';
                form.action = config.saveURL;
                var cInput = document.createElement('input');
                cInput.type = 'hidden';
                cInput.name = 'content';
                cInput.value = content;
                form.appendChild(cInput);
                document.body.appendChild(form);
                form.submit();
            },

            // --- Edit card delegation (profile chat) ---

            handleEditClick(e) {
                if (!config.hasEditCards) return;
                var editBtn = e.target.closest('.profile-card-edit-btn');
                if (editBtn) {
                    e.preventDefault();
                    this.editCard(editBtn.dataset.section);
                }
            },

            // --- Init ---

            init() {
                var self = this;

                // Bind edit card delegation at document level for profile chat
                if (config.hasEditCards) {
                    document.addEventListener('click', function(e) {
                        self.handleEditClick(e);
                    });
                }

                // Scroll to bottom on init
                this.scrollToBottom();
            },

            destroy() {
                if (this.source) this.source.close();
            }
        };
    });
});
