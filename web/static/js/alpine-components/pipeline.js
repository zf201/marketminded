// Alpine.js component: pipelineBoard
// Uses StepCards (step-cards.js) for all step card rendering and streaming.
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

            // --- Step chaining (cornerstone) ---

            activeSource: null,
            beforeUnloadHandler: null,

            runNextStep(cards, index) {
                var self = this;

                if (index >= cards.length) {
                    if (self.beforeUnloadHandler) window.removeEventListener('beforeunload', self.beforeUnloadHandler);
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
                var url = self.basePath + '/stream/step/' + stepID;

                self.activeSource = StepCards.stream(card, url, function() {
                    self.activeSource = null;
                    self.runNextStep(cards, index + 1);
                }, function() {
                    self.activeSource = null;
                    if (self.beforeUnloadHandler) window.removeEventListener('beforeunload', self.beforeUnloadHandler);
                    var cancelBtn = document.getElementById('cancel-pipeline-btn');
                    if (cancelBtn) cancelBtn.classList.add('hidden');
                    var runBtn = document.getElementById('run-pipeline-btn');
                    if (runBtn) {
                        runBtn.disabled = false;
                        runBtn.textContent = 'Retry';
                        runBtn.classList.remove('hidden');
                    }
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

            // --- Init ---

            init() {
                var self = this;

                // Render all step cards from data attributes (shared module)
                StepCards.renderAll();

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

                    // Collapse completed steps
                    StepCards.collapseCompleted();

                    // Run pipeline / Retry button
                    var runBtn = document.getElementById('run-pipeline-btn');
                    var cancelBtn = document.getElementById('cancel-pipeline-btn');

                    self.beforeUnloadHandler = function(e) {
                        e.preventDefault();
                    };

                    if (runBtn) {
                        runBtn.addEventListener('click', function() {
                            runBtn.disabled = true;
                            runBtn.textContent = 'Running...';
                            if (cancelBtn) cancelBtn.classList.remove('hidden');
                            window.addEventListener('beforeunload', self.beforeUnloadHandler);
                            var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
                            self.runNextStep(cards, 0);
                        });
                    }

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function() {
                            if (self.activeSource) {
                                self.activeSource.close();
                                self.activeSource = null;
                            }
                            window.removeEventListener('beforeunload', self.beforeUnloadHandler);
                            cancelBtn.classList.add('hidden');
                            // Reload to get clean state from server
                            window.location.reload();
                        });
                    }

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
                        toggleBtn.className = 'btn btn-secondary btn-xs';
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
