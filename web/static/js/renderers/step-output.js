function renderSection(parent, label, content, opts) {
    opts = opts || {};
    if (!content && !opts.force) return;
    var sec = document.createElement('div');
    sec.className = 'mb-3';
    var lbl = document.createElement('div');
    lbl.className = opts.minor ? 'text-xs font-medium opacity-70 mb-1' : 'text-sm font-semibold mb-1';
    lbl.textContent = label;
    sec.appendChild(lbl);
    if (opts.markdown && typeof marked !== 'undefined' && content) {
        var md = document.createElement('div');
        md.className = 'prose prose-sm max-w-none';
        var cleaned = content.replace(/\\n/g, '\n').replace(/\n{3,}/g, '\n\n');
        md.innerHTML = marked.parse(cleaned, { breaks: false, gfm: true });
        sec.appendChild(md);
    } else if (opts.badges && content) {
        var badges = document.createElement('div');
        badges.className = 'flex flex-wrap gap-1';
        content.split(/\s+/).forEach(function(tag) {
            if (!tag) return;
            var b = document.createElement('span');
            b.className = 'badge badge-sm badge-ghost';
            b.textContent = tag;
            badges.appendChild(b);
        });
        sec.appendChild(badges);
    } else if (content) {
        var txt = document.createElement('div');
        txt.className = 'text-sm';
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

function makeSubcard(title, contentEl) {
    var card = document.createElement('div');
    card.className = 'card card-compact bg-base-100 border border-base-300 mb-2';
    var body = document.createElement('div');
    body.className = 'card-body p-3';
    if (title) {
        var h = document.createElement('div');
        h.className = 'font-semibold text-sm mb-1';
        h.textContent = title;
        body.appendChild(h);
    }
    body.appendChild(contentEl);
    card.appendChild(body);
    return card;
}

function renderSourcesSubcard(sources) {
    var wrapper = document.createElement('div');
    var list = document.createElement('ul');
    list.className = 'text-sm list-disc pl-4 space-y-1';
    sources.forEach(function(s) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = s.url;
        a.textContent = s.title || s.url;
        a.target = '_blank';
        a.className = 'link link-primary font-semibold';
        li.appendChild(a);
        if (s.date) {
            var dateSpan = document.createElement('span');
            dateSpan.textContent = ' (' + s.date + ')';
            dateSpan.className = 'opacity-60';
            li.appendChild(dateSpan);
        }
        if (s.summary) {
            var sumDiv = document.createElement('div');
            sumDiv.textContent = s.summary;
            sumDiv.className = 'opacity-70 text-xs';
            li.appendChild(sumDiv);
        }
        list.appendChild(li);
    });
    wrapper.appendChild(list);
    return makeSubcard('Sources (' + sources.length + ')', wrapper);
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
            list.className = 'text-sm list-disc pl-4 space-y-1';
            data.posts.forEach(function(p) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = p.url;
                a.textContent = p.title || p.url;
                a.target = '_blank';
                a.className = 'link link-primary';
                li.appendChild(a);
                list.appendChild(li);
            });
            var wrapper = document.createElement('div');
            wrapper.appendChild(list);
            el.appendChild(makeSubcard('Posts Analyzed (' + data.posts.length + ')', wrapper));
        }
    } else if (typeName === 'Fact-Checker') {
        var issuesContent = document.createElement('div');
        if (data.issues_found && data.issues_found.length > 0) {
            data.issues_found.forEach(function(issue) {
                var row = document.createElement('div');
                row.className = 'mb-2 text-sm';
                var claim = document.createElement('strong');
                claim.textContent = issue.claim;
                row.appendChild(claim);
                if (issue.problem) {
                    var prob = document.createElement('div');
                    prob.textContent = issue.problem;
                    prob.className = 'text-error';
                    row.appendChild(prob);
                }
                if (issue.resolution) {
                    var res = document.createElement('div');
                    res.textContent = issue.resolution;
                    res.className = 'text-success';
                    row.appendChild(res);
                }
                issuesContent.appendChild(row);
            });
            el.appendChild(makeSubcard('Issues (' + data.issues_found.length + ')', issuesContent));
        } else {
            var ok = document.createElement('div');
            ok.textContent = 'No issues found.';
            ok.className = 'text-success font-semibold text-sm';
            el.appendChild(makeSubcard('Issues', ok));
        }
        if (data.enriched_brief) el.appendChild(makeSubcard('Enriched Brief', renderMarkdown(data.enriched_brief)));
        if (data.sources && data.sources.length > 0) el.appendChild(renderSourcesSubcard(data.sources));
    } else if (typeName === 'Editor') {
        if (data.angle) {
            var angleDiv = document.createElement('div');
            angleDiv.className = 'text-sm';
            angleDiv.textContent = data.angle;
            el.appendChild(makeSubcard('Angle', angleDiv));
        }
        if (data.sections && data.sections.length > 0) {
            var sectionsContent = document.createElement('div');
            data.sections.forEach(function(sec) {
                var row = document.createElement('div');
                row.className = 'mb-2 p-2 bg-base-200 rounded text-sm';
                var heading = document.createElement('strong');
                heading.textContent = sec.heading;
                if (sec.framework_beat) heading.textContent += ' (' + sec.framework_beat + ')';
                row.appendChild(heading);
                if (sec.key_points && sec.key_points.length > 0) {
                    var ul = document.createElement('ul');
                    ul.className = 'list-disc pl-4 mt-1 space-y-0.5';
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
                    note.className = 'italic opacity-60 mt-1';
                    row.appendChild(note);
                }
                sectionsContent.appendChild(row);
            });
            el.appendChild(makeSubcard('Sections (' + data.sections.length + ')', sectionsContent));
        }
        if (data.conclusion_strategy) {
            var concDiv = document.createElement('div');
            concDiv.className = 'text-sm';
            concDiv.textContent = data.conclusion_strategy;
            el.appendChild(makeSubcard('Conclusion', concDiv));
        }
    } else {
        el.textContent = JSON.stringify(data, null, 2);
        el.style.whiteSpace = 'pre-wrap';
        el.className += ' text-sm';
    }
}
