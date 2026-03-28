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
