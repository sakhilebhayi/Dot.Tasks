// The phone menu, the scrim, the scroll lock and focus handling are the shared header's (dot-ui dot-nav.js, drawer-target="shell-drawer").
// This file keeps the platform's own behaviour: the theme, New task, and the find keys.

// The theme is chosen in the account menu (dot-ui sets data-theme and stores dot-theme: light, dark or system). This app's tokens
// follow the .dark class, so it is kept in step with that choice, and with the system when the choice is "system".
function theme() {
    const dark = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => {
        let stored = null;
        try {
            stored = localStorage.getItem('dot-theme');
        } catch {
            // No storage: follow the system.
        }
        document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && dark.matches));
    };
    document.addEventListener('dot:theme', apply);
    dark.addEventListener?.('change', apply);
    apply();
}

theme();

// Who owns which key (R-52):
//   Ctrl K / Cmd K  the find palette (Livewire FindTasks), unchanged. The pill in the header has no "Ctrl K" hint on purpose: dot-ui's
//                   hint also makes dot-search.js bind Ctrl K to the pill, and two owners of one chord would fight.
//   /               the header search (the pill; on a phone it opens the search sheet).
//   q               New task.
// Keys are left alone while someone is typing in a field.
function shortcuts() {
    const send = (name) => window.Livewire?.dispatch(name);

    const focusPill = () => {
        const input = document.querySelector('[data-find-pill] .dsr-input');
        if (!input) return false;
        if (input.getClientRects().length === 0) {
            // A phone: the pill lives in the search sheet, behind the bar's search button.
            document.querySelector('[data-dh-search-toggle]')?.click();
        } else {
            input.focus();
        }
        return true;
    };

    document.addEventListener('click', (event) => {
        const quickAdd = event.target.closest?.('[data-open-quick-add]');
        if (quickAdd) {
            // A link carrying this goes to the Inbox when Livewire is not there to open the box, or when it is opened in a new tab.
            const followsLink = quickAdd.matches('a[href]') && (!window.Livewire || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey);
            if (!followsLink) {
                if (quickAdd.matches('a[href]')) event.preventDefault();
                send('open-quick-add');
            }
        }
        // A task picked in the header search opens the task panel: on a phone the search sheet must not stay over it.
        const picked = event.target.closest?.('[data-find-result]');
        if (picked) {
            // The box empties (a focused field keeps its text through a Livewire morph, so say it here too) and the phone sheet closes.
            const input = picked.closest('.dsr')?.querySelector('.dsr-input');
            if (input && input.value !== '') {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            input?.blur();
            window.DotNav?.closeSearch?.();
        }
    });

    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest?.('input:not([readonly]), textarea, select, [contenteditable]');
        if (typing || event.altKey || event.isComposing) return;
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            send('open-search');
            return;
        }
        if (event.metaKey || event.ctrlKey) return;
        if (event.key === 'q') {
            event.preventDefault();
            send('open-quick-add');
        }
        if (event.key === '/' && focusPill()) {
            event.preventDefault();
        }
    });
}

shortcuts();

// The header search's list: Arrow keys move a highlight (dot-ui styles .is-active, the list logic is the host's), Enter opens the
// highlighted row, and with nothing highlighted Enter submits the form, which opens the first match (HeaderSearch::go).
function findList() {
    const rows = (input) => [...(input.closest('.dsr')?.querySelectorAll('.dsr-panel [data-find-result]') ?? [])];
    const mark = (input, list, index) => {
        list.forEach((row, i) => {
            row.classList.toggle('is-active', i === index);
            row.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });
        list[index]?.scrollIntoView({ block: 'nearest' });
    };

    document.addEventListener('keydown', (event) => {
        const input = event.target;
        if (!input.matches?.('[data-find-pill] .dsr-input')) return;
        const list = rows(input);
        const current = list.findIndex((row) => row.classList.contains('is-active'));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (list.length === 0) return;
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            mark(input, list, current === -1 ? (step === 1 ? 0 : list.length - 1) : (current + step + list.length) % list.length);
        } else if (event.key === 'Enter' && current !== -1) {
            event.preventDefault();
            list[current].click();
        }
    });
}

findList();

// The visible viewport height (it shrinks when the on-screen keyboard opens), for full-height sheets: the find palette on a phone.
// One custom property through the CSSOM (allowed by style-src 'self'); without visualViewport the CSS falls back to 100dvh.
function visibleViewport() {
    const vv = window.visualViewport;
    if (!vv) return;
    const write = () => document.documentElement.style.setProperty('--tk-vv-h', `${Math.round(vv.height)}px`);
    vv.addEventListener('resize', write);
    write();
}

visibleViewport();
