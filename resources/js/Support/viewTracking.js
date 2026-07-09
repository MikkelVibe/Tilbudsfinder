function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function recordOfferView(url) {
    if (!url) {
        return;
    }

    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
    }).catch(() => {});
}
