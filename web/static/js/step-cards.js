// step-cards.js — Shared step card rendering for pipeline and topic generator.
// Step cards are rendered entirely by JS. The server provides data-only shells:
//   <div class="step-card card" data-step-id="12" data-status="completed"
//        data-step-type="research" data-usage='{"cost":0.06,...}'
//        data-output='...' data-thinking='...'>
//   </div>
// This module reads data attributes and builds all inner DOM.

var StepCards = (function() {

    var STEP_LABELS = {
        'research': 'Researcher',
        'audience_picker': 'Audience Picker',
        'brand_enricher': 'Brand Enricher',
        'claim_verifier': 'Claim Verifier',
        'editor': 'Editor',
        'style_reference': 'Style Reference',
        'write': 'Writer',
        'topic_explore': 'Explorer',
        'topic_review': 'Reviewer'
    };

    var BADGE_CLASSES = {
        'completed': 'badge-completed',
        'running': 'badge-running',
        'failed': 'badge-failed',
        'pending': 'badge-ghost'
    };

    function stepLabel(type) {
        return STEP_LABELS[type] || type;
    }

    // Format usage stats as a compact line
    function renderUsageStats(container, usageJSON) {
        if (!usageJSON) return;
        var usage;
        try { usage = JSON.parse(usageJSON); } catch(e) { return; }

        var stats = document.createElement('div');
        stats.className = 'step-usage flex flex-wrap gap-2 mt-2 text-xs text-zinc-500';

        var parts = [];

        if (usage.server_tool_use && usage.server_tool_use.web_search_requests > 0) {
            parts.push('\uD83D\uDD0D ' + usage.server_tool_use.web_search_requests + ' searches');
        }

        if (usage.total_tokens > 0) {
            var tokens = usage.total_tokens;
            var label = tokens >= 1000 ? (tokens / 1000).toFixed(1) + 'k' : tokens;
            parts.push('\u2699 ' + label + ' tokens');
        }

        if (usage.cost > 0) {
            parts.push('$ ' + usage.cost.toFixed(4));
        }

        if (parts.length === 0) return;

        parts.forEach(function(text) {
            var pill = document.createElement('span');
            pill.className = 'badge badge-sm badge-ghost font-mono';
            pill.textContent = text;
            stats.appendChild(pill);
        });

        container.appendChild(stats);
    }

    function createThinkingPulse() {
        var pulse = document.createElement('span');
        pulse.className = 'step-thinking-pulse hidden';
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.className = 'dot';
            pulse.appendChild(dot);
        }
        return pulse;
    }

    // Build the full inner content of a step card based on its data attributes.
    function render(card) {
        var status = card.dataset.status || 'pending';
        var stepType = card.dataset.stepType || '';
        var round = card.dataset.round || '';
        var usageData = card.dataset.usage || '';
        var output = card.dataset.output || '';

        card.textContent = '';

        var inner = document.createElement('div');
        inner.className = 'p-3';

        // Header: [round label] step name [thinking pulse] [badge]
        var header = document.createElement('div');
        header.className = 'board-card-header flex items-center gap-2';

        if (round) {
            var roundEl = document.createElement('span');
            roundEl.className = 'text-xs text-zinc-500';
            roundEl.textContent = 'Round ' + round;
            header.appendChild(roundEl);
        }

        var nameEl = document.createElement('strong');
        nameEl.className = 'text-sm';
        nameEl.textContent = stepLabel(stepType);
        header.appendChild(nameEl);

        // Thinking pulse — shown during streaming
        var pulseEl = createThinkingPulse();
        header.appendChild(pulseEl);

        var badge = document.createElement('span');
        badge.className = 'ml-auto badge ' + (BADGE_CLASSES[status] || 'badge-ghost');
        badge.textContent = status;
        header.appendChild(badge);

        inner.appendChild(header);

        // Usage stats (completed steps only)
        if ((status === 'completed' || status === 'failed') && usageData) {
            renderUsageStats(inner, usageData);
        }

        // Thinking ticker (for streaming)
        var tickerEl = document.createElement('div');
        tickerEl.className = 'step-thinking-ticker font-mono text-xs max-h-24 overflow-y-auto mt-1 empty:hidden';
        inner.appendChild(tickerEl);

        // Stream element (for live streaming text)
        var streamEl = document.createElement('div');
        streamEl.className = 'step-stream whitespace-pre-wrap text-sm mt-2 max-h-72 overflow-y-auto empty:hidden';
        inner.appendChild(streamEl);

        // Output element
        var outputEl = document.createElement('div');
        outputEl.className = 'step-output mt-2 empty:hidden';
        inner.appendChild(outputEl);

        // Render output based on status
        if (status === 'completed' && output) {
            var label = stepLabel(stepType);
            if (label === 'Writer') {
                outputEl.style.display = 'none';
            } else {
                try {
                    var data = JSON.parse(output);
                    if (stepType === 'topic_explore' && data.topics) {
                        renderTopicExploreOutput(outputEl, data);
                    } else if (stepType === 'topic_review' && data.reviews) {
                        renderTopicReviewOutput(outputEl, data);
                    } else if (typeof renderStepOutput === 'function') {
                        renderStepOutput(outputEl, label, data);
                    } else {
                        outputEl.textContent = output;
                        outputEl.style.whiteSpace = 'pre-wrap';
                    }
                } catch(e) {
                    outputEl.textContent = output;
                    outputEl.style.whiteSpace = 'pre-wrap';
                }
            }
        } else if (status === 'failed' && output) {
            var details = document.createElement('details');
            details.className = 'mt-0';
            var summary = document.createElement('summary');
            summary.className = 'cursor-pointer text-sm text-red-400';
            summary.textContent = 'View failed output';
            details.appendChild(summary);
            var pre = document.createElement('div');
            pre.className = 'whitespace-pre-wrap text-xs mt-2 max-h-72 overflow-y-auto p-2 bg-red-500/5 border border-red-500/20 rounded';
            pre.textContent = output;
            details.appendChild(pre);
            outputEl.appendChild(details);
        }

        card.appendChild(inner);

        return {
            pulseEl: pulseEl,
            tickerEl: tickerEl,
            streamEl: streamEl,
            outputEl: outputEl,
            badge: badge
        };
    }

    function renderTopicExploreOutput(el, data) {
        var container = document.createElement('div');
        container.className = 'space-y-2';
        data.topics.forEach(function(t) {
            var item = document.createElement('div');
            item.className = 'bg-zinc-800/50 rounded p-2';
            var title = document.createElement('p');
            title.className = 'text-sm font-medium text-zinc-200';
            title.textContent = t.title;
            var angle = document.createElement('p');
            angle.className = 'text-xs text-zinc-400 mt-1';
            angle.textContent = t.angle;
            item.appendChild(title);
            item.appendChild(angle);
            container.appendChild(item);
        });
        el.appendChild(container);
    }

    function renderTopicReviewOutput(el, data) {
        var container = document.createElement('div');
        container.className = 'space-y-2';
        data.reviews.forEach(function(r) {
            var item = document.createElement('div');
            item.className = 'bg-zinc-800/50 rounded p-2 border-l-2';
            item.style.borderColor = r.verdict === 'approved' ? 'rgb(34 197 94 / 0.5)' : 'rgb(239 68 68 / 0.5)';
            var hdr = document.createElement('div');
            hdr.className = 'flex items-center gap-2';
            var vBadge = document.createElement('span');
            vBadge.className = 'badge badge-sm ' + (r.verdict === 'approved' ? 'badge-success' : 'badge-error');
            vBadge.textContent = r.verdict;
            var title = document.createElement('span');
            title.className = 'text-sm font-medium text-zinc-200';
            title.textContent = r.title;
            hdr.appendChild(vBadge);
            hdr.appendChild(title);
            var reasoning = document.createElement('p');
            reasoning.className = 'text-xs text-zinc-400 mt-1';
            reasoning.textContent = r.reasoning;
            item.appendChild(hdr);
            item.appendChild(reasoning);
            container.appendChild(item);
        });
        el.appendChild(container);
    }

    // Stream a step via SSE. Renders the card in running state, then fills it.
    function stream(card, url, onDone, onError) {
        card.dataset.status = 'running';
        card.dataset.toolCalls = '';
        card.dataset.usage = '';
        card.dataset.output = '';
        var refs = render(card);

        // Show thinking pulse
        refs.pulseEl.classList.remove('hidden');

        var source = new EventSource(url);

        source.onmessage = function(event) {
            var d = JSON.parse(event.data);

            if (d.type === 'thinking') {
                refs.tickerEl.textContent += d.chunk;
                refs.tickerEl.scrollTop = refs.tickerEl.scrollHeight;
            } else if (d.type === 'chunk') {
                refs.streamEl.textContent += d.chunk;
                refs.streamEl.scrollTop = refs.streamEl.scrollHeight;
            } else if (d.type === 'content_written') {
                if (refs.outputEl && typeof renderContentBody === 'function') {
                    renderContentBody(refs.outputEl, d.platform, d.format, JSON.stringify(d.data));
                }
            } else if (d.type === 'done') {
                source.close();
                refs.pulseEl.classList.add('hidden');
                refs.tickerEl.classList.add('done');
                refs.badge.textContent = 'completed';
                refs.badge.className = 'ml-auto badge badge-completed';
                card.dataset.status = 'completed';
                if (onDone) onDone();
            } else if (d.type === 'error') {
                source.close();
                refs.pulseEl.classList.add('hidden');
                refs.tickerEl.classList.add('done');
                refs.streamEl.textContent += '\nError: ' + d.error;
                refs.badge.textContent = 'failed';
                refs.badge.className = 'ml-auto badge badge-failed';
                card.dataset.status = 'failed';
                if (onError) onError(d.error);
            }
        };

        source.onerror = function() {
            source.close();
            refs.pulseEl.classList.add('hidden');
            refs.badge.textContent = 'failed';
            refs.badge.className = 'ml-auto badge badge-failed';
            card.dataset.status = 'failed';
            if (onError) onError('Connection lost');
        };

        return source;
    }

    function create(stepId, stepType, round, status) {
        var card = document.createElement('div');
        card.className = 'step-card card';
        card.dataset.stepId = stepId;
        card.dataset.stepType = stepType;
        card.dataset.round = round || '';
        card.dataset.status = status || 'running';
        card.dataset.toolCalls = '';
        card.dataset.usage = '';
        card.dataset.output = '';
        render(card);
        return card;
    }

    function renderAll() {
        document.querySelectorAll('.step-card[data-step-id]').forEach(function(card) {
            render(card);
        });
    }

    function collapseCompleted() {
        var cards = document.querySelectorAll('.step-card[data-step-id]');
        var allCompleted = true;
        cards.forEach(function(card) {
            if (card.dataset.status !== 'completed') allCompleted = false;
        });

        if (!allCompleted) return;

        cards.forEach(function(card, idx) {
            if (idx >= cards.length - 1) return;

            var output = card.querySelector('.step-output');
            if (output) output.style.display = 'none';
            card.dataset.collapsed = 'true';

            var headerDiv = card.querySelector('.board-card-header');
            if (!headerDiv) return;
            var badge = headerDiv.querySelector('.badge');
            var rightGroup = document.createElement('div');
            rightGroup.className = 'flex items-center gap-1';
            if (badge) {
                badge.parentNode.removeChild(badge);
                rightGroup.appendChild(badge);
            }
            var toggleBtn = document.createElement('button');
            toggleBtn.className = 'btn btn-secondary btn-xs';
            toggleBtn.textContent = '+';
            toggleBtn.title = 'Expand';
            rightGroup.appendChild(toggleBtn);
            headerDiv.appendChild(rightGroup);
            headerDiv.style.marginBottom = '0';

            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var o = card.querySelector('.step-output');
                var isCollapsed = card.dataset.collapsed === 'true';
                if (o) o.style.display = isCollapsed ? '' : 'none';
                card.dataset.collapsed = isCollapsed ? 'false' : 'true';
                toggleBtn.textContent = isCollapsed ? '\u2212' : '+';
                toggleBtn.title = isCollapsed ? 'Collapse' : 'Expand';
                headerDiv.style.marginBottom = isCollapsed ? '' : '0';
            });
        });
    }

    // Set usage data on a card and render the stats
    function setUsage(card, usageJSON) {
        if (!usageJSON || usageJSON === 'null') return;
        card.dataset.usage = typeof usageJSON === 'string' ? usageJSON : JSON.stringify(usageJSON);
        var inner = card.querySelector('.p-3');
        if (!inner) return;
        var existing = inner.querySelector('.step-usage');
        if (existing) existing.remove();
        var header = inner.querySelector('.board-card-header');
        if (header && header.nextSibling) {
            var tmp = document.createElement('div');
            renderUsageStats(tmp, card.dataset.usage);
            if (tmp.firstChild) {
                header.parentNode.insertBefore(tmp.firstChild, header.nextSibling);
            }
        }
    }

    return {
        render: render,
        stream: stream,
        create: create,
        renderAll: renderAll,
        collapseCompleted: collapseCompleted,
        setUsage: setUsage,
        stepLabel: stepLabel
    };
})();

// Auto-render all step cards on page load
document.addEventListener('DOMContentLoaded', function() {
    StepCards.renderAll();
    StepCards.collapseCompleted();
});
