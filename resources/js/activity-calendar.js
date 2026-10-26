function registerActivityCalendar() {
    window.Alpine.data('activityCalendar', (minimal = false) => ({
        index: 0,
        preview: null,
        previewDate: null,
        previewLeft: 0,
        previewTop: 0,
        dismissed: false,
        init() {
            this.$nextTick(() => { this.$refs.scroll.scrollLeft = 0; });
        },
        move(event) {
            if (!event.target.matches('[data-date]')) return;
            const steps = { ArrowLeft: -7, ArrowRight: 7, ArrowUp: -1, ArrowDown: 1, Home: -366, End: 366 };
            if (!(event.key in steps)) return;
            event.preventDefault();
            const buttons = this.$refs.days.querySelectorAll('button');
            this.index = Math.max(0, Math.min(buttons.length - 1, this.index + steps[event.key]));
            buttons[this.index].focus();
        },
        showPreview(button, pointer = false) {
            if (!minimal || this.detailOpen || (pointer && this.dismissed)) return;
            if (!pointer) this.dismissed = false;
            this.previewDate = button.dataset.date;
            this.preview = {
                date: button.dataset.date,
                outcome: button.dataset.previewOutcome,
                counts: button.dataset.previewCounts,
            };
            this.$nextTick(() => this.positionPreview());
        },
        positionPreview() {
            if (!this.previewDate) return;
            const button = this.$refs.days.querySelector(`[data-date='${this.previewDate}']`);
            if (!button) return this.hidePreview();
            const rect = button.getBoundingClientRect();
            const region = this.$refs.scroll.getBoundingClientRect();
            if (rect.right < region.left || rect.left > region.right || rect.bottom < 0 || rect.top > window.innerHeight) {
                this.hidePreview();
            } else {
                const tooltip = this.$refs.preview.getBoundingClientRect();
                this.previewLeft = Math.max(12, Math.min(rect.left + rect.width / 2 - tooltip.width / 2, window.innerWidth - tooltip.width - 12));
                // Touch opens the persistent details panel. This hover/focus
                // preview touches its trigger so the pointer can enter it.
                this.previewTop = rect.top >= tooltip.height + 12
                    ? rect.top - tooltip.height
                    : rect.bottom;
            }
        },
        hidePreview() {
            this.preview = null;
            this.previewDate = null;
        },
        dismissPreview() {
            // Hiding a hovered preview can uncover another date beneath it.
            // Ignore that synthetic mouseenter until the pointer actually moves.
            this.dismissed = true;
            this.hidePreview();
        },
        leavePreview() {
            if (this.dismissed) return;
            const focused = document.activeElement;
            if (this.$refs.days.contains(focused) && focused.matches('[data-date]')) {
                this.showPreview(focused);
            } else {
                this.hidePreview();
            }
        },
    }));
}

document.addEventListener('alpine:init', registerActivityCalendar);
if (window.Alpine) registerActivityCalendar();
