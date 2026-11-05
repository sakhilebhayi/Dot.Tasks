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

function sortable(element, { handle, canDrop, drop, sharing = 'cards' }) {
    let refused = null;
    const clear = () => {
        refused?.classList.remove('refuse');
        refused = null;
    };

    return Sortable.create(element, {
        group: sharing,
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

// One column of the board.
function boardColumn() {
    return {
        init() {
            this.sorter = sortable(this.$el, {
                canDrop: blockedFromDone,
                drop: (evt) => save(evt, () => this.send(evt.item, evt.to, evt.from.dataset.status, nextId(evt.item))),
            });
        },
        destroy() {
            this.sorter?.destroy();
        },
        send(card, list, from, before) {
            return this.$wire.place(Number(card.dataset.task), list.dataset.status, before, from, list.dataset.lane ?? null);
        },
        keys(event) {
            const card = event.target.closest('[data-task]');
            if (!card || !event.altKey || !event.key.startsWith('Arrow')) return;
            event.preventDefault();

            const here = this.$el;
            const lists = [...(here.closest('[data-rack]')?.querySelectorAll('[data-status]') ?? [])];
            const siblings = items(here);
            const index = siblings.indexOf(card);
            const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
            let target = here;
            let before;

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
            saving(() => this.send(card, target, here.dataset.status, before), () => {}).then((answer) => {
                if (answer?.ok === false) return answer.message && say(answer.message);
                say(step ? `${title} moved to ${target.dataset.name}.` : `${title} moved.`);
                this.$nextTick(() => document.querySelector(`[data-task="${id}"]`)?.focus());
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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardColumn', boardColumn);
    window.Alpine.data('stepList', stepList);
});
