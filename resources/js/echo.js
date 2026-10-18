import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb speaks the Pusher protocol, so laravel-echo's 'reverb' broadcaster
// is really just its Pusher connector pointed at our own server. Only
// initialize it when the keys are actually configured (BROADCAST_CONNECTION
// defaults to "log" for local dev/CI -- see .env.example) so the app
// doesn't try to open a websocket connection to nothing.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            withCredentials: true,
        },
    });
}
