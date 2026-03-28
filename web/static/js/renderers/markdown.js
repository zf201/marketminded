function renderMarkdown(text) {
    var div = document.createElement('div');
    div.className = 'markdown-body';
    div.style.fontSize = '0.85rem';
    try {
        div.innerHTML = marked.parse(text.replace(/\\n/g, '\n'), { breaks: false, gfm: true });
    } catch(e) {
        div.textContent = text;
    }
    return div;
}
