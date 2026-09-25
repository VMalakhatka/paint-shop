(() => {
    const form = document.querySelector('.lw-request');
    if (!form || !form.querySelector('button[type=submit]')) return;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type=submit]');
        if (button.disabled) return;
        const result = form.querySelector('.lw-form-result');
        const original = button.innerHTML;
        button.disabled = true;
        button.textContent = lwPublic.sending;
        form.setAttribute('aria-busy', 'true');
        result.textContent = '';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        let errorMessage = lwPublic.error;
        try {
            const nonceUrl = new URL(lwPublic.endpoint);
            nonceUrl.searchParams.set('action', 'lw_nonce');
            nonceUrl.searchParams.set('workshop', form.elements.workshop.value);
            const nonceResponse = await fetch(nonceUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            const nonce = await nonceResponse.json();
            if (!nonceResponse.ok || !nonce.success) throw new Error(lwPublic.error);
            form.elements.nonce.value = nonce.data.nonce;
            const response = await fetch(lwPublic.endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin', signal: controller.signal });
            const data = await response.json();
            if (!response.ok || !data.success) {
                errorMessage = data.data?.message || lwPublic.error;
                throw new Error(errorMessage);
            }
            result.className = 'lw-form-result lw-success';
            result.textContent = data.data.message;
            button.hidden = true;
        } catch (error) {
            result.className = 'lw-form-result lw-error';
            result.textContent = errorMessage;
        } finally {
            clearTimeout(timer);
            button.disabled = false;
            button.innerHTML = original;
            form.removeAttribute('aria-busy');
            result.focus();
        }
    });
})();
