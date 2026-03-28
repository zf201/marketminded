// Alpine.js component: contentPiece
// Consolidates approve/reject/improve/proofread logic from initCornerstonePipeline
// and openContentModal into a reusable component for content piece actions.
//
// Config shape:
// {
//   projectID: number|string,
//   runID: number|string,
//   pieceID: number|string,
// }

document.addEventListener('alpine:init', function() {
    Alpine.data('contentPiece', function(config) {
        return {
            basePath: '/projects/' + config.projectID + '/pipeline/' + config.runID,

            // --- Approve ---

            approve() {
                var self = this;
                var btn = this.$el.querySelector('.piece-approve-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Approving...';
                }

                fetch(self.basePath + '/piece/' + config.pieceID + '/approve', { method: 'POST' })
                    .then(function() { window.location.reload(); })
                    .catch(function() { window.location.reload(); });
            },

            // --- Reject ---

            reject() {
                var self = this;
                var reason = prompt('Why should this be rejected?');
                if (reason === null) return;

                fetch(self.basePath + '/piece/' + config.pieceID + '/reject', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'reason=' + encodeURIComponent(reason)
                }).then(function() { window.location.reload(); });
            },

            // --- Improve (opens content modal) ---

            openImprove() {
                var bodyEl = document.getElementById('piece-body-' + config.pieceID);
                openContentModal({
                    mode: 'improve',
                    pieceId: config.pieceID,
                    platform: bodyEl ? bodyEl.dataset.platform : '',
                    format: bodyEl ? bodyEl.dataset.format : '',
                    basePath: this.basePath
                });
            },

            // --- Proofread (opens content modal) ---

            proofread() {
                var bodyEl = document.getElementById('piece-body-' + config.pieceID);
                openContentModal({
                    mode: 'proofread',
                    pieceId: config.pieceID,
                    platform: bodyEl ? bodyEl.dataset.platform : '',
                    format: bodyEl ? bodyEl.dataset.format : '',
                    basePath: this.basePath
                });
            }
        };
    });
});
