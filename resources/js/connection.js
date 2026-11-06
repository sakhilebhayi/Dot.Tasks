import { say } from './drag';

// Losing the connection must not lose work. Edits that give the same result if sent twice wait here and go out when
// the connection returns. Anything else (a comment, a new task) could land twice, so it stays in its box for a retry.
const SAFE_TWICE = new Set(['saveField', 'saveCustomField', 'saveCell', 'reschedule', 'setDates']);
const CHECK_EVERY = 5000;

const held = new Map();
let failing = [];
let timer = null;

const banner = () => document.getElementById('connection');

function lost() {
    document.body.classList.add('offline');
    banner()?.removeAttribute('hidden');
    timer ??= setInterval(check, CHECK_EVERY);
}

async function check() {
    try {
        const reply = await fetch('/up', { method: 'HEAD', cache: 'no-store' });
        if (reply.ok) back();
    } catch {
        // Still down: the next check tries again.
    }
}

function back() {
    clearInterval(timer);
    timer = null;
    document.body.classList.remove('offline');
    banner()?.setAttribute('hidden', '');

    const count = held.size;
    held.forEach(({ component, call }) => component.$wire[call.method](...call.params));
    held.clear();

    window.dispatchEvent(new CustomEvent('connection-back'));
    say(count ? `Back online. ${count === 1 ? 'Your change was' : `${count} changes were`} saved.` : 'Back online.', true);
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ component, commit, fail }) => {
        fail(() => {
            failing.push(...(commit.calls ?? []).filter((call) => SAFE_TWICE.has(call.method)).map((call) => ({ component, call })));
        });
    });

    // A reply from the server, even an error, means the connection is fine: only a request that got none is held.
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ content }) => {
            const waiting = failing;
            failing = [];
            if (content !== null) return;

            waiting.forEach((one) => held.set(`${one.component.id}:${one.call.method}:${one.call.params[0]}`, one));
            lost();
        });
    });

    window.addEventListener('online', check);
    window.addEventListener('offline', lost);
});
