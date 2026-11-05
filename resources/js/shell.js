// The drawer below 860px, and the light and dark switch. Plain JS, so the menu works whatever else fails.
function drawer() {
    const button = document.getElementById('shell-menu-button');
    const panel = document.getElementById('shell-drawer');
    const backdrop = document.getElementById('shell-backdrop');
    if (!button || !panel || !backdrop) return;

    const root = document.documentElement;
    const wide = window.matchMedia('(min-width: 860px)');
    const close = panel.querySelector('.shell-drawer-close');
    const pages = [...document.querySelectorAll('[data-shell-page]')];
    let open = false;

    const inert = (element, on) => (on ? element.setAttribute('inert', '') : element.removeAttribute('inert'));

    function render() {
        panel.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        root.classList.toggle('shell-locked', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        pages.forEach((element) => inert(element, open));
        // Closed on a phone, the drawer must not be focusable. On wide screens it is just the sidebar.
        inert(panel, !wide.matches && !open);
    }

    function set(next, returnFocus) {
        if (next && wide.matches) return;
        open = next;
        render();
        if (open) (close ?? panel.querySelector('a')).focus();
        else if (returnFocus) button.focus();
    }

    button.addEventListener('click', () => set(!open, false));
    backdrop.addEventListener('click', () => set(false, true));
    close?.addEventListener('click', () => set(false, true));
    document.addEventListener('keydown', (event) => {
        if (open && event.key === 'Escape') {
            event.preventDefault();
            set(false, true);
        }
    });
    // Following a link leaves the page: close without pulling focus back.
    panel.addEventListener('click', (event) => {
        if (open && event.target.closest?.('a[href]')) set(false, false);
    });
    wide.addEventListener('change', () => {
        if (wide.matches) open = false;
        render();
    });
    window.addEventListener('pageshow', () => {
        open = false;
        render();
    });
    render();
}

function theme() {
    document.getElementById('theme-toggle')?.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark');
        try {
            localStorage.setItem('dot-theme', dark ? 'dark' : 'light');
        } catch {
            // The choice just lasts for this page.
        }
    });
}

drawer();
theme();

// New task and search, from a button or a key. Keys are left alone while someone is typing.
function shortcuts() {
    const send = (name) => window.Livewire?.dispatch(name);

    document.addEventListener('click', (event) => {
        if (event.target.closest?.('[data-open-quick-add]')) send('open-quick-add');
        if (event.target.closest?.('[data-open-search]')) send('open-search');
    });
    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
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
        if (event.key === '/') {
            event.preventDefault();
            send('open-search');
        }
    });
}

shortcuts();
