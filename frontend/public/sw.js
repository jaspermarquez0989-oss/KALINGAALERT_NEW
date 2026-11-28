self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data?.json() ?? {};
    } catch (error) {
        console.error('Could not parse a browser push notification payload.', error);
    }

    event.waitUntil(self.registration.showNotification(payload.title ?? 'KalingaAlert', {
        body: payload.body ?? 'There is a new community update.',
        data: {
            url: payload.url ?? '/dashboard',
        },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    let targetUrl;
    try {
        targetUrl = new URL(event.notification.data?.url ?? '/', self.location.origin);
    } catch (error) {
        console.error('Could not read the browser notification destination.', error);
        targetUrl = new URL('/', self.location.origin);
    }

    if (targetUrl.origin !== self.location.origin) {
        targetUrl = new URL('/', self.location.origin);
    }

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
        const client = clients.find((candidate) => new URL(candidate.url).origin === self.location.origin);

        if (client) {
            return client.navigate(targetUrl.href).then(() => client.focus());
        }

        return self.clients.openWindow(targetUrl.href);
    }));
});
