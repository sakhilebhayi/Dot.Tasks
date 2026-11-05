import Sortable from 'sortablejs';

// Drag for every list of cards, by pointer, touch and keyboard. The card moves at once; the server has the last word.
const still = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const items = (list) => [...list.querySelectorAll(':scope > [data-task]')];

export function say(message, loudly = false) {
    const live = document.getElementById('announce');
    if (live) live.textContent = message;
    if (!loudly) return;

    document.querySelectorAll('.flash[data-said]').forEach((old) => old.remove());
    const flash = document.createElement('div');
    flash.className = 'flash';
    flash.dataset.said = '';
    flash.textContent = message;
    document.body.append(flash);
    setTimeout(() => flash.remove(), 6000);
}

function putBack({ item, from, oldDraggableIndex }) {
    from.insertBefore(item, items(from).filter((card) => card !== item)[oldDraggableIndex] ?? null);
}

const LOST = 'That did not save, so the card is back where it was. Check your connection and try again.';

// Livewire never settles a call whose request failed, so a move waits here for either the answer or the failure.
const waiting = new Set();

function saving(send, lost) {
    return new Promise((resolve) => {
        const give = () => {
            waiting.delete(give);
            lost();
            say(LOST, true);
            resolve({ ok: false });
        };
        waiting.add(give);
        send().then((answer) => {
            waiting.delete(give);
            resolve(answer);
        }, give);
    });
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ preventDefault }) => {
            if (!waiting.size) return;
            preventDefault();
            [...waiting].forEach((give) => give());
        });
    });
});

function save(evt, send) {
    return saving(send, () => putBack(evt)).then((answer) => {
        if (answer?.ok === false && answer.message) say(answer.message);
    });
}

function sortable(element, { handle, canDrop, drop, keepOrder = false, sharing = 'cards' }) {
    let refused = null;
    const clear = () => {
        refused?.classList.remove('refuse');
        refused = null;
    };

    return Sortable.create(element, {
        group: sharing,
        sort: !keepOrder,
        draggable: '[data-task]',
        handle,
        filter: handle ? undefined : 'a, button, input, select, textarea',
        preventOnFilter: false,
        forceFallback: true,
        fallbackTolerance: 4,
        delay: 120,
        delayOnTouchOnly: true,
        touchStartThreshold: 4,
        animation: still() ? 0 : 150,
        easing: 'cubic-bezier(0.2, 0, 0, 1)',
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        emptyInsertThreshold: 24,
        scroll: true,
        bubbleScroll: true,
        scrollSensitivity: 48,
        scrollSpeed: 12,
        onStart() {
            document.body.classList.add('dragging');
        },
        onMove(evt) {
            clear();
            const refusal = canDrop?.(evt.dragged, evt.to);
            if (!refusal) return true;
            refused = evt.to;
            refused.classList.add('refuse');
            say(refusal);
            return false;
        },
        onEnd(evt) {
            clear();
            document.body.classList.remove('dragging');
            const moved = evt.from !== evt.to || evt.oldDraggableIndex !== evt.newDraggableIndex;
            if (moved) drop(evt);
        },
    });
}

const nextId = (item) => {
    let next = item.nextElementSibling;
    while (next && !next.dataset?.task) next = next.nextElementSibling;
    return next ? Number(next.dataset.task) : null;
};

const blockedFromDone = (card, list) => (list.dataset.status === 'done' && card.dataset.blocked !== undefined ? 'A blocked task cannot be finished yet.' : null);

// The tasks on screen come back every 20 seconds, unless someone is in the middle of something.
function pulse(seconds = 20) {
    return {
        init() {
            this.timer = setInterval(() => {
                const busy = document.hidden || document.body.classList.contains('dragging') || document.activeElement?.matches('input, textarea, select');
                if (!busy) this.$wire.refreshBoard();
            }, seconds * 1000);
        },
        destroy() {
            clearInterval(this.timer);
        },
    };
}

// One column of the board.
function boardColumn() {
    return {
        init() {
            this.sorter = sortable(this.$el, {
                handle: this.$el.dataset.handle,
                keepOrder: this.$el.dataset.status === undefined,
                canDrop: blockedFromDone,
                drop: (evt) => save(evt, () => this.send(evt.item, evt.to, evt.item.dataset.status, nextId(evt.item))),
            });
        },
        destroy() {
            this.sorter?.destroy();
        },
        send(card, list, from, before) {
            return this.$wire.place(Number(card.dataset.task), list.dataset.status ?? from, before, from, list.dataset.lane ?? null);
        },
        keys(event) {
            const card = event.target.closest('[data-task]');
            if (!card || !event.altKey || !event.key.startsWith('Arrow')) return;
            event.preventDefault();

            const here = this.$el;
            const lists = [...(here.closest('[data-rack]')?.querySelectorAll('[data-list]') ?? [])];
            const siblings = items(here);
            const index = siblings.indexOf(card);
            const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
            let target = here;
            let before;

            if (!step && here.dataset.status === undefined) return;

            if (step) {
                target = lists[lists.indexOf(here) + step];
                if (!target) return;
                before = null;
            } else if (event.key === 'ArrowUp') {
                if (index === 0) return;
                before = Number(siblings[index - 1].dataset.task);
            } else {
                if (index === siblings.length - 1) return;
                before = siblings[index + 2] ? Number(siblings[index + 2].dataset.task) : null;
            }

            const refusal = blockedFromDone(card, target);
            if (refusal) return say(refusal, true);

            const id = card.dataset.task;
            const title = card.dataset.title;
            saving(() => this.send(card, target, card.dataset.status, before), () => {}).then((answer) => {
                if (answer?.ok === false) return answer.message && say(answer.message);
                say(step ? `${title} moved to ${target.dataset.name}.` : `${title} moved.`);
                this.$nextTick(() => {
                    const moved = document.querySelector(`[data-task="${id}"]`);
                    (moved?.querySelector('.grip') ?? moved)?.focus();
                });
            });
        },
    };
}

