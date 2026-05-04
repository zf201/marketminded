import './echo';

document.addEventListener('alpine:init', () => {
    Alpine.data('conversationStream', (conversationId, initialItems = [], live = false, pieceUrlTemplate = '') => ({
        items: initialItems,
        live,
        pieceUrlTemplate,

        init() {
            if (!this.live || typeof Echo === 'undefined') return;
            window.Echo
                .private('conversation.' + conversationId)
                .listen('.ConversationEvent', e => this.handle(e));
        },

        pieceUrl(pieceId) {
            if (!pieceId || !this.pieceUrlTemplate) return null;
            return this.pieceUrlTemplate.replace('__PIECE_ID__', pieceId);
        },

        handle(e) {
            const p = e.payload;
            switch (e.type) {
                case 'text_chunk': {
                    const last = this.items[this.items.length - 1];
                    if (last && last.type === 'text') {
                        last.content += p.content;
                    } else {
                        this.items.push({ type: 'text', content: p.content });
                    }
                    break;
                }
                case 'reasoning_chunk': {
                    let r = this.items.find(i => i.type === 'reasoning' && i.streaming);
                    if (!r) {
                        r = { type: 'reasoning', content: '', streaming: true };
                        this.items.unshift(r);
                    }
                    r.content += p.content;
                    break;
                }
                case 'subagent_started':
                    this.items.push({
                        type: 'subagent', agent: p.agent,
                        title: p.title, color: p.color,
                        status: 'working', pills: [], card: null, message: null,
                        reasoning: '',
                    });
                    break;
                case 'subagent_reasoning_chunk': {
                    const sa = this.findLastAgent(p.agent);
                    if (sa) sa.reasoning = (sa.reasoning || '') + p.content;
                    break;
                }
                case 'subagent_tool_call': {
                    if (p.agent === 'main') {
                        this.items.push({
                            type: 'tool_pill',
                            id: p.id || '',
                            name: (p.name || '?').replace(/_/g, ' '),
                            status: p.status || 'running',
                            detail: p.detail || null,
                        });
                    } else {
                        const sa = this.findLastAgent(p.agent);
                        if (sa) sa.pills.push((p.name || '?').replace(/_/g, ' '));
                    }
                    break;
                }
                case 'subagent_tool_call_status': {
                    for (let i = this.items.length - 1; i >= 0; i--) {
                        if (this.items[i].type === 'tool_pill' && this.items[i].id === p.id) {
                            this.items[i].status = p.status || 'ok';
                            if (p.error) this.items[i].error = p.error;
                            break;
                        }
                    }
                    break;
                }
                case 'subagent_completed': {
                    const done = this.findLastAgent(p.agent);
                    if (done) { done.status = 'done'; done.card = p.card; }
                    break;
                }
                case 'subagent_error': {
                    const err = this.findLastAgent(p.agent);
                    if (err) { err.status = 'error'; err.message = p.message; }
                    break;
                }
                case 'turn_complete':
                case 'turn_interrupted':
                case 'turn_error':
                    this.items.forEach(i => {
                        if (i.type === 'subagent' && i.status === 'working') i.status = 'done';
                    });
                    this.live = false;
                    this.$wire.loadMessages();
                    break;
            }
        },

        findLastAgent(agent) {
            for (let i = this.items.length - 1; i >= 0; i--) {
                if (this.items[i].type === 'subagent' && this.items[i].agent === agent) {
                    return this.items[i];
                }
            }
            return null;
        },
    }));
});
