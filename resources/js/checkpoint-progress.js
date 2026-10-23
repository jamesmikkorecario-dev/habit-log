// A preview of the current selection only. Saving and analytics still derive
// outcomes on the server from the selected date's immutable checkpoint setup.
function registerCheckpointProgress() {
    window.Alpine.data('checkpointProgress', (ids) => ({
        ids: ids.map(String),
        get completed() {
            const selected = this.$wire.form.completed;
            if (!Array.isArray(selected)) return 0;
            return this.ids.filter(id => selected.map(String).includes(id)).length;
        },
        get summary() {
            if (this.$wire.form.status === 'not_applicable') return 'Not applicable · Neutral';
            const total = this.ids.length;
            if (!total) return 'Checkpoint setup unavailable. Reload this entry.';
            const completed = this.completed;
            const assessment = completed === total ? 'Completed' : (completed ? 'Partial' : 'Missed');
            const percentage = Number((completed / total * 100).toFixed(1));
            return `${completed} of ${total} completed · ${percentage}% · ${assessment}`;
        },
    }));
}

document.addEventListener('alpine:init', registerCheckpointProgress);
if (window.Alpine) registerCheckpointProgress();