// The subtasks in the task panel.
function stepList(method) {
    return {
        init() {
            this.sorter = sortable(this.$el, {
                handle: '.grip',
                sharing: method,
                drop: (evt) => save(evt, () => this.$wire[method](Number(evt.item.dataset.task), nextId(evt.item))),
            });
        },
        destroy() {
            this.sorter?.destroy();
        },
        keys(event, id) {
            const step = { ArrowUp: -1, ArrowDown: 1 }[event.key];
            if (!step) return;
            event.preventDefault();

            const list = this.$root;
            const row = event.target.closest('[data-task]');
            const siblings = items(list);
            const index = siblings.indexOf(row);
            if (!siblings[index + step]) return;

            const before = step < 0 ? Number(siblings[index - 1].dataset.task) : (siblings[index + 2] ? Number(siblings[index + 2].dataset.task) : null);
            saving(() => this.$wire[method](id, before), () => {}).then((answer) => {
                if (answer?.ok === false) return;
                say('Moved.');
                this.$nextTick(() => list.querySelector(`[data-task="${id}"] .grip`)?.focus());
            });
        },
    };
}

const shifted = (date, days) => {
    const moved = new Date(`${date}T12:00:00Z`);
    moved.setUTCDate(moved.getUTCDate() + days);
    return moved.toISOString().slice(0, 10);
};

// One day of the calendar. Cards keep no order inside a day: the date is what moves.
function dayList() {
    return {
        init() {
            this.sorter = sortable(this.$el, {
                keepOrder: true,
                sharing: 'days',
                drop: (evt) => save(evt, () => this.$wire.reschedule(Number(evt.item.dataset.task), evt.to.dataset.date || null, evt.item.dataset.date || null)),
            });
        },
        destroy() {
            this.sorter?.destroy();
        },
        keys(event) {
            const card = event.target.closest('[data-task]');
            const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
            if (!card || !event.altKey || !step || !card.dataset.date) return;
            event.preventDefault();

            const id = card.dataset.task;
            const date = shifted(card.dataset.date, step);
            saving(() => this.$wire.reschedule(Number(id), date, card.dataset.date), () => {}).then((answer) => {
                if (answer?.ok === false) return answer.message && say(answer.message);
                say(`${card.dataset.title} moved to ${date}.`);
                this.$nextTick(() => document.querySelector(`[data-task="${id}"]`)?.focus());
            });
        },
    };
}

// Bars on the timeline: drag the middle to move, an end to change the length, or Alt and the arrows to do either.
function timeline(dayWidth) {
    return {
        held: null,
        down(event) {
            const bar = event.target.closest('[data-bar]');
            if (!bar || event.button !== 0) return;

            const edge = event.target.closest('[data-edge]')?.dataset.edge ?? null;
            this.held = { bar, edge, x: event.clientX, left: bar.offsetLeft, width: bar.offsetWidth, days: 0, id: event.pointerId };
            bar.setPointerCapture(event.pointerId);
            document.body.classList.add('dragging');
        },
        move(event) {
            const held = this.held;
            if (!held || event.pointerId !== held.id) return;

            const span = Math.round(held.width / dayWidth);
            let days = Math.round((event.clientX - held.x) / dayWidth);
            if (held.edge === 'start') days = Math.min(days, span - 1);
            if (held.edge === 'due') days = Math.max(days, 1 - span);
            held.days = days;

            held.bar.classList.toggle('moving', days !== 0);
            held.bar.style.left = `${held.left + (held.edge === 'due' ? 0 : days * dayWidth)}px`;
            held.bar.style.width = `${held.width + (held.edge === 'start' ? -days : held.edge === 'due' ? days : 0) * dayWidth}px`;
        },
        up(event) {
            const held = this.held;
            if (!held || event.pointerId !== held.id) return;

            this.held = null;
            document.body.classList.remove('dragging');
            held.bar.classList.remove('moving');
            if (held.days === 0) return this.$dispatch('open-task', { taskId: Number(held.bar.dataset.task) });

            const put = () => {
                held.bar.style.left = `${held.left}px`;
                held.bar.style.width = `${held.width}px`;
            };
            this.commit(held.bar, held.edge, held.days, put);
        },
        commit(bar, edge, days, put) {
            const { task, start, due } = bar.dataset;
            const nextStart = edge === 'due' ? start : shifted(start, days);
            const nextDue = edge === 'start' ? due : shifted(due, days);

            return saving(() => this.$wire.setDates(Number(task), nextStart, nextDue, start, due), put).then((answer) => {
                if (answer?.ok === false) return answer.message && say(answer.message);
                say(`${bar.dataset.title} runs ${nextStart} to ${nextDue}.`);
                this.$nextTick(() => document.querySelector(`[data-bar][data-task="${task}"]`)?.focus());
            });
        },
        keys(event) {
            const bar = event.target.closest('[data-bar]');
            const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
            if (bar && !event.altKey && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                return this.$dispatch('open-task', { taskId: Number(bar.dataset.task) });
            }
            if (!bar || !event.altKey || !step) return;
            event.preventDefault();

            if (event.shiftKey && step < 0 && bar.dataset.start === bar.dataset.due) return;
            this.commit(bar, event.shiftKey ? 'due' : null, step, () => {});
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dayList', dayList);
    window.Alpine.data('timeline', timeline);
    window.Alpine.data('boardColumn', boardColumn);
    window.Alpine.data('stepList', stepList);
    window.Alpine.data('pulse', pulse);
});
