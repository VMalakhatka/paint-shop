const state = {
  session: null,
  csrf: null,
  selectedInvoice: null,
  preview: null,
};

const $ = (selector) => document.querySelector(selector);

async function api(path, options = {}) {
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (state.csrf && (options.method || "GET") !== "GET") {
    headers["X-CSRF-Token"] = state.csrf;
  }
  const response = await fetch(path, { credentials: "same-origin", ...options, headers });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.error?.message || `HTTP ${response.status}`);
    error.code = payload.error?.code;
    error.details = payload.error?.details || {};
    throw error;
  }
  return payload;
}

function showAlert(message, type = "error") {
  const alert = $("#alert");
  alert.textContent = message;
  alert.classList.remove("hidden", "success");
  if (type === "success") alert.classList.add("success");
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function clearAlert() {
  $("#alert").classList.add("hidden");
}

function money(cents) {
  return new Intl.NumberFormat("uk-UA", { style: "currency", currency: "UAH" }).format(cents / 100);
}

function shortDate(value) {
  return new Intl.DateTimeFormat("uk-UA", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function setMode(config) {
  const badge = $("#mode-badge");
  const mock = config.checkbox_mode === "mock";
  badge.className = `mode-badge ${mock ? "mock" : "api"}`;
  badge.textContent = mock ? "MOCK · чеков нет" : "API · реальный apply заблокирован";
}

function renderSession(payload) {
  state.session = payload;
  state.csrf = payload.csrf_token;
  setMode(payload.config);
  $("#login-panel").classList.toggle("hidden", payload.authenticated);
  $("#cashier-panel").classList.toggle("hidden", !payload.authenticated || Boolean(payload.cashier));
  $("#workspace").classList.toggle("hidden", !payload.authenticated || !payload.cashier);
  if (payload.authenticated && payload.cashier) {
    $("#context-manager").textContent = payload.manager;
    $("#context-cashier").textContent = payload.cashier.cashier_name;
    $("#context-register").textContent = `${payload.cashier.cash_register_label} · ${payload.cashier.shift_status}`;
    $("#context-environment").textContent = payload.cashier.environment.toUpperCase();
    loadInvoices();
    loadOperations();
  }
}

async function bootstrap() {
  try {
    renderSession(await api("/api/session"));
  } catch (error) {
    showAlert(error.message);
  }
}

$("#login-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  clearAlert();
  try {
    const payload = await api("/api/login", {
      method: "POST",
      body: JSON.stringify({
        username: $("#manager-username").value,
        password: $("#manager-password").value,
      }),
    });
    $("#manager-password").value = "";
    renderSession(payload);
  } catch (error) {
    $("#manager-password").value = "";
    showAlert(error.message);
  }
});

$("#cashier-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  clearAlert();
  const pin = $("#cashier-pin").value;
  try {
    const payload = await api("/api/cashier/login", {
      method: "POST",
      body: JSON.stringify({ pin }),
    });
    $("#cashier-pin").value = "";
    renderSession(payload);
  } catch (error) {
    $("#cashier-pin").value = "";
    showAlert(error.message);
  }
});

async function logout() {
  const config = state.session?.config || { checkbox_mode: "mock" };
  try {
    await api("/api/logout", { method: "POST", body: "{}" });
  } finally {
    state.csrf = null;
    state.session = null;
    renderSession({ authenticated: false, manager: null, cashier: null, csrf_token: null, config });
  }
}

$("#manager-logout").addEventListener("click", logout);
$("#workspace-logout").addEventListener("click", logout);
$("#cashier-logout").addEventListener("click", async () => {
  try {
    renderSession(await api("/api/cashier/logout", { method: "POST", body: "{}" }));
  } catch (error) {
    showAlert(error.message);
  }
});

async function loadInvoices() {
  const params = new URLSearchParams();
  if ($("#date-from").value) params.set("date_from", $("#date-from").value);
  if ($("#date-to").value) params.set("date_to", $("#date-to").value);
  try {
    const payload = await api(`/api/invoices?${params.toString()}`);
    const rows = $("#invoice-rows");
    rows.replaceChildren();
    for (const invoice of payload.documents) {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(shortDate(invoice.document_date))}</td>
        <td><strong>${escapeHtml(invoice.document_number)}</strong></td>
        <td>${escapeHtml(invoice.customer_display)}</td>
        <td>${escapeHtml(invoice.warehouse_display)}</td>
        <td class="money">${escapeHtml(money(invoice.total_cents))}</td>
        <td><span class="status ${invoice.eligible ? "ready" : "blocked"}">${invoice.eligible ? "Готова к preview" : "Заблокирована"}</span></td>
        <td><button type="button" data-source-id="${escapeHtml(invoice.source_id)}" ${invoice.eligible ? "" : "disabled"}>Открыть</button></td>`;
      rows.appendChild(tr);
    }
    rows.querySelectorAll("button[data-source-id]").forEach((button) => {
      button.addEventListener("click", () => openPreview(button.dataset.sourceId));
    });
  } catch (error) {
    showAlert(error.message);
  }
}

$("#refresh-invoices").addEventListener("click", loadInvoices);

async function openPreview(sourceId) {
  clearAlert();
  try {
    const payload = await api(`/api/invoices/${encodeURIComponent(sourceId)}`);
    state.selectedInvoice = payload.document;
    state.preview = null;
    $("#preview-title").textContent = `Накладная ${payload.document.document_number}`;
    $("#payment-type").value = payload.document.suggested_payment_type;
    $("#operation-revision").value = "1";
    $("#payment-confirmed").checked = false;
    $("#preview-result").classList.add("hidden");
    $("#apply-operation").classList.add("hidden");
    renderPreviewDetails(payload.document);
    $("#preview-dialog").showModal();
  } catch (error) {
    showAlert(error.message);
  }
}

function renderPreviewDetails(invoice) {
  const items = invoice.items.map((item) => `
    <tr>
      <td>${escapeHtml(item.sku)}</td>
      <td>${escapeHtml(item.name)}</td>
      <td>${item.quantity_thousandths / 1000}</td>
      <td class="money">${escapeHtml(money(item.price_cents))}</td>
      <td>${item.tax_codes.join(", ")}</td>
      <td class="money">${escapeHtml(money(item.line_total_cents))}</td>
    </tr>`).join("");
  $("#preview-details").innerHTML = `
    <div class="preview-summary">
      <div><span>Клиент</span><strong>${escapeHtml(invoice.customer_display)}</strong></div>
      <div><span>Склад</span><strong>${escapeHtml(invoice.warehouse_display)}</strong></div>
      <div><span>Итого</span><strong>${escapeHtml(money(invoice.total_cents))}</strong></div>
    </div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Код</th><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Налог</th><th>Сумма</th></tr></thead>
        <tbody>${items}</tbody>
      </table>
    </div>`;
}

$("#prepare-preview").addEventListener("click", async () => {
  if (!state.selectedInvoice) return;
  try {
    const payload = await api(`/api/invoices/${encodeURIComponent(state.selectedInvoice.source_id)}/preview`, {
      method: "POST",
      body: JSON.stringify({
        payment_type: $("#payment-type").value,
        payment_confirmed: $("#payment-confirmed").checked,
        revision: Number($("#operation-revision").value),
      }),
    });
    state.preview = payload;
    const result = $("#preview-result");
    result.innerHTML = `
      <strong>Preview сохранён</strong><br>
      <small>Ключ: ${escapeHtml(payload.operation.operation_key)}<br>Статус: ${escapeHtml(payload.operation.status)}</small>`;
    result.classList.remove("hidden");
    const apply = $("#apply-operation");
    apply.textContent = payload.apply_available ? "Сымитировать фискализацию" : "Реальный apply заблокирован";
    apply.disabled = !payload.apply_available;
    apply.classList.remove("hidden");
    loadOperations();
  } catch (error) {
    showAlert(error.message);
  }
});

$("#apply-operation").addEventListener("click", async () => {
  if (!state.preview) return;
  const exact = state.preview.operation;
  if (!window.confirm(`Подтвердить MOCK-операцию для накладной ${state.selectedInvoice.document_number} на сумму ${money(exact.total_cents)}?`)) return;
  try {
    const payload = await api("/api/fiscalize", {
      method: "POST",
      body: JSON.stringify({
        operation_key: exact.operation_key,
        request_hash: state.preview.preview.request_hash,
        confirmed: true,
      }),
    });
    $("#preview-dialog").close();
    showAlert(`${payload.replayed ? "Повтор распознан" : "Симуляция завершена"}: ${payload.operation.status}`, "success");
    await loadOperations();
  } catch (error) {
    $("#preview-dialog").close();
    showAlert(error.message);
    await loadOperations();
  }
});

async function loadOperations() {
  try {
    const payload = await api("/api/operations");
    const list = $("#operations");
    list.replaceChildren();
    if (!payload.operations.length) {
      list.innerHTML = '<p class="muted">Операций пока нет.</p>';
      return;
    }
    for (const operation of payload.operations) {
      const article = document.createElement("article");
      article.className = "operation";
      const statusClass = operation.status.toLowerCase();
      article.innerHTML = `
        <div>
          <code>${escapeHtml(operation.operation_key)}</code>
          <div class="operation-meta">Накладная ${escapeHtml(operation.source_id)} · ${escapeHtml(money(operation.total_cents))} · попыток ${operation.attempts}</div>
        </div>
        <span class="status ${escapeHtml(statusClass)}">${escapeHtml(operation.status)}</span>`;
      list.appendChild(article);
    }
  } catch (error) {
    showAlert(error.message);
  }
}

$("#refresh-operations").addEventListener("click", loadOperations);

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

bootstrap();
