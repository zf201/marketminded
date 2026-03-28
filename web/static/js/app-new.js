// Entry point — handles any remaining page-level initialization
document.addEventListener('DOMContentLoaded', function() {
    // Auto-scroll chat messages to bottom on page load
    document.querySelectorAll('.chat-messages').forEach(function(el) {
        el.scrollTop = el.scrollHeight;
    });
});
