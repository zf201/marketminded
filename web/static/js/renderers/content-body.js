function renderPlan(el) {
    var raw = el.textContent.trim();
    if (!raw) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    el.textContent = '';

    if (data.cornerstone) {
        var h = document.createElement('div');
        h.className = 'font-semibold text-base mb-2';
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

    if (data && data.instructions) {
        renderSection(el, 'Production Notes', data.instructions, { minor: true, markdown: true });
    }
}

function renderBlogPost(el, data) {
    renderSection(el, 'Title', data.title);
    renderSection(el, 'Body', data.body, { markdown: true });
    if (data.body) {
        var copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-secondary btn-xs mt-1';
        copyBtn.textContent = 'Copy Markdown';
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
    var items = document.createElement('div');
    items.className = 'space-y-1 text-sm';
    data.tweets.forEach(function(tweet, i) {
        var item = document.createElement('div');
        item.className = 'flex gap-2';
        var num = document.createElement('span');
        num.className = 'font-semibold opacity-60';
        num.textContent = (i + 1) + '.';
        item.appendChild(num);
        item.appendChild(document.createTextNode(tweet));
        items.appendChild(item);
    });
    sec.appendChild(items);
}

function renderLinkedinCarousel(el, data) {
    if (data.slides) {
        var sec = renderSection(el, 'Slides (' + data.slides.length + ')', null, { force: true });
        data.slides.forEach(function(slide, i) {
            var card = document.createElement('div');
            card.className = 'card bg-zinc-800/50 mb-1';
            var body = document.createElement('div');
            body.className = 'card-body p-2';
            var title = document.createElement('div');
            title.className = 'font-semibold text-sm';
            title.textContent = 'Slide ' + (i + 1) + (slide.title ? ': ' + slide.title : '');
            body.appendChild(title);
            if (slide.body) {
                var text = document.createElement('div');
                text.className = 'text-sm';
                text.textContent = slide.body;
                body.appendChild(text);
            }
            card.appendChild(body);
            sec.appendChild(card);
        });
    }
    renderSection(el, 'Caption', data.caption);
}

function renderInstagramCarousel(el, data) {
    if (data.slides) {
        var sec = renderSection(el, 'Slides (' + data.slides.length + ')', null, { force: true });
        data.slides.forEach(function(slide, i) {
            var card = document.createElement('div');
            card.className = 'card bg-zinc-800/50 mb-1';
            var body = document.createElement('div');
            body.className = 'card-body p-2';
            var title = document.createElement('div');
            title.className = 'font-semibold text-sm';
            title.textContent = 'Slide ' + (i + 1);
            body.appendChild(title);
            if (slide.text) {
                var text = document.createElement('div');
                text.className = 'text-sm';
                text.textContent = slide.text;
                body.appendChild(text);
            }
            card.appendChild(body);
            sec.appendChild(card);
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
            var div = document.createElement('div');
            div.className = 'mb-2';
            var heading = document.createElement('div');
            heading.className = 'text-xs font-medium opacity-70 mb-0.5';
            heading.textContent = (s.timestamp ? '[' + s.timestamp + '] ' : '') + s.heading;
            div.appendChild(heading);
            var content = document.createElement('div');
            content.className = 'text-sm';
            content.textContent = s.content;
            div.appendChild(content);
            if (s.notes) {
                var n = document.createElement('div');
                n.className = 'text-xs italic opacity-60';
                n.textContent = '[' + s.notes + ']';
                div.appendChild(n);
            }
            sec.appendChild(div);
        });
    }
}
