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
