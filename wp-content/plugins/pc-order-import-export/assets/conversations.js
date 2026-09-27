(() => {
  const box = document.querySelector('.pcoe-chat-messages[data-live="1"]');
  if (!box || !window.pcoeChat) return;
  let busy = false;
  box.scrollTop = box.scrollHeight;
  const refresh = async () => {
    if (busy || document.hidden) return;
    busy = true;
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 12000);
    try {
      const url = new URL(pcoeChat.url);
      url.search = new URLSearchParams({action: 'pcoe_chat_poll', nonce: pcoeChat.nonce, chat_id: box.dataset.chat});
      const response = await fetch(url, {credentials: 'same-origin', signal: abort.signal, cache: 'no-store'});
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error();
      const bottom = box.scrollHeight - box.scrollTop - box.clientHeight < 50;
      if (box.innerHTML !== data.data.html) box.innerHTML = data.data.html;
      if (bottom) box.scrollTop = box.scrollHeight;
      document.querySelector('.pcoe-chat-error').textContent = '';
    } catch (_) { document.querySelector('.pcoe-chat-error').textContent = pcoeChat.error; }
    finally { clearTimeout(timeout); busy = false; }
  };
  setInterval(refresh, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
