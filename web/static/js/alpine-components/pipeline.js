// Alpine.js component: pipelineBoard
// Consolidates initCornerstonePipeline and initProductionBoard into a single
// reusable component for pipeline step streaming, chaining, and piece generation.
//
// Config shape:
// {
//   projectID: number|string,
//   runID: number|string,
//   isProduction: boolean,       // true for production board, false for cornerstone
//   nextPieceID: number|string,  // production board: next piece to generate
// }

document.addEventListener('alpine:init', function() {
    Alpine.data('pipelineBoard', function(config) {
        return {
            basePath: '/projects/' + config.projectID + '/pipeline/' + config.runID,

            // --- Helpers ---

            setBadge(card, text, cls) {
                var badges = card.querySelectorAll('.badge');
                var badge = badges[badges.length - 1];
                if (!badge) return;
                badge.textContent = text;
                badge.className = 'badge ' + (cls || '');
            },

            addToolPill(card, type, value) {
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
            },

            // --- Step streaming (cornerstone) ---

            streamStep(stepID, card, onDone, onError) {
                var self = this;
                var streamEl = card.querySelector('.step-stream');
                var outputEl = card.querySelector('.step-output');
                var tickerEl = card.querySelector('.step-thinking-ticker');

                if (streamEl) streamEl.textContent = '';
                if (outputEl) outputEl.textContent = '';
                if (tickerEl) tickerEl.textContent = '';

                var url = self.basePath + '/stream/step/' + stepID;
                var source = new EventSource(url);

                source.onmessage = function(event) {
                    var d = JSON.parse(event.data);

                    if (d.type === 'thinking') {
                        if (tickerEl) {
                            tickerEl.textContent += d.chunk;
                            tickerEl.scrollTop = tickerEl.scrollHeight;
                        }
                    } else if (d.type === 'chunk') {
                        if (streamEl) {
                            streamEl.textContent += d.chunk;
                            streamEl.scrollTop = streamEl.scrollHeight;
                        }
                    } else if (d.type === 'tool_start') {
                        if (d.tool === 'web_search' && d.query) {
                            self.addToolPill(card, 'search', d.query);
                        } else if (d.tool === 'fetch_url' && d.url) {
                            self.addToolPill(card, 'fetch', d.url);
                        }
                    } else if (d.type === 'content_written') {
                        if (outputEl) renderContentBody(outputEl, d.platform, d.format, JSON.stringify(d.data));
                    } else if (d.type === 'done') {
                        source.close();
                        if (tickerEl) tickerEl.classList.add('done');
                        self.setBadge(card, 'completed', 'badge-completed');
                        card.dataset.status = 'completed';
                        if (onDone) onDone();
                    } else if (d.type === 'error') {
                        source.close();
                        if (tickerEl) tickerEl.classList.add('done');
                        if (streamEl) streamEl.textContent += '\nError: ' + d.error;
                        self.setBadge(card, 'failed', 'badge-failed');
                        card.dataset.status = 'failed';
                        if (onError) onError(d.error);
                    }
                };

                source.onerror = function() {
                    source.close();
                    self.setBadge(card, 'failed', 'badge-failed');
                    card.dataset.status = 'failed';
                    if (onError) onError('Connection lost');
                };

                return source;
            },

            // --- Step chaining (cornerstone) ---

            runNextStep(cards, index) {
                var self = this;

                if (index >= cards.length) {
                    // All done — reload to show the cornerstone piece
                    window.location.reload();
                    return;
                }

                var card = cards[index];
                var status = card.dataset.status;

                if (status === 'completed') {
                    self.runNextStep(cards, index + 1);
                    return;
                }

                if (status !== 'pending' && status !== 'failed') {
                    self.runNextStep(cards, index + 1);
                    return;
                }

                var stepID = card.dataset.stepId;
                self.setBadge(card, 'running', 'badge-running');
                card.dataset.status = 'running';

                self.streamStep(stepID, card, function() {
                    self.runNextStep(cards, index + 1);
                }, function() {
                    // On error, stop chaining
                });
            },

            // --- Production board: stream to element ---

            streamToElement(url, el, onDone) {
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
            },

            // --- Production board: piece streaming ---

            streamPiece(pieceId) {
                var self = this;
                var bodyEl = document.getElementById('piece-body-' + pieceId);
                var card = bodyEl.closest('.board-card');
                card.className = card.className.replace(/board-card-(pending|rejected)/g, '') + ' board-card-generating';
                bodyEl.classList.remove('collapsed');

                self.streamToElement(self.basePath + '/stream/piece/' + pieceId, bodyEl, function() {
                    window.location.reload();
                });
            },

            // --- Run pipeline button ---

            runPipeline() {
                var self = this;
                var runBtn = this.$refs.runBtn || document.getElementById('run-pipeline-btn');
                if (runBtn) {
                    runBtn.disabled = true;
                    runBtn.textContent = 'Running...';
                }

                var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
                self.runNextStep(cards, 0);
            },

            // --- Init ---

            init() {
                var self = this;

                if (config.isProduction) {
                    // --- Production board init ---
                    var boardEl = this.$el;

                    // Plan generation
                    var genPlanBtn = document.getElementById('generate-plan-btn');
                    if (genPlanBtn) {
                        genPlanBtn.addEventListener('click', function() {
                            genPlanBtn.disabled = true;
                            genPlanBtn.textContent = 'Generating...';
                            var planBody = document.getElementById('plan-body');
                            self.streamToElement(self.basePath + '/stream/plan', planBody, function() {
                                window.location.reload();
                            });
                        });
                    }

                    // Event delegation for piece buttons
                    boardEl.addEventListener('click', function(e) {
                        var btn = e.target;

                        if (btn.classList.contains('piece-generate-btn')) {
                            var pieceId = btn.dataset.pieceId;
                            btn.disabled = true;
                            btn.textContent = 'Generating...';
                            self.streamPiece(pieceId);
                            return;
                        }

                        if (btn.classList.contains('piece-approve-btn')) {
                            var pieceId = btn.dataset.pieceId;
                            btn.disabled = true;
                            btn.textContent = 'Approving...';
                            fetch(self.basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
                                .then(function() { window.location.reload(); })
                                .catch(function() { window.location.reload(); });
                            return;
                        }

                        if (btn.classList.contains('piece-reject-btn')) {
                            var pieceId = btn.dataset.pieceId;
                            var reason = prompt('Why should this be rejected?');
                            if (reason === null) return;
                            fetch(self.basePath + '/piece/' + pieceId + '/reject', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'reason=' + encodeURIComponent(reason)
                            }).then(function() {
                                window.location.reload();
                            });
                            return;
                        }

                        if (btn.classList.contains('piece-improve-btn')) {
                            var pieceId = btn.dataset.pieceId;
                            var bodyEl = document.getElementById('piece-body-' + pieceId);
                            openContentModal({
                                mode: 'improve',
                                pieceId: pieceId,
                                platform: bodyEl ? bodyEl.dataset.platform : '',
                                format: bodyEl ? bodyEl.dataset.format : '',
                                basePath: self.basePath
                            });
                            return;
                        }
                    });

                    // Render plan card
                    var planBody = document.getElementById('plan-body');
                    if (planBody) {
                        renderPlan(planBody);
                    }

                    // Render existing content bodies
                    document.querySelectorAll('.board-card-body').forEach(function(el) {
                        var text = el.textContent.trim();
                        if (text && el.dataset.platform) {
                            renderContentBody(el, el.dataset.platform, el.dataset.format, text);
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
                                basePath: self.basePath
                            });
                        });
                    });

                } else {
                    // --- Cornerstone pipeline init ---

                    // Run pipeline button
                    var runBtn = document.getElementById('run-pipeline-btn');
                    if (runBtn) {
                        runBtn.addEventListener('click', function() {
                            runBtn.disabled = true;
                            runBtn.textContent = 'Running...';

                            var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
                            self.runNextStep(cards, 0);
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
                            self.setBadge(card, 'running', 'badge-running');
                            card.dataset.status = 'running';
                            btn.disabled = true;

                            self.streamStep(stepID, card, function() {
                                self.runNextStep(cards, stepIndex + 1);
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

                        fetch(self.basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
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
                        fetch(self.basePath + '/piece/' + pieceId + '/reject', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'reason=' + encodeURIComponent(reason)
                        }).then(function() { window.location.reload(); });
                    });

                    // Render existing content bodies
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
                        rightGroup.className = 'flex items-center gap-1';
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
                                self.addToolPill(card, tc.type, tc.value);
                            });
                        } catch(e) {}
                    });

                    // Render step outputs and collapse completed non-last steps
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
                            var badge = headerDiv.querySelector('.badge');
                            var rightGroup = document.createElement('div');
                            rightGroup.className = 'flex items-center gap-1';
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
                                basePath: self.basePath
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
                            basePath: self.basePath
                        });
                    });
                }
            }
        };
    });
});
