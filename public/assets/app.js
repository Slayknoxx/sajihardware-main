let csrfToken = '';
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const api = (action, data, method = 'POST') => fetch(`../api.php?action=${action}`, {method, headers:{'Content-Type':'application/json', ...(csrfToken ? {'X-CSRF-Token':csrfToken} : {})}, body:data ? JSON.stringify(data) : undefined}).then(async r=>{const j=await r.json(); if(!r.ok){const error=new Error(j.error||'Request failed'); error.code=j.code; error.license=j.license; throw error;} return j});
const paymentApi = (path, data, method = 'POST') => fetch(`../api/${path}`, {method, credentials:'same-origin', headers:{'Content-Type':'application/json', ...(csrfToken ? {'X-CSRF-Token':csrfToken} : {})}, body:data ? JSON.stringify(data) : undefined}).then(async r=>{const j=await r.json(); if(!r.ok) throw new Error(j.error||'Payment request failed'); return j});
const state = {user:null, permissions:{}, pages:[], products:[], cart:[], page:'dashboard', license:null, company:null, setupCompleted:true, checkoutKey:null, checkoutPayload:null, auditPage:1, people:{customers:[],suppliers:[]}, cashEntries:[], staff:{employees:[],branches:[]}, customerOptions:[], returnSale:null, poLines:[]};
const PAYMENT_METHODS = ['Cash', 'M-Pesa', 'Card', 'Bank', 'Mobile Money', 'Voucher', 'Credit'];
const money = n => `KES ${Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;
const showPaymentLoading = (label='Waiting for M-Pesa confirmation...') => {
  const modal = document.getElementById('paymentLoadingModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  const text = document.getElementById('paymentLoadingText');
  if (text) text.textContent = label;
};
const hidePaymentLoading = () => {
  const modal = document.getElementById('paymentLoadingModal');
  if (!modal) return;
  modal.classList.add('hidden');
};
const receiptDate = d => new Date(d).toLocaleString('en-KE',{dateStyle:'medium',timeStyle:'short'});
const toast = msg => {const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2600)};
const qs = s => document.querySelector(s);
const qsa = s => [...document.querySelectorAll(s)];
const formData = f => Object.fromEntries(new FormData(f).entries());
const normalizeMpesaPhone = (value = '') => {
  const digits = String(value || '').replace(/\D/g, '').replace(/^254/, '');
  const rest = digits.slice(0, 9);
  return '254' + rest;
};
const maskMpesaPhone = (value = '') => {
  const digits = String(value || '').replace(/\D/g, '');
  if (digits.length < 7) return '****';
  return `${digits.slice(0, 3)}****${digits.slice(-3)}`;
};
const enforceMpesaInput = () => {
  const input = qs('#mpesaPhone');
  if (!input) return;
  input.setAttribute('maxlength', '9');
  input.setAttribute('inputmode', 'numeric');
  input.value = String(input.value || '').replace(/\D/g, '').replace(/^254/, '').slice(0, 9);
};
let healthTimer = null;

const pages = [
  ['dashboard','Dashboard',['ADMIN','MANAGER']],
  ['sales','Sales',['ADMIN','MANAGER','EMPLOYEE']],
  ['products','Products',['ADMIN','MANAGER','EMPLOYEE']],
  ['people','Customers & Suppliers',['ADMIN','MANAGER']],
  ['quotations','Quotations',['ADMIN','MANAGER','EMPLOYEE']],
  ['analytics','Analytics',['ADMIN','MANAGER']],
  ['cashflow','Cash Flow',['ADMIN','MANAGER']],
  ['stockanalytics','Stock Analytics',['ADMIN','MANAGER']],
  ['admin','Admin',['ADMIN','MANAGER']],
  ['reports','Reports & Backups',['ADMIN','MANAGER']],
  ['settings','Settings',['ADMIN','MANAGER','EMPLOYEE']],
];
const navIcons = {
  dashboard: '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
  sales: '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>',
  products: '<path d="M21 8.5 12 3 3 8.5v7L12 21l9-5.5z"/><path d="M3 8.5 12 14l9-5.5"/><path d="M12 14v7"/>',
  people: '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0"/><circle cx="17.5" cy="9.5" r="2.4"/><path d="M15.5 15.6a5 5 0 0 1 5.7 4.4"/>',
  quotations: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
  analytics: '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
  cashflow: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>',
  stockanalytics: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/>',
  admin: '<path d="M12 3 4.5 6v5.5c0 4.4 3.2 7.6 7.5 9.5 4.3-1.9 7.5-5.1 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
  reports: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 10h6M9 14h6M9 18h4"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
};

async function boot(){
  state.user = null;
  state.permissions = {};
  state.pages = [];
  qs('#app').classList.add('hidden');
  qs('#login').classList.remove('hidden');
  startConnectionMonitor();

  try {
    const me = await api('me', null, 'GET');
    csrfToken = me.csrf || csrfToken;
    state.license = me.license || null;
    state.company = me.company || state.company;
    state.setupCompleted = me.setup_completed !== false;
    state.permissions = me.permissions || {};
    state.pages = me.pages || [];
    if (me.user && me.user.id) {
      state.user = me.user;
      showApp();
      maybeShowSetup();
      return;
    }
  } catch (err) {
    console.warn('Session check failed:', err.message || err);
  }

  qs('#login').classList.remove('hidden');
  qs('#app').classList.add('hidden');
  const form = qs('#loginForm');
  if (form) form.reset();
}
function setConnectionStatus(online, label){
  const pill = qs('#connectionPill');
  if (!pill) return;
  pill.textContent = label || (online ? 'Online' : 'Offline');
  pill.classList.toggle('online', online);
  pill.classList.toggle('offline', !online);
}
async function checkConnection(){
  if (!navigator.onLine) {
    setConnectionStatus(false, 'Offline');
    return;
  }
  try {
    await api('health', null, 'GET');
    setConnectionStatus(true, 'Online');
  } catch (e) {
    setConnectionStatus(false, 'Offline');
  }
}
function startConnectionMonitor(){
  checkConnection();
  window.addEventListener('online', checkConnection);
  window.addEventListener('offline', () => setConnectionStatus(false, 'Offline'));
  if (!healthTimer) healthTimer = setInterval(checkConnection, 15000);
}
function canViewSales(){
  return ['ADMIN','MANAGER'].includes(state.user?.role);
}
function applyRoleVisibility(){
  const canManageProducts = ['ADMIN','MANAGER'].includes(state.user?.role);
  qs('#productForm')?.classList.toggle('hidden', !canManageProducts);
  qs('#restockPanel')?.classList.toggle('hidden', !canManageProducts);
  qs('#stockMovementsPanel')?.classList.toggle('hidden', !canManageProducts);
  qs('#recentSalesPanel')?.classList.toggle('hidden', !canViewSales());
  const isAdmin = state.user?.role === 'ADMIN';
  qsa('.admin-only').forEach(element => element.classList.toggle('hidden', !isAdmin));
}
function showApp(){
  qs('#login').classList.add('hidden'); qs('#app').classList.remove('hidden');
  qs('#userPill').textContent = `${state.user.full_name || state.user.username} · ${state.user.role}`;
  applyBranding();
  applyRoleVisibility();
  renderNav(); renderLicenseBanner(); openPage(defaultPage()).catch(err=>toast(err.message));
}
function applyBranding(){
  const c = state.company;
  if (!c) return;
  qs('#loginLogo')?.setAttribute('src', c.logo);
  qs('#brandLogo')?.setAttribute('src', c.logo);
  qs('#loginLogo')?.setAttribute('alt', c.name || '');
  qs('#brandLogo')?.setAttribute('alt', c.name || '');
  document.title = `${c.name || 'SAJI POS'} POS`;
  const subtitle = qs('#companySubtitle');
  if (subtitle) subtitle.textContent = [c.name, c.email, c.location].filter(Boolean).join(' · ');
  if (c.color && /^#[0-9a-fA-F]{6}$/.test(c.color)) {
    document.documentElement.style.setProperty('--brand', c.color);
  }
}
function maybeShowSetup(){
  if (state.setupCompleted || state.user?.role !== 'ADMIN') return;
  const modal = qs('#setupWizard');
  if (modal) modal.classList.remove('hidden');
}
function renderLicenseBanner(){
  const banner = qs('#licenseBanner');
  if (!banner) return;
  const l = state.license;
  if (!l || l.state === 'active') { banner.classList.add('hidden'); return; }
  let text = l.message || '';
  let danger = false;
  if (l.state === 'trial') {
    text = `Free trial: ${l.days_left} day${l.days_left === 1 ? '' : 's'} left. Activate a license from Settings.`;
  } else if (l.state === 'grace') {
    text = `License expired: ${l.days_left} day${l.days_left === 1 ? '' : 's'} of grace remaining. Renew from Settings.`;
    danger = true;
  } else if (l.state === 'expired') {
    text = 'Your license has expired. Enter a renewed key from Settings.';
    danger = true;
  } else if (l.state === 'trial_expired') {
    text = 'Your free trial has ended. Enter a license key from Settings to continue.';
    danger = true;
  } else if (l.state === 'revoked' || l.state === 'invalid') {
    text = l.message || 'This installation is not licensed.';
    danger = true;
  } else if (l.state === 'clock_tampered') {
    text = l.message || 'The system clock appears to have been moved backwards. Correct the date and time, then enter a valid license key.';
    danger = true;
  }
  banner.textContent = text;
  banner.classList.toggle('danger', danger);
  banner.classList.remove('hidden');
}
function allowedPages(){
  if (state.pages?.length) return state.pages;
  return pages.filter(p=>p[2].includes(state.user?.role)).map(p=>p[0]);
}
function defaultPage(){
  const allowed = allowedPages();
  return pages.find(p=>allowed.includes(p[0]))?.[0] || 'sales';
}
function renderNav(){
  const allowed = allowedPages();
  qs('#nav').innerHTML = pages.filter(p=>allowed.includes(p[0])).map(p=>`<button data-page="${p[0]}" title="${p[1]}" aria-label="${p[1]}"><svg viewBox="0 0 24 24" aria-hidden="true">${navIcons[p[0]] || ''}</svg><span class="nav-label">${p[1]}</span></button>`).join('');
  qsa('#nav button').forEach(b=>b.onclick=()=>openPage(b.dataset.page).catch(err=>toast(err.message)));
}
const SIDEBAR_STORAGE_KEY = 'saji.sidebar.collapsed';
const SIDEBAR_RAIL_MAX_WIDTH = 980;
let sidebarPreference = false;
let sidebarNarrowOverride = null;

function sidebarMediaQuery(){
  return window.matchMedia(`(max-width: ${SIDEBAR_RAIL_MAX_WIDTH}px)`);
}
function sidebarIsNarrow(){
  return sidebarMediaQuery().matches;
}
function sidebarIsCollapsed(){
  return qs('#app')?.classList.contains('sidebar-collapsed') || false;
}
function setSidebarCollapsed(collapsed){
  const app = qs('#app');
  if (!app) return;
  app.classList.toggle('sidebar-collapsed', collapsed);
  const toggle = qs('#sidebarToggle');
  if (toggle) {
    const label = collapsed ? 'Expand menu' : 'Collapse menu';
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', label);
    toggle.title = label;
  }
}
function syncSidebar(){
  // Narrow/split windows default to the icon rail, but a click can still widen it.
  const collapsed = sidebarIsNarrow()
    ? (sidebarNarrowOverride === null ? true : sidebarNarrowOverride)
    : sidebarPreference;
  setSidebarCollapsed(collapsed);
}
function initSidebar(){
  try { sidebarPreference = localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1'; } catch (err) { }
  const query = sidebarMediaQuery();
  const onBreakpointChange = () => { sidebarNarrowOverride = null; syncSidebar(); };
  if (query.addEventListener) query.addEventListener('change', onBreakpointChange);
  else if (query.addListener) query.addListener(onBreakpointChange);
  syncSidebar();
  qs('#sidebarToggle')?.addEventListener('click', () => {
    const collapsed = !sidebarIsCollapsed();
    if (sidebarIsNarrow()) {
      sidebarNarrowOverride = collapsed;
    } else {
      sidebarPreference = collapsed;
      try { localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0'); } catch (err) { }
    }
    syncSidebar();
  });
}
async function openPage(page){
  if(!allowedPages().includes(page)) page = defaultPage();
  state.page=page; qsa('.view').forEach(v=>v.classList.add('hidden')); qs('#'+page).classList.remove('hidden');
  qsa('#nav button').forEach(b=>b.classList.toggle('active', b.dataset.page===page));
  qs('#pageTitle').textContent = pages.find(p=>p[0]===page)?.[1] || 'POS';
  if(page==='dashboard') await loadDashboard();
  if(page==='sales') await loadSales();
  if(page==='products') await loadProducts();
  if(page==='people') await loadPeople();
  if(page==='quotations') await loadQuotations();
  if(page==='analytics') await loadAnalytics();
  if(page==='cashflow') await loadCashFlow();
  if(page==='stockanalytics') await loadStockAnalytics();
  if(page==='admin') await loadAdmin();
  if(page==='reports') await loadReports();
  if(page==='settings') await loadSettings();
}
function table(rows){
  if(!rows?.length) return '<p class="muted">No records yet.</p>';
  const keys = Object.keys(rows[0]).filter(k=>!String(rows[0][k]).startsWith('$2y$'));
  return `<table><thead><tr>${keys.map(k=>`<th>${esc(k.replaceAll('_',' '))}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${keys.map(k=>`<td>${esc(r[k] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
async function loadDashboard(){
  const d = await api('dashboard', null, 'GET');
  qs('#dashboard').innerHTML = `<div class="kpis">
    <section class="panel kpi">Today sales<strong>${money(d.today.total)}</strong><span>${d.today.count} transactions</span></section>
    <section class="panel kpi">This month<strong>${money(d.month_total)}</strong></section>
    <section class="panel kpi">Stock value<strong>${state.permissions['cost.view'] ? money(d.stock_value) : 'Restricted'}</strong></section>
    <section class="panel kpi">Low stock<strong>${d.low_stock}</strong></section>
  </div><section class="panel" style="margin-top:16px"><h2>Recent sales</h2>${table(d.recent_sales)}</section>`;
}
async function loadSales(){
  state.products = (await api('list&table=products', null, 'GET')).rows;
  const heldSales = (await api('list&table=held_sales', null, 'GET')).rows;
  renderProducts(); renderCart(); renderHeldSales(heldSales);
  if (canViewSales()) {
    const salesRows = (await api('list&table=sales', null, 'GET')).rows;
    renderSalesHistory(salesRows, '#salesHistoryTable');
  }
  await Promise.all([refreshCustomerOptions('#customerSelect'), refreshReturnsPanel(), refreshShiftPanel()]);
}
async function refreshCustomerOptions(selector){
  const target = qs(selector);
  if (!target) return;
  try {
    state.customerOptions = (await api('customer_options', null, 'GET')).rows || [];
  } catch (error) {
    state.customerOptions = [];
  }
  const previous = target.value;
  target.innerHTML = '<option value="" selected>Walk-in Customer</option>'
    + state.customerOptions.map(customer => `<option value="${esc(customer.id)}">${esc(customer.name)}${customer.phone ? ` · ${esc(customer.phone)}` : ''}</option>`).join('');
  if (previous && state.customerOptions.some(customer => String(customer.id) === previous)) target.value = previous;
}
function renderReturnsTable(rows){
  const target = qs('#returnsTable');
  if (!target) return;
  if (!rows?.length) {
    target.innerHTML = '<p class="muted">No returns recorded yet.</p>';
    return;
  }
  target.innerHTML = `<table><thead><tr><th>Date</th><th>Receipt</th><th>Product</th><th>Qty</th><th>Amount</th><th>Refund</th><th>Reason</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(receiptDate(row.created_at))}</td><td>${esc(row.receipt_no || '—')}</td><td>${esc(row.product_name || '—')}</td><td>${esc(row.quantity || '')}</td><td>${money(row.amount)}</td><td>${esc(row.refund_method || '—')}</td><td>${esc(row.reason || '—')}</td></tr>`).join('')}</tbody></table>`;
}
async function refreshReturnsPanel(){
  try {
    const rows = (await api('list&table=returns', null, 'GET')).rows || [];
    renderReturnsTable(rows);
  } catch (error) {
    const target = qs('#returnsTable');
    if (target) target.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
async function lookupReturnSale(){
  const canManage = ['ADMIN','MANAGER'].includes(state.user?.role);
  const receiptInput = qs('#returnReceipt');
  const itemsTarget = qs('#returnSaleItems');
  const submitRow = qs('#returnSubmitRow');
  if (!canManage) {
    toast('Only admins and managers can record returns');
    return;
  }
  const receipt = receiptInput.value.trim();
  if (!receipt) {
    toast('Enter a receipt number first');
    return;
  }
  try {
    const result = await api(`sale_lookup&receipt=${encodeURIComponent(receipt)}`, null, 'GET');
    state.returnSale = result.sale;
    const open = result.sale.items.filter(item => Number(item.quantity) - Number(item.returned_quantity) > 0);
    if (!open.length) {
      state.returnSale = null;
      itemsTarget.innerHTML = '<p class="muted">Every line on this receipt has already been fully returned.</p>';
      submitRow.classList.add('hidden');
      return;
    }
    itemsTarget.innerHTML = `<table><thead><tr><th>Product</th><th>Unit price</th><th>Sold</th><th>Returned</th><th>Return qty</th></tr></thead><tbody>${open.map(item => `<tr><td>${esc(item.product_name)}</td><td>${money(item.unit_price)}</td><td>${esc(item.quantity)}</td><td>${esc(item.returned_quantity)}</td><td><input type="number" min="0" max="${Number(item.quantity) - Number(item.returned_quantity)}" step="1" value="0" data-return-qty="${item.id}"></td></tr>`).join('')}</tbody></table>`;
    // Refunds default to the way the customer paid; credit sales never took
    // money in, so they default to no refund.
    const refundSelect = qs('#returnRefundMethod');
    if (refundSelect) {
      const paidWith = result.sale.payment_method;
      const known = [...refundSelect.options].some(option => option.value === paidWith);
      refundSelect.value = known ? paidWith : 'None';
    }
    submitRow.classList.remove('hidden');
  } catch (error) {
    state.returnSale = null;
    itemsTarget.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
    submitRow.classList.add('hidden');
  }
}
async function recordReturn(){
  const canManage = ['ADMIN','MANAGER'].includes(state.user?.role);
  if (!canManage) return toast('Only admins and managers can record returns');
  const sale = state.returnSale;
  if (!sale) return toast('Load a receipt first');
  const lines = qsa('[data-return-qty]')
    .map(input => ({item_id: Number(input.dataset.returnQty), quantity: Number(input.value) || 0}))
    .filter(line => line.quantity > 0);
  if (!lines.length) return toast('Enter a quantity to return on at least one line');
  if (lines.length > 1) return toast('Record one product line per return');
  const reason = qs('#returnReason').value.trim();
  const refundMethod = qs('#returnRefundMethod')?.value || '';
  try {
    const result = await api('create_return', {sale_id: sale.id, sale_item_id: lines[0].item_id, quantity: lines[0].quantity, reason, refund_method: refundMethod});
    toast(`Return recorded: ${result.product_name} ×${result.quantity} (${result.refund_method} refund)`);
    state.returnSale = null;
    qs('#returnSaleItems').innerHTML = '';
    qs('#returnSubmitRow').classList.add('hidden');
    qs('#returnReason').value = '';
    qs('#returnReceipt').value = '';
    await refreshReturnsPanel();
    await refreshShiftPanel();
  } catch (error) {
    toast(error.message);
  }
}
function renderShiftPanel(shift){
  const current = qs('#shiftCurrent');
  const openForm = qs('#shiftOpenForm');
  const closeForm = qs('#shiftCloseForm');
  if (!current || !openForm || !closeForm) return;
  if (shift) {
    const movements = [];
    if (Number(shift.cash_in) > 0) movements.push(`+${money(shift.cash_in)} cash in`);
    if (Number(shift.cash_out) > 0) movements.push(`−${money(shift.cash_out)} cash out`);
    if (Number(shift.cash_refunds) > 0) movements.push(`−${money(shift.cash_refunds)} refunds`);
    const detail = movements.length ? ` <span class="muted">(${movements.join(', ')})</span>` : '';
    current.innerHTML = `<p>Shift open since ${esc(receiptDate(shift.opened_at))} · opening float ${money(shift.opening_float)} · expected cash <b>${money(shift.cash_expected)}</b>${detail}</p>`;
    openForm.classList.add('hidden');
    closeForm.classList.remove('hidden');
  } else {
    current.innerHTML = '<p class="muted">No open shift. Set the opening float to start one.</p>';
    openForm.classList.remove('hidden');
    closeForm.classList.add('hidden');
  }
}
async function refreshShiftPanel(){
  try {
    const result = await api('shift_current', null, 'GET');
    renderShiftPanel(result.shift);
  } catch (error) {
    const current = qs('#shiftCurrent');
    if (current) current.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
async function shiftOpen(){
  const openingFloat = qs('#shiftOpeningFloat').value;
  if (openingFloat === '' || Number(openingFloat) < 0) return toast('Enter the opening float');
  try {
    await api('shift_open', {opening_float: openingFloat});
    qs('#shiftOpeningFloat').value = '';
    toast('Shift opened');
    await refreshShiftPanel();
  } catch (error) {
    toast(error.message);
  }
}
async function shiftClose(){
  const declared = qs('#shiftCashDeclared').value;
  if (declared === '' || Number(declared) < 0) return toast('Enter the cash counted in the till');
  try {
    const result = await api('shift_close', {cash_declared: declared});
    qs('#shiftCashDeclared').value = '';
    const varianceLabel = result.variance === 0 ? 'balanced' : (result.variance > 0 ? 'over' : 'short');
    toast(`Shift closed. Expected ${money(result.cash_expected)}, declared ${money(result.cash_declared)} (${varianceLabel} ${money(Math.abs(result.variance))})`);
    await refreshShiftPanel();
  } catch (error) {
    toast(error.message);
  }
}
function renderSalesHistory(rows, targetSelector){
  const target = qs(targetSelector);
  if (!rows.length) {
    target.innerHTML = '<p class="muted">No completed sales yet.</p>';
    return;
  }
  target.innerHTML = `<table><thead><tr><th>Receipt</th><th>Customer</th><th>Total</th><th>Time</th><th>Actions</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.receipt_no)}</td><td>${esc(row.customer_name || 'Walk-in Customer')}</td><td>${money(row.total)}</td><td>${esc(receiptDate(row.created_at))}</td><td><button class="secondary" data-print-receipt="${row.id}">Print</button>${state.permissions['sale.void'] ? ` <button class="danger" data-void-sale="${row.id}">Void</button>` : ''}</td></tr>`).join('')}</tbody></table>`;
  qsa(`${targetSelector} [data-print-receipt]`).forEach(btn => btn.onclick = () => printReceipt(+btn.dataset.printReceipt).catch(error=>toast(error.message)));
  qsa(`${targetSelector} [data-void-sale]`).forEach(btn => btn.onclick = () => voidSale(+btn.dataset.voidSale));
}
function renderHeldSales(rows){
  const target = qs('#heldSalesList');
  if (!rows.length) {
    target.innerHTML = '<p class="muted">No held sales.</p>';
    return;
  }
  target.innerHTML = rows.map(row => {
    const cart = JSON.parse(row.cart_json || '[]');
    const itemCount = cart.reduce((total, item) => total + (+item.qty || 0), 0);
    return `<div class="held-sale"><div><b>${esc(row.hold_no)}</b><span>${esc(row.customer_name || 'Walk-in Customer')} · ${itemCount} item${itemCount === 1 ? '' : 's'} · ${esc(receiptDate(row.created_at))}</span></div><button class="secondary" type="button" data-resume-hold="${row.id}">Continue</button></div>`;
  }).join('');
  qsa('[data-resume-hold]').forEach(button => button.onclick = () => resumeHeldSale(+button.dataset.resumeHold));
}
async function resumeHeldSale(id){
  try {
    const held = await api('resume_hold', {id});
    state.cart = held.cart.map(item => ({id:+item.id, name:item.name, price:+item.price, qty:+item.qty}));
    qs('#customerName').value = held.customer_name || 'Walk-in Customer';
    renderCart();
    await loadSales();
    toast(`Continuing ${held.hold_no}`);
  } catch (error) {
    toast(error.message);
  }
}
function renderProducts(){
  const term = qs('#productSearch').value.toLowerCase();
  const rows = state.products.filter(p=>[p.sku,p.barcode,p.name,p.category].join(' ').toLowerCase().includes(term));
  qs('#productTiles').innerHTML = rows.map(p=>`<button class="product-tile" data-id="${p.id}"><b>${esc(p.name)}</b><small>${esc(p.sku)} · ${esc(p.barcode||'no barcode')}</small><span>${money(p.selling_price)}</span><small class="${+p.quantity<=+p.reorder_level?'stock-low':''}">Stock: ${esc(p.quantity)}</small></button>`).join('');
  qsa('.product-tile').forEach(b=>b.onclick=()=>addToCart(+b.dataset.id));
}
function addToCart(id){
  const p=state.products.find(x=>+x.id===id); if(!p || +p.quantity<1) return toast('No stock available');
  const line=state.cart.find(x=>x.id===id); line ? line.qty++ : state.cart.push({id, name:p.name, price:+p.selling_price, qty:1});
  renderCart();
}
function renderCart(){
  const paymentMethod = qs('#paymentMethod').value;
  const phoneInput = qs('#mpesaPhone');
  const phoneWrap = qs('#mpesaPhoneWrap');
  if (phoneInput) phoneInput.classList.toggle('hidden', paymentMethod !== 'M-Pesa');
  if (phoneWrap) phoneWrap.classList.toggle('hidden', paymentMethod !== 'M-Pesa');
  if (paymentMethod !== 'M-Pesa') {
    if (phoneInput) phoneInput.value = '';
  }
  enforceMpesaInput();

  const creditHint = qs('#creditHint');
  if (creditHint) creditHint.classList.toggle('hidden', paymentMethod !== 'Credit');

  qs('#cartLines').innerHTML = state.cart.length ? state.cart.map(x=>`<div class="cart-line"><div><b>${esc(x.name)}</b><small>${money(x.price*x.qty)}</small></div><input type="number" min="1" value="${esc(x.qty)}" data-qty="${x.id}"><button data-remove="${x.id}">×</button></div>`).join('') : '<p class="muted">Scan or tap products to add them.</p>';
  qsa('[data-qty]').forEach(i=>i.onchange=()=>{state.cart.find(x=>x.id===+i.dataset.qty).qty=Math.max(1,+i.value);renderCart()});
  qsa('[data-remove]').forEach(b=>b.onclick=()=>{state.cart=state.cart.filter(x=>x.id!==+b.dataset.remove);renderCart()});
  const subtotal=state.cart.reduce((s,x)=>s+x.price*x.qty,0), discount=+qs('#discount').value||0, total=Math.max(0,subtotal-discount), paid=+qs('#amountPaid').value||total, change = paymentMethod === 'Cash' ? Math.max(0, paid-total) : 0;
  const onCredit = paymentMethod === 'Credit' && paid < total ? total - paid : 0;
  qs('#totals').innerHTML = `<div><span>Subtotal</span><b>${money(subtotal)}</b></div><div><span>VAT</span><b>Calculated by server</b></div><div><span>Discount</span><b>${money(discount)}</b></div><div class="grand"><span>Total</span><b>${money(total)}</b></div><div><span>Amount paid</span><b>${money(paid)}</b></div>${onCredit ? `<div><span>On credit</span><b>${money(onCredit)}</b></div>` : ''}<div><span>Change</span><b>${money(change)}</b></div>`;
}
function resetCheckoutForm(){
  state.cart = [];
  state.checkoutKey = null;
  state.checkoutPayload = null;
  const customerSelect = qs('#customerSelect');
  if (customerSelect) customerSelect.value = '';
  qs('#customerName').value = '';
  qs('#customerName').disabled = false;
  qs('#paymentMethod').value = '';
  qs('#mpesaPhone').value = '';
  qs('#discount').value = '';
  qs('#amountPaid').value = '';
  renderCart();
}
async function checkout(){
  const paymentMethod = qs('#paymentMethod').value;
  if (!paymentMethod) {
    throw new Error('Select a payment method.');
  }
  const customerSelect = qs('#customerSelect');
  const customerId = customerSelect && customerSelect.value ? customerSelect.value : '';
  if (paymentMethod === 'Credit' && !customerId) {
    throw new Error('Credit sales require a saved customer.');
  }
  let mpesaPhone = '';
  if (paymentMethod === 'M-Pesa') {
    mpesaPhone = normalizeMpesaPhone(qs('#mpesaPhone').value);
    if (!/^254\d{9,10}$/.test(mpesaPhone)) {
      throw new Error('Enter a valid 254-format mobile number.');
    }
    qs('#mpesaPhone').value = mpesaPhone.replace(/^254/, '');
  }
  const payload={cart:state.cart.map(item=>({id:item.id,qty:item.qty})), customer_id:customerId, customer_name:qs('#customerName').value, payment_method:paymentMethod, discount:+qs('#discount').value||0, amount_paid:+qs('#amountPaid').value||0, mpesa_phone: paymentMethod === 'M-Pesa' ? mpesaPhone : ''};
  const requestSignature = JSON.stringify(payload);
  if (!state.checkoutKey || state.checkoutPayload !== requestSignature) {
    state.checkoutKey = globalThis.crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
    state.checkoutPayload = requestSignature;
  }
  payload.idempotency_key = state.checkoutKey;
  const r=await api('checkout', payload);
  if (r.payment_status === 'PAYMENT_FAILED' || r.payment_status === 'VOID') {
    state.checkoutKey = null;
    state.checkoutPayload = null;
    toast(r.payment_status === 'VOID' ? 'This sale was voided.' : 'The previous M-Pesa payment failed. Retry checkout to create a new sale.');
    await loadSales();
    return;
  }
  if (r.payment_status === 'PENDING_PAYMENT') {
    const stk = await paymentApi('stk_push.php', {sale_id:r.sale_id, reference:r.payment_reference, amount:r.amount, phone:r.phone});
    showPaymentLoading('Sending STK push to the customer phone...');
    toast(`STK Push sent. Waiting for payment: ${r.receipt_no}`);
    const paid = await waitForMpesaPayment(r.sale_id, r.payment_reference, stk.poll_seconds || r.poll_seconds || 90);
    hidePaymentLoading();
    if (!paid) {
      if (paid === 'failed') {
        state.checkoutKey = null;
        state.checkoutPayload = null;
      }
      await loadSales();
      return;
    }
  }
  resetCheckoutForm(); await loadSales(); toast(`Sale complete: ${r.receipt_no}`); await printReceipt(r.sale_id);
}
async function waitForMpesaPayment(saleId, reference, timeoutSeconds){
  const started = Date.now();
  while ((Date.now() - started) / 1000 < timeoutSeconds) {
    await new Promise(resolve => setTimeout(resolve, 3000));
    const r = await paymentApi(`payment_status.php?sale_id=${saleId}&reference=${encodeURIComponent(reference)}`, null, 'GET');
    if (r.paid) {
      hidePaymentLoading();
      return true;
    }
    if (r.sale?.status === 'PAYMENT_FAILED') {
      hidePaymentLoading();
      toast(`M-Pesa failed: ${r.sale.mpesa_status}`);
      await loadSales();
      return 'failed';
    }
    showPaymentLoading(`Waiting for customer confirmation... ${Math.max(0, Math.ceil(timeoutSeconds - ((Date.now() - started) / 1000)))}s left`);
  }
  hidePaymentLoading();
  toast('M-Pesa confirmation timed out. Retry checkout to check the same pending sale.');
  await loadSales();
  return false;
}
async function printReceipt(id){
  const r=await api(`receipt&id=${id}`, null, 'GET'), s=r.sale;
  const items = (r.items || []).map(i=>`<tr><td>${esc(i.product_name)}</td><td>${esc(i.quantity)}</td><td>${money(i.unit_price)}</td><td>${money(i.total)}</td></tr>`).join('');
  
  // Generate QR code URL if not present
  let qrUrl = s.etims_qr_url;
  if (!qrUrl && s.etims_qr_code) {
    qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(s.etims_qr_code);
  }
  
  const div=document.createElement('section'); div.className='receipt-print';
  const company = r.company || state.company || {};
  div.innerHTML=`
    <div class="receipt-shell">
      <img class="receipt-logo" src="${esc(company.logo)}">
      <h2>${esc(company.name)}</h2>
      <p>${esc(company.location)}<br>${esc(company.email)}${company.phone ? `<br>${esc(company.phone)}` : ''}</p>
      <div class="receipt-divider"></div>
      <p>Receipt: <b>${esc(s.receipt_no)}</b><br>${esc(receiptDate(s.created_at))}</p>
      <p>Cashier: <b>${esc(s.cashier_name || state.user?.full_name || 'System')}</b><br>Customer: <b>${esc(s.customer_name || 'Walk-in Customer')}</b></p>
      <table>
        <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amt</th></tr></thead>
        <tbody>${items || '<tr><td colspan="4">No items</td></tr>'}</tbody>
      </table>
      <div class="receipt-divider"></div>
      <p>Subtotal: ${money(s.subtotal)}<br>Discount: ${money(s.discount)}<br>VAT: ${money(s.tax)}<br>Total: <b>${money(s.total)}</b></p>
      <p>Payment: <b>${esc(s.payment_method)}</b>${s.payment_method === 'M-Pesa' ? `${s.mpesa_phone ? `<br>Mobile: ${esc(maskMpesaPhone(s.mpesa_phone))}` : ''}${s.mpesa_receipt_no ? `<br>M-Pesa Ref: ${esc(s.mpesa_receipt_no)}` : ''}<br>Status: ${esc(s.mpesa_status || 'PAID')}` : ''}${s.payment_method === 'Cash' ? `<br>Paid: ${money(s.amount_paid)}<br>Change: ${money(s.change_due)}` : `<br>Paid: ${money(s.amount_paid)}`}</p>
      <div class="receipt-divider"></div>
      ${qrUrl ? `<div style="text-align: center; margin: 10px 0;"><img src="${esc(qrUrl)}" style="max-width: 200px; height: auto;" alt="KRA QR Code"></div>` : ''}
      ${s.etims_unique_id ? `<p style="text-align: center; font-size: 10px;">ETIMS ID: ${esc(s.etims_unique_id)}</p>` : ''}
      <p>${esc(company.footer || 'Thank you for shopping with ' + (company.name || 'us') + '.')}</p>
    </div>`;
  document.body.appendChild(div); window.print(); div.remove();
}
async function loadProducts(){
  const rows=(await api('list&table=products', null, 'GET')).rows;
  state.products = rows;
  qs('#productsTable').innerHTML=renderProductTable(rows);
  const canManageProducts = ['ADMIN','MANAGER'].includes(state.user?.role);
  qs('#productForm').classList.toggle('hidden', !canManageProducts);
  qs('#restockPanel').classList.toggle('hidden', !canManageProducts);
  qs('#stockMovementsPanel').classList.toggle('hidden', !canManageProducts);
  qs('#purchaseOrdersPanel').classList.toggle('hidden', !canManageProducts);
  qsa('[data-edit-product]').forEach(button => button.onclick = () => openProductEditor(+button.dataset.editProduct));
  qsa('[data-delete-product]').forEach(button => button.onclick = () => deleteProduct(+button.dataset.deleteProduct));
  if (canManageProducts) {
    const suppliers=(await api('list&table=suppliers', null, 'GET')).rows;
    qs('#productSupplier').innerHTML='<option value="">Supplier (optional)</option>'+suppliers.map(s=>`<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
    qs('#productEditSupplier').innerHTML='<option value="">Unassigned</option>'+suppliers.map(s=>`<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
    renderRestockProducts(rows);
    renderPurchaseOrderInputs(suppliers, rows);
    await loadPurchaseOrders(suppliers);
    await loadStockMovements();
  }
}
function renderPurchaseOrderInputs(suppliers, products){
  const supplierSelect = qs('#poSupplier');
  const productSelect = qs('#poProduct');
  if (!supplierSelect || !productSelect) return;
  supplierSelect.innerHTML = '<option value="">Select supplier</option>' + suppliers.map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
  productSelect.innerHTML = '<option value="">Select product</option>' + products.map(p => `<option value="${esc(p.id)}">${esc(p.sku)} — ${esc(p.name)}</option>`).join('');
}
function renderPurchaseOrderLines(){
  const target = qs('#poLines');
  if (!target) return;
  if (!state.poLines.length) {
    target.innerHTML = '<p class="muted">No order lines yet.</p>';
    return;
  }
  const total = state.poLines.reduce((sum, line) => sum + line.unit_cost * line.quantity, 0);
  target.innerHTML = `<table><thead><tr><th>Product</th><th>Qty</th><th>Unit cost</th><th>Total</th><th></th></tr></thead><tbody>${state.poLines.map((line, index) => `<tr><td>${esc(line.name)}</td><td>${esc(line.quantity)}</td><td>${money(line.unit_cost)}</td><td>${money(line.unit_cost*line.quantity)}</td><td><button class="danger" type="button" data-po-remove-line="${index}">Remove</button></td></tr>`).join('')}</tbody></table><p class="muted">Order total: <b>${money(total)}</b></p>`;
  qsa('[data-po-remove-line]').forEach(button => button.onclick = () => {
    state.poLines.splice(Number(button.dataset.poRemoveLine), 1);
    renderPurchaseOrderLines();
  });
}
async function addPurchaseOrderLine(){
  const productSelect = qs('#poProduct');
  const productId = Number(productSelect.value);
  if (!productId) return toast('Choose a product to order');
  const quantity = Math.trunc(Number(qs('#poQuantity').value) || 0);
  if (quantity < 1 || quantity > 100000) return toast('Enter a quantity between 1 and 100000');
  const product = state.products.find(item => Number(item.id) === productId);
  if (!product) return toast('Product not found');
  const unitCost = qs('#poUnitCost').value.trim() === '' ? Number(product.buying_price ?? 0) : Number(qs('#poUnitCost').value);
  const existing = state.poLines.find(line => line.product_id === productId);
  if (existing) {
    existing.quantity = Math.min(100000, existing.quantity + quantity);
  } else {
    state.poLines.push({product_id: productId, name: product.name, quantity, unit_cost: unitCost});
  }
  productSelect.value = '';
  qs('#poQuantity').value = '';
  qs('#poUnitCost').value = '';
  renderPurchaseOrderLines();
}
async function createPurchaseOrder(){
  if (!state.poLines.length) return toast('Add at least one product line');
  const supplierId = Number(qs('#poSupplier').value);
  if (!supplierId) return toast('Choose a supplier');
  const payload = {
    supplier_id: supplierId,
    items: state.poLines.map(line => ({product_id: line.product_id, quantity: line.quantity, unit_cost: line.unit_cost})),
    notes: qs('#poNotes').value.trim(),
  };
  if (qs('#poExpectedAt').value) payload.expected_at = qs('#poExpectedAt').value;
  try {
    const result = await api('create_purchase_order', payload);
    state.poLines = [];
    qs('#poNotes').value = '';
    qs('#poExpectedAt').value = '';
    renderPurchaseOrderLines();
    await loadPurchaseOrders();
    toast(`Purchase order created: ${result.po_number}`);
  } catch (error) {
    toast(error.message);
  }
}
function renderPurchaseOrders(rows, suppliers){
  const target = qs('#purchaseOrdersTable');
  if (!target) return;
  if (!rows?.length) {
    target.innerHTML = '<p class="muted">No purchase orders yet.</p>';
    return;
  }
  const supplierName = id => (suppliers || []).find(s => Number(s.id) === Number(id))?.name || `Supplier #${id}`;
  target.innerHTML = `<table><thead><tr><th>Order</th><th>Supplier</th><th>Status</th><th>Total</th><th>Expected</th><th>Created</th><th>Actions</th></tr></thead><tbody>${rows.map(row => {
    const status = String(row.status || '');
    const cancellable = status === 'OPEN' || status === 'PARTIAL' || status === 'DRAFT';
    const receivable = status === 'OPEN' || status === 'PARTIAL';
    return `<tr><td>${esc(row.po_number)}</td><td>${esc(supplierName(row.supplier_id))}</td><td>${esc(status)}</td><td>${money(row.total)}</td><td>${esc(row.expected_at || '—')}</td><td>${esc(receiptDate(row.created_at))}</td><td>${receivable ? `<button class="secondary" type="button" data-po-receive="${row.id}">Receive</button> ` : ''}${cancellable && status !== 'RECEIVED' ? `<button class="danger" type="button" data-po-cancel="${row.id}">Cancel</button>` : ''}</td></tr>`;
  }).join('')}</tbody></table>`;
  qsa('[data-po-receive]').forEach(button => button.onclick = () => openPurchaseOrderReceive(+button.dataset.poReceive).catch(error => toast(error.message)));
  qsa('[data-po-cancel]').forEach(button => button.onclick = () => cancelPurchaseOrder(+button.dataset.poCancel));
}
async function loadPurchaseOrders(suppliers){
  let rows = [];
  try {
    rows = (await api('list&table=purchase_orders', null, 'GET')).rows || [];
  } catch (error) {
    rows = [];
  }
  if (!suppliers) {
    try {
      suppliers = (await api('list&table=suppliers', null, 'GET')).rows || [];
    } catch (error) {
      suppliers = [];
    }
  }
  renderPurchaseOrders(rows, suppliers);
}
async function cancelPurchaseOrder(id){
  if (!window.confirm('Cancel this purchase order?')) return;
  try {
    await api('cancel_purchase_order', {purchase_order_id: id});
    toast('Purchase order cancelled');
    await loadPurchaseOrders();
  } catch (error) {
    toast(error.message);
  }
}
function renderPurchaseOrderReceiveLines(order){
  const target = qs('#poReceiveLines');
  const open = (order.items || []).filter(item => Number(item.quantity) - Number(item.received_quantity) > 0);
  if (!open.length) {
    target.innerHTML = '<p class="muted">Every line has already been fully received.</p>';
    return;
  }
  target.innerHTML = `<table><thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Units now</th><th>New buying price</th></tr></thead><tbody>${open.map(item => {
    const outstanding = Number(item.quantity) - Number(item.received_quantity);
    return `<tr><td>${esc(item.product_name)}</td><td>${esc(item.quantity)}</td><td>${esc(item.received_quantity)}</td><td><input type="number" min="0" max="${outstanding}" step="1" value="0" data-receive-qty="${item.id}"></td><td><input type="number" min="0" step="0.01" placeholder="Optional" data-receive-price="${item.id}"></td></tr>`;
  }).join('')}</tbody></table>`;
}
async function openPurchaseOrderReceive(id){
  const result = await api(`po_detail&id=${id}`, null, 'GET');
  const order = result.order;
  qs('#poReceiveForm').elements['purchase_order_id'].value = order.id;
  qs('#poReceiveTitle').textContent = `Receive ${order.po_number}`;
  renderPurchaseOrderReceiveLines(order);
  qs('#poReceiveModal').classList.remove('hidden');
}
function closePurchaseOrderReceive(){
  qs('#poReceiveModal').classList.add('hidden');
}
async function submitPurchaseOrderReceive(form){
  const purchaseOrderId = Number(form.elements['purchase_order_id'].value);
  const lines = qsa('[data-receive-qty]')
    .map(input => {
      const line = {item_id: Number(input.dataset.receiveQty), quantity: Number(input.value) || 0};
      const priceInput = qs(`[data-receive-price="${line.item_id}"]`);
      const price = priceInput ? priceInput.value.trim() : '';
      if (price !== '') line.buying_price = price;
      return line;
    })
    .filter(line => line.quantity > 0);
  if (!lines.length) return toast('Enter the units received on at least one line');
  try {
    const paidUpfront = qs('#poReceivePaidUpfront').checked;
    const result = await api('receive_purchase_order', {purchase_order_id: purchaseOrderId, lines, paid_upfront: paidUpfront});
    closePurchaseOrderReceive();
    const credit = paidUpfront || !result.credited
      ? ''
      : ` Supplier balance is now ${money(result.supplier_balance)}`;
    toast(`Received ${result.received} unit(s): ${result.po_number} is now ${result.status}.${credit}`);
    await loadPurchaseOrders();
    await loadStockMovements();
  } catch (error) {
    toast(error.message);
  }
}
function renderProductTable(rows){
  if (!rows.length) return '<p class="muted">No products yet.</p>';
  const canEdit = ['ADMIN','MANAGER'].includes(state.user?.role);
  const showCost = !!state.permissions['cost.view'];
  return `<table><thead><tr><th>SKU</th><th>Product</th><th>Stock</th>${showCost?'<th>Buying</th>':''}<th>Selling</th><th>VAT</th><th>Branch</th><th>Supplier</th>${canEdit?'<th>Action</th>':''}</tr></thead><tbody>${rows.map(p=>{
    const stock = Number(p.quantity) || 0;
    const low = stock <= (Number(p.reorder_level) || 0);
    const meta = [p.category, p.brand].filter(Boolean).map(esc).join(' · ');
    return `<tr><td>${esc(p.sku||'')}</td><td><b>${esc(p.name||'')}</b><br><small class="muted">${meta}</small></td><td class="${low?'stock-low':''}">${stock}${low?' <small>low</small>':''}</td>${showCost?`<td>${money(p.buying_price)}</td>`:''}<td>${money(p.selling_price)}</td><td>${vatLabel(p.vat_rate)}</td><td>${esc(p.branch||'Main')}</td><td>${esc(p.supplier_name||'Unassigned')}</td>${canEdit?`<td><button class="secondary" type="button" data-edit-product="${p.id}">Edit</button> <button class="danger" type="button" data-delete-product="${p.id}">Delete</button></td>`:''}</tr>`;
  }).join('')}</tbody></table>`;
}
function vatLabel(rate){
  const percent = Number(rate || 0) * 100;
  return `${Number(percent.toFixed(2))}%`;
}
function openProductEditor(id){
  const product = (state.products || []).find(item => Number(item.id) === Number(id));
  if (!product) return toast('Product not found');
  const form = qs('#productEditForm');
  form.reset();
  form.elements['id'].value = product.id;
  form.elements['sku'].value = product.sku || '';
  form.elements['barcode'].value = product.barcode || '';
  form.elements['name'].value = product.name || '';
  form.elements['category'].value = product.category || '';
  form.elements['brand'].value = product.brand || '';
  form.elements['warehouse'].value = product.warehouse || 'Main';
  form.elements['branch'].value = product.branch || 'Main';
  form.elements['supplier_id'].value = product.supplier_id ?? '';
  form.elements['buying_price'].value = product.buying_price ?? '';
  form.elements['selling_price'].value = product.selling_price ?? '';
  form.elements['vat_rate'].value = product.vat_rate ?? '';
  form.elements['reorder_level'].value = product.reorder_level ?? 0;
  const showCost = !!state.permissions['cost.view'];
  qs('#productBuyingWrap').classList.toggle('hidden', !showCost);
  form.elements['buying_price'].disabled = !showCost;
  qs('#productEditTitle').textContent = `Edit ${product.name}`;
  qs('#productEditModal').classList.remove('hidden');
  form.elements['selling_price'].focus();
}
function closeProductEditor(){
  qs('#productEditModal').classList.add('hidden');
}
function deleteProduct(id){
  const product = (state.products || []).find(item => Number(item.id) === Number(id));
  if (!product) return toast('Product not found');
  const stock = Number(product.quantity) || 0;
  const stockNote = stock > 0 ? ` ${stock} unit(s) in stock will be discarded.` : '';
  if (!window.confirm(`Delete ${product.name} (${product.sku})?${stockNote} This cannot be undone.`)) return;
  return api('delete_product', {id})
    .then(async result => {
      await loadProducts();
      toast(`Deleted ${result.sku}`);
    })
    .catch(error => toast(error.message));
}
function renderRestockProducts(rows){
  const select = qs('#restockProduct');
  if (!select) return;
  select.innerHTML = '<option value="">Select product</option>' + rows.map(p=>`<option value="${esc(p.id)}">${esc(p.sku)} — ${esc(p.name)} (stock ${esc(p.quantity)})</option>`).join('');
}
function renderStockMovements(rows){
  if (!rows?.length) return '<p class="muted">No stock movements yet.</p>';
  return `<table><thead><tr><th>Date</th><th>Product</th><th>Change</th><th>Reason</th><th>Reference</th><th>By</th></tr></thead><tbody>${rows.map(row=>{
    const delta = Number(row.quantity_delta) || 0;
    return `<tr><td>${receiptDate(row.created_at)}</td><td>${esc(row.product_name)}<br><small class="muted">${esc(row.sku)}</small></td><td class="${delta < 0 ? 'stock-out' : 'stock-in'}">${delta > 0 ? '+' : ''}${delta}</td><td>${esc(row.reason)}</td><td>${esc(row.reference || '—')}</td><td>${esc(row.username)}</td></tr>`;
  }).join('')}</tbody></table>`;
}
async function loadStockMovements(){
  try {
    const result = await api('stock_movements&limit=25', null, 'GET');
    qs('#stockMovementsTable').innerHTML = renderStockMovements(result.rows);
  } catch (error) {
    qs('#stockMovementsTable').innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
async function loadPeople(){
  const c=(await api('list&table=customers', null, 'GET')).rows, s=(await api('list&table=suppliers', null, 'GET')).rows;
  state.people = {customers:c, suppliers:s};
  qs('#peopleTables').innerHTML='<h2>Customers</h2>'+peopleTable(c,'customers')+'<h2>Suppliers</h2>'+peopleTable(s,'suppliers');
  qsa('[data-edit-person]').forEach(button => button.onclick = () => openPersonEditor(button.dataset.table, +button.dataset.editPerson));
  qsa('[data-delete-person]').forEach(button => button.onclick = () => deletePerson(button.dataset.table, +button.dataset.deletePerson));
  const paymentCustomer = qs('#paymentCustomer');
  if (paymentCustomer) {
    const previous = paymentCustomer.value;
    const debtors = c.filter(row => Number(row.balance) > 0);
    const settled = c.filter(row => !(Number(row.balance) > 0));
    paymentCustomer.innerHTML = '<option value="">Select customer</option>'
      + debtors.map(row => `<option value="${esc(row.id)}">${esc(row.name)} · owes ${money(row.balance)}</option>`).join('')
      + settled.map(row => `<option value="${esc(row.id)}">${esc(row.name)} · no outstanding balance</option>`).join('');
    if (previous && c.some(row => String(row.id) === previous)) paymentCustomer.value = previous;
  }
  const supplierPaymentSelect = qs('#supplierPaymentSupplier');
  if (supplierPaymentSelect) {
    const previousSupplier = supplierPaymentSelect.value;
    const debtors = s.filter(row => Number(row.balance) > 0);
    const settled = s.filter(row => !(Number(row.balance) > 0));
    supplierPaymentSelect.innerHTML = '<option value="">Select supplier</option>'
      + debtors.map(row => `<option value="${esc(row.id)}">${esc(row.name)} · we owe ${money(row.balance)}</option>`).join('')
      + settled.map(row => `<option value="${esc(row.id)}">${esc(row.name)} · nothing owed</option>`).join('');
    if (previousSupplier && s.some(row => String(row.id) === previousSupplier)) supplierPaymentSelect.value = previousSupplier;
  }
}
function peopleTable(rows, tableName){
  if (!rows.length) return '<p class="muted">No records yet.</p>';
  const canDelete = !!state.permissions['records.delete'];
  const canEdit = ['ADMIN','MANAGER'].includes(state.user?.role);
  const hasActions = canEdit || canDelete;
  const showBalance = rows.some(row => row.balance !== undefined && row.balance !== null);
  return `<table><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>KRA PIN</th>${showBalance ? '<th>Credit balance</th>' : ''}${hasActions ? '<th>Action</th>' : ''}</tr></thead><tbody>${rows.map(row=>{
    const actions = [
      canEdit ? `<button class="secondary" type="button" data-edit-person="${row.id}" data-table="${esc(tableName)}">Edit</button>` : '',
      canDelete ? `<button class="danger" type="button" data-delete-person="${row.id}" data-table="${esc(tableName)}">Delete</button>` : '',
    ].filter(Boolean).join(' ');
    return `<tr><td>${esc(row.name||'')}</td><td>${esc(row.phone||'')}</td><td>${esc(row.email||'')}</td><td>${esc(row.kra_pin||'')}</td>${showBalance ? `<td>${money(row.balance)}</td>` : ''}${hasActions ? `<td>${actions}</td>` : ''}</tr>`;
  }).join('')}</tbody></table>`;
}
function openPersonEditor(table, id){
  const row = (state.people?.[table] || []).find(item => Number(item.id) === Number(id));
  if (!row) return toast('Record not found');
  const form = qs('#personEditForm');
  form.elements['table'].value = table;
  form.elements['id'].value = row.id;
  form.elements['name'].value = row.name || '';
  form.elements['phone'].value = row.phone || '';
  form.elements['email'].value = row.email || '';
  form.elements['kra_pin'].value = row.kra_pin || '';
  qs('#personEditTitle').textContent = table === 'suppliers' ? 'Edit supplier' : 'Edit customer';
  qs('#personEditModal').classList.remove('hidden');
  form.elements['name'].focus();
}
function closePersonEditor(){
  qs('#personEditModal').classList.add('hidden');
}
async function deletePerson(table, id){
  if (!window.confirm('Delete this record permanently?')) return;
  try { await api('delete_person',{table,id}); await loadPeople(); toast('Record deleted'); } catch (error) { toast(error.message); }
}
async function loadQuotations(){
  const rows=(await api('list&table=quotations', null, 'GET')).rows;
  qs('#quotationsTable').innerHTML=renderQuotationHistory(rows);
  qsa('[data-edit-quotation]').forEach(button => button.onclick = () => openQuotationEditor(+button.dataset.editQuotation).catch(error=>toast(error.message)));
  renderQuoteCart();
}
function canEditQuotations(){
  return ['ADMIN','MANAGER'].includes(state.user?.role);
}
function renderQuotationHistory(rows){
  if(!rows.length) return '<p class="muted">No quotations yet.</p>';
  const canEdit = canEditQuotations();
  const canDelete = !!state.permissions['records.delete'];
  return `<table><thead><tr><th>Quotation</th><th>Customer</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>${rows.map(row=>{
    const accepted= row.status === 'ACCEPTED';
    const converted= row.status === 'CONVERTED';
    return `<tr><td>${esc(row.quote_no)}</td><td>${esc(row.customer_name||'')}</td><td>${money(row.total)}</td><td>${esc(row.status)}</td><td>${esc(receiptDate(row.created_at))}</td><td>${canEdit && !converted ? `<button class="secondary" type="button" data-edit-quotation="${row.id}">Edit</button> ` : ''}<button class="secondary" type="button" data-view-quotation="${row.id}">Receipt</button> <button class="secondary" type="button" data-pdf-quotation="${row.id}">PDF</button> ${!converted && !accepted ? `<button class="secondary" type="button" data-accept-quotation="${row.id}">Accept</button>` : ''} ${accepted ? `<button class="primary" type="button" data-convert-quotation="${row.id}">Add to sales</button>` : ''} ${converted ? `<button class="secondary" type="button" data-view-sale="${row.converted_sale_id}">Sale receipt</button>` : ''} ${canDelete ? `<button class="danger" type="button" data-delete-quotation="${row.id}" data-converted="${converted}">Delete</button>` : ''}</td></tr>`;
  }).join('')}</tbody></table>`;
}
async function quotationDetail(id){ return api(`quotation_detail&id=${id}`, null, 'GET'); }
async function printQuotation(id){
  const r=await quotationDetail(id), q=r.quotation;
  const company = r.company || state.company || {};
  const items=(r.items||[]).map(item=>`<tr><td>${esc(item.product_name)}</td><td>${esc(item.quantity)}</td><td>${money(item.unit_price)}</td><td>${money(item.total)}</td></tr>`).join('');
  const div=document.createElement('section'); div.className='receipt-print';
  div.innerHTML=`<div class="receipt-shell quotation-receipt"><img class="receipt-logo" src="${esc(company.logo)}"><h2>${esc(company.name)}</h2><p>${esc(company.location)}<br>${esc(company.email)}${company.phone ? `<br>${esc(company.phone)}` : ''}</p><h3>QUOTATION RECEIPT</h3><p>Quotation No: <b>${esc(q.quote_no)}</b><br>${esc(receiptDate(q.created_at))}<br>Valid until: ${esc(q.valid_until||'Not specified')}</p><p>Customer: <b>${esc(q.customer_name||'Walk-in Customer')}</b>${q.customer_kra_pin ? `<br>KRA PIN: ${esc(q.customer_kra_pin)}` : ''}</p><table><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amt</th></tr></thead><tbody>${items}</tbody></table><div class="receipt-divider"></div><p>Subtotal: ${money(q.subtotal)}<br>Discount: ${money(q.discount)}<br>VAT: ${money(q.tax)}<br>Total: <b>${money(q.total)}</b></p><div class="receipt-divider"></div><p>This is a quotation, not an official tax receipt.</p></div>`;
  const previousTitle=document.title;
  document.title=`Quotation Receipt ${q.quote_no}`;
  document.body.appendChild(div); window.print(); div.remove(); document.title=previousTitle;
}
async function deleteQuotation(id, converted){ const message=converted ? 'Delete this converted quotation permanently? Its sale and sale receipt will be kept.' : 'Delete this quotation permanently?'; if(!window.confirm(message)) return; try{await api('delete_quotation',{id});await loadQuotations();toast('Quotation deleted')}catch(error){toast(error.message)} }
async function acceptQuotation(id){ try{await api('accept_quotation',{id});await loadQuotations();toast('Quotation marked as accepted')}catch(error){toast(error.message)} }
async function convertQuotation(id){
  const methods = PAYMENT_METHODS.filter(method => method !== 'M-Pesa');
  const paymentMethod=window.prompt(`Payment method (${methods.join(', ')})`,'Cash');
  if(!paymentMethod) return;
  const amountPaid=window.prompt('Amount paid', '0');
  if(amountPaid===null) return;
  try{const r=await api('convert_quotation',{id,payment_method:paymentMethod.trim(),amount_paid:+amountPaid||0});await loadQuotations();toast(`Sale created: ${r.receipt_no}`);await printReceipt(r.sale_id)}catch(error){toast(error.message)}
}
const quoteEditor = {id:null, discount:0, lines:[], nextKey:1};
async function openQuotationEditor(id){
  if(!canEditQuotations()) return toast('You do not have permission to edit quotations');
  const detail = await quotationDetail(id);
  const quote = detail.quotation;
  if(quote.status === 'CONVERTED') return toast('A converted quotation can no longer be edited');
  state.products = (await api('list&table=products', null, 'GET')).rows;
  quoteEditor.id = Number(quote.id);
  quoteEditor.discount = Number(quote.discount)||0;
  quoteEditor.lines = (detail.items||[]).map(item => ({
    key: `existing-${item.id}`,
    id: Number(item.id),
    product_id: Number(item.product_id),
    name: item.product_name || '',
    price: Number(item.unit_price)||0,
    qty: Number(item.quantity)||1,
  }));
  const form = qs('#quotationEditForm');
  form.elements['id'].value = quoteEditor.id;
  form.elements['customer_name'].value = quote.customer_name || '';
  form.elements['customer_kra_pin'].value = quote.customer_kra_pin || '';
  qs('#quotationEditTitle').textContent = `Edit ${quote.quote_no}`;
  populateQuotationProducts();
  renderQuotationEditorLines();
  qs('#quotationEditModal').classList.remove('hidden');
  form.elements['customer_name'].focus();
}
function populateQuotationProducts(){
  const select = qs('#quotationAddProduct');
  select.innerHTML = '<option value="">Select product</option>' + state.products.map(product => `<option value="${esc(product.id)}">${esc(product.sku)} — ${esc(product.name)} (${money(product.selling_price)})</option>`).join('');
}
function addQuotationEditorLine(){
  const select = qs('#quotationAddProduct');
  const productId = Number(select.value);
  if(!productId) return toast('Choose a product to add');
  const quantity = Math.trunc(Number(qs('#quotationAddQty').value)||0);
  if(quantity < 1 || quantity > 10000) return toast('Enter a quantity between 1 and 10000');
  const product = state.products.find(item => Number(item.id) === productId);
  if(!product) return toast('Product not found');
  const existing = quoteEditor.lines.find(line => Number(line.product_id) === productId);
  if(existing){
    existing.qty = Math.min(10000, existing.qty + quantity);
  } else {
    quoteEditor.lines.push({
      key: `new-${quoteEditor.nextKey++}`,
      id: null,
      product_id: productId,
      name: product.name || '',
      price: Number(product.selling_price)||0,
      qty: quantity,
    });
  }
  select.value = '';
  qs('#quotationAddQty').value = '1';
  renderQuotationEditorLines();
}
function renderQuotationEditorLines(){
  const target = qs('#quotationEditLines');
  target.innerHTML = quoteEditor.lines.length
    ? `<table><thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th>Total</th><th></th></tr></thead><tbody>${quoteEditor.lines.map(line => `<tr><td>${esc(line.name)}</td><td>${money(line.price)}</td><td><input class="quote-editor-qty" type="number" min="1" max="10000" step="1" value="${esc(line.qty)}" data-quote-qty="${esc(line.key)}"></td><td data-quote-line-total="${esc(line.key)}">${money(line.price*line.qty)}</td><td><button class="danger" type="button" data-quote-remove-line="${esc(line.key)}">Remove</button></td></tr>`).join('')}</tbody></table>`
    : '<p class="muted">Add at least one product.</p>';
  qsa('[data-quote-qty]').forEach(input => input.onchange = () => {
    const line = quoteEditor.lines.find(item => item.key === input.dataset.quoteQty);
    if(!line) return;
    const quantity = Math.trunc(Number(input.value)||0);
    if(quantity < 1 || quantity > 10000){ input.value = line.qty; return toast('Enter a quantity between 1 and 10000'); }
    line.qty = quantity;
    const cell = qs(`[data-quote-line-total="${line.key}"]`);
    if(cell) cell.textContent = money(line.price*line.qty);
    renderQuotationEditorTotals();
  });
  qsa('[data-quote-remove-line]').forEach(button => button.onclick = () => {
    quoteEditor.lines = quoteEditor.lines.filter(item => item.key !== button.dataset.quoteRemoveLine);
    renderQuotationEditorLines();
  });
  renderQuotationEditorTotals();
}
function renderQuotationEditorTotals(){
  const subtotal = quoteEditor.lines.reduce((sum, line) => sum + line.price*line.qty, 0);
  const discount = Math.min(Math.max(0, quoteEditor.discount), subtotal);
  const total = Math.max(0, subtotal - discount);
  qs('#quotationEditTotals').innerHTML = `<div><span>Subtotal</span><b>${money(subtotal)}</b></div><div><span>Discount</span><b>${money(discount)}</b></div><div><span>VAT</span><b>Recalculated on save</b></div><div class="grand"><span>Total</span><b>${money(total)}</b></div>`;
}
function closeQuotationEditor(){
  qs('#quotationEditModal').classList.add('hidden');
  quoteEditor.id = null;
  quoteEditor.lines = [];
  quoteEditor.discount = 0;
}
async function viewSaleReceipt(id){ await printReceipt(id); }
async function loadCashFlow(){
  const period=qs('#cashFlowPeriod').value, date=qs('#cashFlowDate').value || new Date().toISOString().slice(0,10);
  const result=await api('cash_flow',{period,date});
  const cash=result.summary, profitable=Number(cash.net_cash)>=0;
  qs('#cashFlowCardsPage').innerHTML=`<section class="kpi">Cash sales<strong>${money(cash.cash_sales)}</strong></section><section class="kpi">Manual income<strong>${money(cash.other_income)}</strong></section><section class="kpi">Total expenses<strong>${money(cash.expenses)}</strong></section><section class="kpi">Net cash<strong>${money(cash.net_cash)}</strong><span>${profitable?'Profit':'Loss'}</span></section>`;
  const max=Math.max(1,...result.series.map(row=>Math.max(Number(row.income),Number(row.expenses))));
  qs('#cashFlowChart').innerHTML=result.series.length ? result.series.map(row=>{const income=Number(row.income), expenses=Number(row.expenses), net=income-expenses;return `<div class="cash-flow-bar ${net<0?'loss':''}" title="Income ${money(income)} | Expenses ${money(expenses)} | ${net<0?'Loss':'Profit'} ${money(Math.abs(net))}"><b>${net<0?'Loss':'Profit'} ${money(Math.abs(net))}</b><span style="height:${Math.max(4,Math.round(Math.max(income,expenses)/max*150))}px"></span><small>${esc(row.bucket)}</small></div>`}).join('') : '<p class="muted">No cash-flow activity for this period.</p>';
  state.cashEntries = result.entries || [];
  renderCashFlowEntries(state.cashEntries);
  qs('#cashFlowDate').value=date;
  await Promise.all([refreshReceivables(), refreshShiftHistory()]);
}
function renderReceivables(data){
  const cards = qs('#receivablesCards');
  const table = qs('#receivablesTable');
  if (!cards || !table) return;
  const ageing = data.ageing || {};
  cards.innerHTML = [
    analyticsCard('Outstanding credit', money(data.outstanding), {sub: `${Number(data.debtors)} debtor(s) · all periods`}),
    analyticsCard('0–30 days', money(ageing.current || 0)),
    analyticsCard('31–60 days', money(ageing['31_60'] || 0)),
    analyticsCard('61–90 days', money(ageing['61_90'] || 0)),
    analyticsCard('Over 90 days', money(ageing.over_90 || 0)),
  ].join('');
  table.innerHTML = renderDebtorsTable(data.debtors_list);
}
function renderDebtorsTable(rows){
  if (!rows?.length) return '<p class="muted analytics-empty">No customers with an outstanding balance.</p>';
  return `<table><thead><tr><th>Customer</th><th>Phone</th><th>Balance</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name)}</td><td>${esc(row.phone || '—')}</td><td>${money(row.balance)}</td></tr>`).join('')}</tbody></table>`;
}
async function refreshReceivables(){
  try {
    renderReceivables(await api('receivables', null, 'GET'));
  } catch (error) {
    const cards = qs('#receivablesCards');
    if (cards) cards.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
function renderShiftHistory(rows){
  const target = qs('#shiftHistoryTable');
  if (!target) return;
  if (!rows?.length) {
    target.innerHTML = '<p class="muted">No cash shifts recorded yet.</p>';
    return;
  }
  target.innerHTML = `<table><thead><tr><th>Opened</th><th>Cashier</th><th>Float</th><th>Expected</th><th>Declared</th><th>Variance</th><th>Closed</th></tr></thead><tbody>${rows.map(row => {
    const open = row.status === 'OPEN';
    const variance = row.variance === null || row.variance === undefined ? null : Number(row.variance);
    const varianceCell = open
      ? '<td>—</td>'
      : `<td class="${variance === 0 ? '' : variance > 0 ? 'stock-in' : 'stock-out'}">${variance === 0 ? 'Balanced' : (variance > 0 ? '+' : '') + money(variance)}</td>`;
    return `<tr><td>${esc(receiptDate(row.opened_at))}</td><td>${esc(row.opened_by_name || '—')}</td><td>${money(row.opening_float)}</td><td>${row.cash_expected === null ? '—' : money(row.cash_expected)}</td><td>${row.cash_declared === null ? '—' : money(row.cash_declared)}</td>${varianceCell}<td>${open ? `<b>Open</b>` : esc(receiptDate(row.closed_at))}</td></tr>`;
  }).join('')}</tbody></table>`;
}
async function refreshShiftHistory(){
  try {
    renderShiftHistory((await api('shift_history&limit=20', null, 'GET')).rows || []);
  } catch (error) {
    const target = qs('#shiftHistoryTable');
    if (target) target.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
function syncCashMovementCategory(){
  const form = qs('#cashMovementForm');
  const expense = qs('#cashMovementExpenseCategory');
  const income = qs('#cashMovementIncomeCategory');
  if (!form || !expense || !income) return;
  const isExpense = form.elements['type'].value === 'EXPENSE';
  expense.disabled = !isExpense;
  expense.classList.toggle('hidden', !isExpense);
  income.disabled = isExpense;
  income.classList.toggle('hidden', isExpense);
  income.required = !isExpense;
}
function syncCashEntryCategory(){
  const form = qs('#cashEntryEditForm');
  const expenseWrap = qs('#cashEntryExpenseCategoryWrap');
  const incomeWrap = qs('#cashEntryIncomeCategoryWrap');
  const expense = qs('#cashEntryExpenseCategory');
  const income = qs('#cashEntryIncomeCategory');
  if (!form || !expenseWrap || !incomeWrap || !expense || !income) return;
  const isExpense = form.elements['type'].value === 'EXPENSE';
  expenseWrap.classList.toggle('hidden', !isExpense);
  incomeWrap.classList.toggle('hidden', isExpense);
  expense.disabled = !isExpense;
  income.disabled = isExpense;
  income.required = !isExpense;
}
function renderCashFlowEntries(rows){
  const target = qs('#cashFlowEntries');
  if(!target) return;
  const canEdit = state.user?.role === 'ADMIN';
  if(!rows?.length){
    target.innerHTML = '<p class="muted">No expenses or cash movements for this period.</p>';
    return;
  }
  target.innerHTML = `<table><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Amount</th><th>Reference / Paid by</th>${canEdit ? '<th>Action</th>' : ''}</tr></thead><tbody>${rows.map(row => {
    const isIncome = row.source === 'cash_transaction' && row.type === 'INCOME';
    const typeLabel = row.source === 'expense' ? 'Expense' : (isIncome ? 'Cash in' : 'Cash out');
    const amountClass = isIncome ? 'stock-in' : 'stock-out';
    return `<tr><td>${esc(receiptDate(row.created_at))}</td><td>${esc(typeLabel)}</td><td>${esc(row.category || '')}</td><td>${esc(row.description || '')}</td><td class="${amountClass}">${money(row.amount)}</td><td>${esc(row.reference || '—')}</td>${canEdit ? `<td><button class="secondary" type="button" data-edit-cash-entry="${row.id}" data-source="${esc(row.source)}">Edit</button></td>` : ''}</tr>`;
  }).join('')}</tbody></table>`;
  qsa('[data-edit-cash-entry]').forEach(button => button.onclick = () => openCashEntryEditor(button.dataset.source, +button.dataset.editCashEntry));
}
function openCashEntryEditor(source, id, entries = state.cashEntries){
  if(state.user?.role !== 'ADMIN') return toast('Only an admin can edit cash-flow entries');
  const entry = (entries || []).find(row => row.source === source && Number(row.id) === Number(id));
  if(!entry) return toast('Entry not found');
  const form = qs('#cashEntryEditForm');
  const fromExpensesTable = source === 'expense';
  form.elements['source'].value = source;
  form.elements['id'].value = entry.id;
  form.elements['type'].value = entry.type === 'INCOME' ? 'INCOME' : 'EXPENSE';
  const isCashExpense = form.elements['type'].value === 'EXPENSE';
  const expenseSelect = qs('#cashEntryExpenseCategory');
  const incomeInput = qs('#cashEntryIncomeCategory');
  // Drop the option added for a legacy category the last time the editor opened.
  [...expenseSelect.options].filter(option => option.dataset.legacy).forEach(option => option.remove());
  const category = entry.category || '';
  if (isCashExpense) {
    if (category && ![...expenseSelect.options].some(option => option.value === category)) {
      const legacy = document.createElement('option');
      legacy.value = category;
      legacy.textContent = category;
      legacy.dataset.legacy = '1';
      expenseSelect.appendChild(legacy);
    }
    expenseSelect.value = category || expenseSelect.options[0].value;
    incomeInput.value = '';
  } else {
    incomeInput.value = category;
    expenseSelect.value = expenseSelect.options[0].value;
  }
  syncCashEntryCategory();
  form.elements['description'].value = entry.description || '';
  form.elements['amount'].value = Number(entry.amount) || 0;
  form.elements['reference'].value = entry.reference || '';
  qs('#cashEntryTypeWrap').classList.toggle('hidden', fromExpensesTable);
  qs('#cashEntryEditTitle').textContent = fromExpensesTable ? 'Edit expense' : 'Edit cash movement';
  qs('#cashEntryDescriptionLabel').textContent = fromExpensesTable ? 'Title' : 'Description';
  qs('#cashEntryReferenceLabel').textContent = fromExpensesTable ? 'Paid by' : 'Reference';
  form.elements['description'].required = fromExpensesTable;
  qs('#cashEntryEditModal').classList.remove('hidden');
  (isCashExpense ? expenseSelect : incomeInput).focus();
}
function closeCashEntryEditor(){
  qs('#cashEntryEditModal').classList.add('hidden');
}
async function loadStockAnalytics(){
  const result = await api('stock_analytics', null, 'GET');
  const s = result.summary || {};
  qs('#stockAnalyticsCards').innerHTML = [
    analyticsCard('Products tracked', Number(s.products || 0)),
    analyticsCard('Low stock', Number(s.low_stock || 0), {sub: 'At or below reorder level'}),
    analyticsCard('Out of stock', Number(s.out_of_stock || 0)),
    s.at_cost === null || s.at_cost === undefined
      ? analyticsCard('Inventory at cost', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
      : analyticsCard('Inventory at cost', money(s.at_cost), {sub: 'At current buying price'}),
    analyticsCard('Inventory at retail', money(s.at_retail), {sub: 'At current selling price'}),
    s.potential_margin === null || s.potential_margin === undefined
      ? analyticsCard('Potential margin', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
      : analyticsCard('Potential margin', money(s.potential_margin), {sub: 'Retail minus cost'}),
  ].join('');
  qs('#stockAnalyticsPage').innerHTML = renderStockAnalytics(result.rows);
}
function renderStockAnalytics(rows){
  if(!rows.length) return '<p class="muted">No supplier-linked products yet.</p>';
  return `<table><thead><tr><th>Supplier name</th><th>Product</th><th>Total units supplied</th><th>Units available in stock</th><th>Units sold</th><th>Remaining units</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.supplier_name||'Unassigned')}</td><td>${esc(row.product||'')}</td><td>${esc(row.total_units_supplied||0)}</td><td>${esc(row.units_available_in_stock||0)}</td><td>${esc(row.units_sold||0)}</td><td>${esc(row.remaining_units||0)}</td></tr>`).join('')}</tbody></table>`;
}
const analyticsState = { period: 'mtd', start: '', end: '' };

const analyticsDate = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-KE', {day: '2-digit', month: 'short', year: 'numeric'}) : '';

const compactMoney = value => {
  const amount = Number(value) || 0;
  if (!amount) return '';
  if (amount >= 1_000_000) return `${(amount / 1_000_000).toFixed(1)}M`;
  if (amount >= 1_000) return `${Math.round(amount / 1_000)}k`;
  return String(Math.round(amount));
};

function analyticsDelta(pct, label = 'vs previous period'){
  if (pct === null || pct === undefined) return '<span class="delta neutral" title="No comparison base">No comparison</span>';
  const value = Number(pct);
  const direction = value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
  const arrow = value > 0 ? '▲' : value < 0 ? '▼' : '■';
  return `<span class="delta ${direction}" title="${esc(label)}">${arrow} ${Math.abs(value).toFixed(1)}%</span>`;
}

function analyticsCountDelta(diff, label = 'vs previous period'){
  if (diff === null || diff === undefined) return '<span class="delta neutral">No comparison</span>';
  const value = Number(diff);
  const direction = value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
  return `<span class="delta ${direction}" title="${esc(label)}">${value > 0 ? '+' : ''}${value}</span>`;
}

function analyticsCard(label, value, {delta = '', sub = '', restricted = false} = {}){
  return `<article class="analytics-card"><span class="label">${esc(label)}</span><strong${restricted ? ' class="restricted"' : ''}>${value}</strong>${delta ? `<div class="analytics-delta">${delta}</div>` : ''}${sub ? `<small>${sub}</small>` : ''}</article>`;
}

function renderAnalyticsChart(trend, {hourly = false} = {}){
  const points = trend?.points || [];
  if (!points.length || !points.some(point => Number(point.revenue) > 0)) {
    return '<p class="muted analytics-empty">No sales in this period.</p>';
  }
  const max = Math.max(1, ...points.map(point => Number(point.revenue) || 0));
  return `<div class="analytics-chart${hourly ? ' analytics-chart-hourly' : ''}" role="img" aria-label="${esc(trend.label || 'Revenue')} trend">${points.map((point, index) => {
    const revenue = Number(point.revenue) || 0;
    const height = Math.max(3, Math.round(revenue / max * 150));
    const label = hourly ? (index % 3 === 0 ? point.label.slice(0, 2) : '') : point.label;
    return `<div class="analytics-bar" title="${esc(point.label)}: ${money(revenue)} (${point.transactions} sale${Number(point.transactions) === 1 ? '' : 's'})"><b>${compactMoney(revenue)}</b><span style="height:${height}px"></span><small>${esc(label)}</small></div>`;
  }).join('')}</div>`;
}

function renderRankedList(title, rows, metric){
  if (!rows?.length) return `<div class="analytics-list-block"><h3>${esc(title)}</h3><p class="muted analytics-empty">No sales in this period.</p></div>`;
  const item = row => `<li><span>${esc(row.name)}</span><b>${metric === 'units' ? `${Number(row.units)} units` : money(row.revenue)}</b></li>`;
  const visible = rows.slice(0, 5).map(item).join('');
  const extra = rows.slice(5, 10);
  return `<div class="analytics-list-block"><h3>${esc(title)}</h3><ol class="analytics-list">${visible}</ol>${extra.length ? `<details class="analytics-more"><summary>Show top ${Math.min(10, rows.length)}</summary><ol class="analytics-list" start="6">${extra.map(item).join('')}</ol></details>` : ''}</div>`;
}

function renderMixTable(title, rows){
  if (!rows?.length) return `<div class="analytics-list-block"><h3>${esc(title)}</h3><p class="muted analytics-empty">No product sales in this period.</p></div>`;
  return `<div class="analytics-list-block"><h3>${esc(title)}</h3><div class="table-wrap"><table><thead><tr><th>Name</th><th>Units</th><th>Revenue</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name)}</td><td>${Number(row.units)}</td><td>${money(row.revenue)}</td></tr>`).join('')}</tbody></table></div></div>`;
}

function renderTopCustomers(rows){
  if (!rows?.length) return '<p class="muted analytics-empty">No named customers purchased in this period.</p>';
  return `<div class="table-wrap"><table><thead><tr><th>Customer</th><th>Sales</th><th>Revenue</th><th>Last purchase</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name)}</td><td>${Number(row.transactions)}</td><td>${money(row.revenue)}</td><td>${row.last_purchase ? receiptDate(row.last_purchase) : '—'}</td></tr>`).join('')}</tbody></table></div>`;
}

function renderBranchTable(rows){
  if (!rows?.length) return '<div class="analytics-list-block"><h3>By branch</h3><p class="muted analytics-empty">No sales in this period.</p></div>';
  return `<div class="analytics-list-block"><h3>By branch</h3><div class="table-wrap"><table><thead><tr><th>Branch</th><th>Transactions</th><th>Revenue</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.branch)}</td><td>${Number(row.transactions)}</td><td>${money(row.revenue)}</td></tr>`).join('')}</tbody></table></div></div>`;
}

function renderPaymentMixTable(rows){
  if (!rows?.length) return '<div class="analytics-list-block"><h3>By payment method</h3><p class="muted analytics-empty">No sales in this period.</p></div>';
  return `<div class="analytics-list-block"><h3>By payment method</h3><div class="table-wrap"><table><thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.method)}</td><td>${Number(row.transactions)}</td><td>${money(row.revenue)}</td></tr>`).join('')}</tbody></table></div></div>`;
}

function renderAnalytics(d){
  const period = d.period;
  const sales = d.sales;
  const money_ = d.money;
  const restricted = Boolean(d.restricted);
  const previousLabel = `vs ${period.comparison_label}`;
  const lastYearDelta = sales.change.revenue_last_year_pct === null
    ? 'no comparison for last year'
    : `${sales.change.revenue_last_year_pct >= 0 ? 'up' : 'down'} ${Math.abs(sales.change.revenue_last_year_pct).toFixed(1)}% vs same period last year`;

  qs('#analyticsPeriodLabel').textContent = `${period.key === 'custom' ? period.label : `${period.label} · ${analyticsDate(period.start)} – ${analyticsDate(period.end)}`} · compared with ${period.comparison_label}`;
  qsa('#analyticsPeriodTabs button').forEach(tab => tab.classList.toggle('active', tab.dataset.period === period.key));
  qs('#analyticsStart').value = period.start;
  qs('#analyticsEnd').value = period.end;

  const sections = [];

  sections.push(`<section class="panel analytics-section">
    <h2>Sales</h2>
    <div class="analytics-kpis">
      ${analyticsCard('Revenue', money(sales.revenue), {delta: analyticsDelta(sales.change.revenue_pct, previousLabel), sub: lastYearDelta})}
      ${analyticsCard('Transactions', Number(sales.transactions), {delta: analyticsCountDelta(sales.change.transactions, previousLabel), sub: `previous: ${Number(sales.previous.transactions)}`})}
      ${analyticsCard('Average basket', money(sales.avg_basket), {delta: analyticsDelta(sales.change.avg_basket_pct, previousLabel)})}
      ${restricted
        ? analyticsCard('Gross profit', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
        : sales.gross_profit === null
          ? analyticsCard('Gross profit', 'No cost data', {sub: 'Add buying prices to unlock profit'})
          : analyticsCard('Gross profit', money(sales.gross_profit), {delta: analyticsDelta(sales.change.gross_profit_pct, previousLabel), sub: 'Estimated from current cost'})}
      ${restricted
        ? analyticsCard('Gross margin', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
        : analyticsCard('Gross margin', sales.gross_margin_pct === null ? '—' : `${Number(sales.gross_margin_pct).toFixed(1)}%`, {sub: 'Net of VAT'})}
    </div>
    <p class="muted analytics-note">${period.key === 'today'
      ? (sales.last_sale_at ? `Last sale: ${receiptDate(sales.last_sale_at)}` : 'No sales yet today.')
      : `Previous period revenue: ${money(sales.previous.revenue)} · same period last year: ${money(sales.last_year.revenue)}`}</p>
    ${renderAnalyticsChart(d.trend)}
    ${period.key === 'today' && d.hourly ? `<h3 class="analytics-subhead">Sales by hour</h3>${renderAnalyticsChart({label: 'Sales by hour', points: d.hourly}, {hourly: true})}` : ''}
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Money in</h2>
    <div class="analytics-kpis">
      ${analyticsCard('Cash', money(money_.cash.revenue), {sub: `${Number(money_.cash.count)} sale${Number(money_.cash.count) === 1 ? '' : 's'}`})}
      ${analyticsCard('M-Pesa', money(money_.mpesa.revenue), {sub: `${Number(money_.mpesa.count)} sale${Number(money_.mpesa.count) === 1 ? '' : 's'}`})}
      ${analyticsCard('Open M-Pesa', money(Number(money_.pending.amount) + Number(money_.failed.amount)), {sub: `${Number(money_.pending.count)} pending · ${Number(money_.failed.count)} failed`})}
      ${analyticsCard('Discounts given', money(money_.discounts), {sub: money_.discount_pct === null ? 'No sales in period' : `${Number(money_.discount_pct).toFixed(1)}% of gross`})}
      ${analyticsCard('Voids', money(money_.voids.void_value), {sub: `${Number(money_.voids.count)} voided sale${Number(money_.voids.count) === 1 ? '' : 's'}`})}
      ${analyticsCard('Returns refunded', money(d.returns.amount), {sub: `${Number(d.returns.count)} return${Number(d.returns.count) === 1 ? '' : 's'} · ${Number(d.returns.units)} unit(s) in period`})}
      ${analyticsCard('Credit outstanding', money(d.receivables.outstanding), {sub: `${Number(d.receivables.debtors)} debtor(s) · all periods`})}
      ${analyticsCard('VAT collected', money(money_.vat), {sub: 'Included in revenue'})}
    </div>
    ${money_.voids.by_cashier?.length ? `<p class="muted analytics-note">Voids by cashier: ${money_.voids.by_cashier.map(row => `${esc(row.name)} (${Number(row.count)})`).join(' · ')}</p>` : ''}
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Top performers</h2>
    <div class="analytics-lists">
      ${renderRankedList('Top products by revenue', d.top_products.by_revenue, 'revenue')}
      ${renderRankedList('Top products by units', d.top_products.by_units, 'units')}
      ${renderRankedList('Top cashier', d.top_cashiers, 'revenue')}
    </div>
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Inventory</h2>
    <div class="analytics-kpis">
      ${analyticsCard('Products tracked', Number(d.inventory.products))}
      ${analyticsCard('Low stock', Number(d.inventory.low_stock), {sub: 'At or below reorder level'})}
      ${analyticsCard('Out of stock', Number(d.inventory.out_of_stock))}
      ${d.inventory.at_cost === null
        ? analyticsCard('Inventory at cost', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
        : analyticsCard('Inventory at cost', money(d.inventory.at_cost), {sub: 'At current buying price'})}
      ${analyticsCard('Inventory at retail', money(d.inventory.at_retail), {sub: 'At current selling price'})}
      ${d.inventory.potential_margin === null
        ? analyticsCard('Potential margin', 'Restricted', {restricted: true, sub: 'Requires cost.view'})
        : analyticsCard('Potential margin', money(d.inventory.potential_margin), {sub: 'Retail minus cost'})}
    </div>
    <p class="muted analytics-note">Inventory values are a snapshot at current prices, not period movement.</p>
  </section>`);

  const canEditExpenses = state.user?.role === 'ADMIN';
  sections.push(`<section class="panel analytics-section">
    <h2>Expenses</h2>
    ${Number(d.expenses.count) > 0
      ? `<div class="analytics-kpis">${analyticsCard('Total expenses', money(d.expenses.total), {sub: `${Number(d.expenses.count)} entr${Number(d.expenses.count) === 1 ? 'y' : 'ies'}`})}</div>
         <h3>By category</h3>
         <div class="table-wrap"><table><thead><tr><th>Category</th><th>Entries</th><th>Total</th></tr></thead><tbody>${d.expenses.by_category.map(row => `<tr><td>${esc(row.name)}</td><td>${Number(row.count)}</td><td>${money(row.total)}</td></tr>`).join('')}</tbody></table></div>
         ${d.expenses.unused?.length ? `<p class="muted analytics-note">No spend this period: ${d.expenses.unused.map(esc).join(' · ')}</p>` : ''}
         <h3>Entries</h3>
         <div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Reference / Paid by</th>${canEditExpenses ? '<th>Action</th>' : ''}</tr></thead><tbody>${(d.expenses.entries || []).map(row => `<tr><td>${esc(receiptDate(row.created_at))}</td><td>${esc(row.category)}</td><td>${esc(row.description || '')}</td><td>${money(row.amount)}</td><td>${esc(row.reference || '-')}</td>${canEditExpenses ? `<td><button class="secondary" type="button" data-analytics-expense-edit="${row.id}" data-source="${esc(row.source)}">Edit</button></td>` : ''}</tr>`).join('')}</tbody></table></div>
         ${canEditExpenses ? '<p class="muted analytics-note">Admins can edit an entry here; the change also shows on Cash Flow.</p>' : ''}`
      : '<p class="muted analytics-empty">No expenses recorded for this period.</p>'}
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Sales mix</h2>
    <div class="analytics-lists">
      ${renderMixTable('By category', d.categories)}
      ${renderMixTable('By brand', d.brands)}
    </div>
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Customers</h2>
    <div class="analytics-kpis">
      ${analyticsCard('New customers', Number(d.customers.new), {sub: 'First purchase in period'})}
      ${analyticsCard('Returning customers', Number(d.customers.returning), {sub: 'Purchased before this period'})}
      ${analyticsCard('Walk-in sales', Number(d.customers.walk_in_sales), {sub: 'Unnamed buyers · approximate'})}
    </div>
    ${renderTopCustomers(d.customers.top)}
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Branches &amp; payment mix</h2>
    <div class="analytics-lists">
      ${renderBranchTable(d.branches)}
      ${renderPaymentMixTable(d.payment_mix)}
    </div>
  </section>`);

  sections.push(`<section class="panel analytics-section">
    <h2>Credit &amp; receivables</h2>
    <div class="analytics-kpis">
      ${analyticsCard('Outstanding credit', money(d.receivables.outstanding), {sub: `${Number(d.receivables.debtors)} debtor(s) · all periods`})}
      ${analyticsCard('0–30 days', money(d.receivables.ageing.current || 0))}
      ${analyticsCard('31–60 days', money(d.receivables.ageing['31_60'] || 0))}
      ${analyticsCard('61–90 days', money(d.receivables.ageing['61_90'] || 0))}
      ${analyticsCard('Over 90 days', money(d.receivables.ageing.over_90 || 0))}
    </div>
    ${renderDebtorsTable(d.receivables.debtors_list)}
  </section>`);

  const compliance = d.compliance;
  sections.push(`<section class="panel compliance-section">
    <h2>Compliance</h2>
    <div class="compliance-strip">
      <div><span>eTIMS unfiled</span><strong>${Number(compliance.pending)}</strong><small>${Number(compliance.pending) === 0 ? 'Nothing pending' : 'Completed sales awaiting filing'}</small></div>
      <div><span>Filed</span><strong>${Number(compliance.filed)}</strong></div>
      <div><span>Failed</span><strong>${Number(compliance.failed)}</strong></div>
      <div><span>Exempt</span><strong>${Number(compliance.exempted)}</strong></div>
      <div><span>Open backlog</span><strong>${Number(compliance.backlog.count)}</strong><small>${compliance.backlog.oldest_days === null ? 'No open backlog' : `Oldest ${Number(compliance.backlog.oldest_days)} day(s)`}</small></div>
      <div><span>VAT collected</span><strong>${money(money_.vat)}</strong><small>Included in revenue</small></div>
    </div>
  </section>`);

  qs('#analyticsBody').innerHTML = sections.join('');
  qsa('[data-analytics-expense-edit]').forEach(button => button.onclick = () => openCashEntryEditor(button.dataset.source, +button.dataset.analyticsExpenseEdit, d.expenses.entries));
}

async function loadAnalytics(){
  const report = await api('analytics', {period: analyticsState.period, start: analyticsState.start, end: analyticsState.end});
  renderAnalytics(report);
}
function renderQuoteCart(){
  const subtotal=state.cart.reduce((sum,item)=>sum+item.price*item.qty,0), discount=+qs('#quoteDiscount').value||0, total=Math.max(0,subtotal-discount);
  qs('#quoteCart').innerHTML=state.cart.length ? state.cart.map(item=>`<div class="cart-line"><div><b>${esc(item.name)}</b><small>${money(item.price*item.qty)}</small></div><span>${esc(item.qty)}</span><button type="button" data-quote-remove="${item.id}">x</button></div>`).join('') : '<p class="muted">Add products from the Sales screen before saving a quotation.</p>';
  qsa('[data-quote-remove]').forEach(button=>button.onclick=()=>{state.cart=state.cart.filter(item=>item.id!==+button.dataset.quoteRemove);renderQuoteCart()});
  qs('#quoteTotals').innerHTML=`<div><span>Subtotal</span><b>${money(subtotal)}</b></div><div><span>VAT</span><b>Calculated by server</b></div><div class="grand"><span>Total</span><b>${money(total)}</b></div>`;
}
async function createQuotation(){
  const r=await api('create_quotation',{customer_name:qs('#quoteCustomer').value,customer_kra_pin:qs('#quoteCustomerKraPin').value,valid_until:qs('#quoteValidUntil').value,discount:+qs('#quoteDiscount').value||0,cart:state.cart});
  state.cart=[]; renderQuoteCart(); await loadQuotations(); toast(`Quotation saved: ${r.quote_no}`);
}
async function loadAdmin(){
  const isAdmin = state.user?.role === 'ADMIN';
  const parts=[];
  if (isAdmin) {
    const users = (await api('list&table=users', null, 'GET')).rows;
    parts.push('<h2>Employee accounts</h2>' + renderUserAccounts(users));
  }
  const employees = (await api('list&table=employees', null, 'GET')).rows;
  const branches = (await api('list&table=branches', null, 'GET')).rows;
  state.staff = {employees, branches};
  parts.push('<h2>Employees</h2>' + renderStaffTable('employees', employees));
  parts.push('<h2>Branches</h2>' + renderStaffTable('branches', branches));
  qs('#adminTables').innerHTML=parts.join('');
  qsa('[data-edit-staff]').forEach(button => button.onclick = () => openStaffEditor(button.dataset.editStaff, +button.dataset.staffId));
  qsa('[data-delete-staff]').forEach(button => button.onclick = () => deleteStaff(button.dataset.deleteStaff, +button.dataset.staffId));
  if (isAdmin) {
    qsa('[data-toggle-user]').forEach(button => button.onclick = () => setUserActive(+button.dataset.toggleUser, button.dataset.active !== '1'));
    qsa('[data-delete-user]').forEach(button => button.onclick = () => deleteUser(+button.dataset.deleteUser));
    qsa('[data-reset-user]').forEach(button => button.onclick = () => resetUserPassword(+button.dataset.resetUser));
    await loadAdminRecordPickers();
    await Promise.all([loadSystemHealth(), loadAudit()]);
  }
}
async function loadAdminRecordPickers(){
  const productPick = qs('#adminProductPick');
  const supplierPick = qs('#adminSupplierPick');
  if (!productPick || !supplierPick) return;
  const [products, suppliers] = await Promise.all([
    api('list&table=products', null, 'GET').catch(() => ({rows: []})),
    api('list&table=suppliers', null, 'GET').catch(() => ({rows: []})),
  ]);
  const productRows = products.rows || [];
  const supplierRows = suppliers.rows || [];
  productPick.innerHTML = '<option value="">Select product</option>' + productRows.map(p => `<option value="${esc(p.id)}">${esc(p.sku)} — ${esc(p.name)}</option>`).join('');
  supplierPick.innerHTML = '<option value="">Select supplier</option>' + supplierRows.map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
  qs('#adminProductSupplier').innerHTML = '<option value="">Unassigned</option>' + supplierRows.map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
  const customerPick = qs('#adminCustomerPick');
  if (customerPick) {
    let customerRows = [];
    try {
      customerRows = (await api('list&table=customers', null, 'GET')).rows || [];
    } catch (error) {
      customerRows = [];
    }
    customerPick.innerHTML = '<option value="">Select customer</option>' + customerRows.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
  }
}
async function adminLoadCustomer(){
  const id = qs('#adminCustomerPick').value;
  if (!id) return toast('Choose a customer to load');
  const result = await api(`admin_customer_detail&id=${id}`, null, 'GET');
  const c = result.customer;
  const form = qs('#adminCustomerEditForm');
  form.reset();
  form.elements['id'].value = c.id;
  form.elements['name'].value = c.name || '';
  form.elements['phone'].value = c.phone || '';
  form.elements['email'].value = c.email || '';
  form.elements['kra_pin'].value = c.kra_pin || '';
  form.elements['balance'].value = c.balance ?? 0;
  form.classList.remove('hidden');
  qs('#adminCustomerHint').classList.remove('hidden');
}
async function adminSaveCustomer(form){
  const payload = formData(form);
  try {
    const result = await api('admin_update_customer', payload);
    toast(result.changed ? `Customer updated: ${result.changes.length} change(s)` : 'Customer saved with no changes');
    await loadAdminRecordPickers();
  } catch (error) {
    toast(error.message);
  }
}
async function adminLoadProduct(){
  const id = qs('#adminProductPick').value;
  if (!id) return toast('Choose a product to load');
  const result = await api(`admin_product_detail&id=${id}`, null, 'GET');
  const p = result.product;
  const form = qs('#adminProductEditForm');
  form.reset();
  form.elements['id'].value = p.id;
  form.elements['sku'].value = p.sku || '';
  form.elements['barcode'].value = p.barcode || '';
  form.elements['name'].value = p.name || '';
  form.elements['category'].value = p.category || '';
  form.elements['brand'].value = p.brand || '';
  form.elements['warehouse'].value = p.warehouse || 'Main';
  form.elements['branch'].value = p.branch || 'Main';
  form.elements['supplier_id'].value = p.supplier_id ?? '';
  form.elements['buying_price'].value = p.buying_price ?? '';
  form.elements['selling_price'].value = p.selling_price ?? '';
  form.elements['vat_rate'].value = p.vat_rate ?? '';
  form.elements['quantity'].value = p.quantity ?? 0;
  form.elements['supplied_quantity'].value = p.supplied_quantity ?? 0;
  form.elements['reorder_level'].value = p.reorder_level ?? 0;
  form.classList.remove('hidden');
  qs('#adminProductHint').classList.remove('hidden');
}
async function adminSaveProduct(form){
  const payload = formData(form);
  try {
    const result = await api('admin_update_product', payload);
    toast(result.changed ? `Product updated: ${result.changes.length} change(s)` : 'Product saved with no changes');
    await loadAdminRecordPickers();
  } catch (error) {
    toast(error.message);
  }
}
async function adminLoadSupplier(){
  const id = qs('#adminSupplierPick').value;
  if (!id) return toast('Choose a supplier to load');
  const result = await api(`admin_supplier_detail&id=${id}`, null, 'GET');
  const s = result.supplier;
  const form = qs('#adminSupplierEditForm');
  form.reset();
  form.elements['id'].value = s.id;
  form.elements['name'].value = s.name || '';
  form.elements['phone'].value = s.phone || '';
  form.elements['email'].value = s.email || '';
  form.elements['kra_pin'].value = s.kra_pin || '';
  form.elements['balance'].value = s.balance ?? 0;
  form.classList.remove('hidden');
}
async function adminSaveSupplier(form){
  const payload = formData(form);
  try {
    const result = await api('admin_update_supplier', payload);
    toast(result.changed ? `Supplier updated: ${result.changes.length} change(s)` : 'Supplier saved with no changes');
    await loadAdminRecordPickers();
  } catch (error) {
    toast(error.message);
  }
}
async function downloadCsv(type){
  const params = new URLSearchParams({action:'export_csv', type});
  if(type === 'analytics'){
    params.set('period', analyticsState.period);
    params.set('start', analyticsState.start);
    params.set('end', analyticsState.end);
  } else if(type === 'cash_flow'){
    params.set('period', qs('#cashFlowPeriod').value || 'month');
    params.set('date', qs('#cashFlowDate').value || new Date().toISOString().slice(0,10));
  }
  const response = await fetch(`../api.php?${params}`, {method:'GET', credentials:'same-origin'});
  if(!response.ok){
    const result = await response.json();
    throw new Error(result.error || 'CSV export failed');
  }
  const blob = await response.blob();
  const disposition = response.headers.get('Content-Disposition') || '';
  const filename = disposition.match(/filename="([^"]+)"/)?.[1] || `${type}.csv`;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  toast(`${filename} downloaded`);
}
function renderStaffTable(tableName, rows){
  if (!rows.length) return '<p class="muted">No records yet.</p>';
  const canDelete = state.user?.role === 'ADMIN';
  const actionCell = row => {
    const edit = `<button class="secondary" type="button" data-edit-staff="${esc(tableName)}" data-staff-id="${row.id}">Edit</button>`;
    const remove = canDelete ? ` <button class="danger" type="button" data-delete-staff="${esc(tableName)}" data-staff-id="${row.id}">Delete</button>` : '';
    return edit + remove;
  };
  if (tableName === 'employees') {
    return `<div class="table-wrap"><table><thead><tr><th>Name</th><th>Phone</th><th>Role</th><th>Salary</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name || '')}</td><td>${esc(row.phone || '')}</td><td>${esc(row.role || '')}</td><td>${money(row.salary)}</td><td>${Number(row.active) === 1 ? 'Active' : 'Inactive'}</td><td>${actionCell(row)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  return `<div class="table-wrap"><table><thead><tr><th>Name</th><th>Location</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.name || '')}</td><td>${esc(row.location || '')}</td><td>${esc(row.phone || '')}</td><td>${Number(row.active) === 1 ? 'Active' : 'Inactive'}</td><td>${actionCell(row)}</td></tr>`).join('')}</tbody></table></div>`;
}
function openStaffEditor(tableName, id){
  const row = (state.staff?.[tableName] || []).find(item => Number(item.id) === Number(id));
  if (!row) return toast('Record not found');
  const isBranch = tableName === 'branches';
  const form = qs('#staffEditForm');
  form.elements['table'].value = tableName;
  form.elements['id'].value = row.id;
  form.elements['name'].value = row.name || '';
  form.elements['phone'].value = row.phone || '';
  form.elements['role'].value = row.role || '';
  form.elements['salary'].value = Number(row.salary) || 0;
  form.elements['location'].value = row.location || '';
  form.elements['active'].value = Number(row.active) === 0 ? '0' : '1';
  qs('#staffEditTitle').textContent = isBranch ? 'Edit branch' : 'Edit employee';
  qs('#staffRoleWrap').classList.toggle('hidden', isBranch);
  qs('#staffSalaryWrap').classList.toggle('hidden', isBranch);
  qs('#staffLocationWrap').classList.toggle('hidden', !isBranch);
  form.elements['role'].required = !isBranch;
  qs('#staffEditModal').classList.remove('hidden');
  form.elements['name'].focus();
}
function closeStaffEditor(){
  qs('#staffEditModal').classList.add('hidden');
}
async function deleteStaff(tableName, id){
  if (!window.confirm(`Delete this ${tableName === 'branches' ? 'branch' : 'employee'} permanently?`)) return;
  try {
    await api('delete_staff', {table: tableName, id});
    await loadAdmin();
    toast(tableName === 'branches' ? 'Branch deleted' : 'Employee deleted');
  } catch (error) {
    toast(error.message);
  }
}
function renderUserAccounts(rows){
  if (!rows.length) return '<p class="muted">No employee accounts yet.</p>';
  return `<div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Branch</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.map(row => {
    const active = +row.active === 1;
   const isMainAdmin = row.id === 1;
   const protectedLabel = isMainAdmin ? '<span class="muted">Protected</span> ' : '';
   const resetButton = `<button class="secondary" type="button" data-reset-user="${row.id}">Reset password</button> `;
   const accountButtons = isMainAdmin ? '' : `<button class="secondary" type="button" data-toggle-user="${row.id}" data-active="${active ? 1 : 0}">${active ? 'Deactivate' : 'Activate'}</button> <button class="danger" type="button" data-delete-user="${row.id}">Delete</button>`;
   return `<tr><td>${esc(row.full_name || '')}</td><td>${esc(row.username || '')}</td><td>${esc(row.role || '')}</td><td>${esc(row.branch || '')}</td><td>${active ? 'Active' : 'Deactivated'}</td><td>${protectedLabel}${resetButton}${accountButtons}</td></tr>`;
  }).join('')}</tbody></table></div>`;
}
async function loadSystemHealth(){
  const result = await api('system_health', null, 'GET');
  const integrity = result.latest_integrity_run;
  const findings = integrity?.findings || [];
  qs('#systemHealth').innerHTML = `<div class="kpis">
    <div class="panel kpi">Database<strong>${esc(result.database)}</strong></div>
    <div class="panel kpi">Foreign-key issues<strong>${esc(result.foreign_key_violations)}</strong></div>
    <div class="panel kpi">Last backup<strong>${esc(result.last_backup ? receiptDate(result.last_backup) : 'None')}</strong></div>
    <div class="panel kpi">Log size<strong>${Math.ceil(Number(result.log_bytes || 0) / 1024)} KB</strong></div>
  </div><p>Latest integrity run: <b>${esc(integrity?.status || 'Not run')}</b>${integrity?.completed_at ? ` · ${esc(receiptDate(integrity.completed_at))}` : ''}</p>
  ${findings.length ? `<div class="table-wrap">${table(findings.map(item=>({check:item.check,details:JSON.stringify(item.details)})))}</div>` : '<p class="muted">No integrity findings.</p>'}`;
}
async function loadAudit(page = state.auditPage){
  state.auditPage = Math.max(1, page);
  const search = qs('#auditSearch').value.trim();
  const result = await api(`audit_list&page=${state.auditPage}&limit=25&search=${encodeURIComponent(search)}`, null, 'GET');
  qs('#auditTable').innerHTML = table(result.rows);
  qs('#auditPageLabel').textContent = `Page ${result.page} of ${Math.max(1, result.pages)} · ${result.total} records`;
  qs('#auditPreviousBtn').disabled = result.page <= 1;
  qs('#auditNextBtn').disabled = result.page >= result.pages;
}
async function voidSale(id){
  if (!window.confirm('Void this completed sale and return its items to stock?')) return;
  try {
    await api('void_sale', {sale_id:id});
    if (state.page === 'reports') await loadReports();
    else await loadSales();
    toast('Sale voided and stock restored');
  } catch (error) {
    toast(error.message);
  }
}
async function setUserActive(id, active){
  try {
    await api('set_user_active', {id, active: active ? 1 : 0});
    await loadAdmin();
    toast(active ? 'Account activated' : 'Account deactivated');
  } catch (error) {
    toast(error.message);
  }
}
async function deleteUser(id){
  if (!window.confirm('Delete this account permanently?')) return;
  try {
    await api('delete_user', {id});
    await loadAdmin();
    toast('Account deleted');
  } catch (error) {
    toast(error.message);
  }
}
async function resetUserPassword(id){
  if (!window.confirm('Generate a temporary password? The user will be signed out and must sign in again.')) return;
  try {
    const r = await api('reset_user_password', {id});
    const selfReset = id === state.user?.id;
    const target = qs('#tempPasswordText');
    if (target) target.textContent = `${r.full_name || r.username} (${r.username}): ${r.temporary_password}`;
    qs('#tempPasswordModal')?.classList.remove('hidden');
    if (selfReset) {
      qs('#tempPasswordCloseBtn').onclick = () => location.reload();
    } else {
      qs('#tempPasswordCloseBtn').onclick = () => qs('#tempPasswordModal').classList.add('hidden');
      await loadAdmin();
    }
    if (selfReset) toast('Your password was reset. Sign in again with the new password.');
    else toast('Password reset');
  } catch (error) {
    toast(error.message);
  }
}
async function loadReports(){
  const salesRows = (await api('list&table=sales', null, 'GET')).rows;
  qs('#salesTable').innerHTML = `<table><thead><tr><th>Receipt</th><th>Customer</th><th>Method</th><th>Cashier</th><th>Total</th><th>Time</th><th>Action</th></tr></thead><tbody>${salesRows.map(r=>`<tr><td>${esc(r.receipt_no)}</td><td>${esc(r.customer_name || 'Walk-in Customer')}</td><td>${esc(r.payment_method)}</td><td>${esc(r.cashier_name || 'Unknown')}</td><td>${money(r.total)}</td><td>${esc(receiptDate(r.created_at))}</td><td><button class="secondary" data-print-receipt="${r.id}">Print</button>${state.permissions['sale.void'] ? ` <button class="danger" data-void-sale="${r.id}">Void</button>` : ''}</td></tr>`).join('')}</tbody></table>`;
  qsa('[data-print-receipt]').forEach(btn => btn.onclick = async () => await printReceipt(+btn.dataset.printReceipt));
  qsa('[data-void-sale]').forEach(btn => btn.onclick = () => voidSale(+btn.dataset.voidSale));
  qs('#syncTable').innerHTML=table((await api('list&table=sync_queue', null, 'GET')).rows);
  await loadBackups();
}

async function loadBackups(){
  const target = qs('#backupsTable');
  if (!target) return;
  try {
    const r = await api('backup_list', null, 'GET');
    const rows = r.backups || [];
    const isAdmin = state.user?.role === 'ADMIN';
    const toggle = qs('#autoBackupToggle');
    if (toggle) { toggle.checked = !!r.auto_enabled; toggle.disabled = !isAdmin; }
    if (!rows.length) {
      target.innerHTML = '<p class="muted">No backups yet.</p>';
      return;
    }
    target.innerHTML = `<table><thead><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.file)}</td><td>${esc(row.label)}</td><td>${Math.round(Number(row.size)/1024)} KB</td><td>${esc(receiptDate(row.created_at))}</td><td><div class="backup-actions"><button class="secondary" type="button" data-backup-download="${esc(row.file)}">Download</button>${isAdmin ? `<button class="secondary" type="button" data-backup-restore="${esc(row.file)}">Restore</button><button class="danger" type="button" data-backup-delete="${esc(row.file)}">Delete</button>` : ''}</div></td></tr>`).join('')}</tbody></table>`;
    qsa('[data-backup-download]').forEach(btn => btn.onclick = () => { window.location.href = `../api.php?action=backup_download&file=${encodeURIComponent(btn.dataset.backupDownload)}`; });
    qsa('[data-backup-restore]').forEach(btn => btn.onclick = () => restoreBackup(btn.dataset.backupRestore));
    qsa('[data-backup-delete]').forEach(btn => btn.onclick = () => deleteBackup(btn.dataset.backupDelete));
  } catch (error) {
    target.innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}
async function restoreBackup(file){
  if (!window.confirm(`Replace the live database with ${file}?\n\nA safety backup of the current data is created first.`)) return;
  const confirmText = window.prompt('Type RESTORE to confirm:', '');
  if (confirmText === null) return;
  const password = window.prompt('Enter your current password to continue:', '');
  if (password === null) return;
  try {
    await api('backup_restore', {file, confirm: confirmText, password});
    toast('Backup restored. Reloading…');
    setTimeout(() => location.reload(), 1200);
  } catch (error) { toast(error.message); }
}
async function deleteBackup(file){
  if (!window.confirm(`Delete ${file}? This cannot be undone.`)) return;
  try { await api('backup_delete', {file}); await loadBackups(); toast('Backup deleted'); }
  catch (error) { toast(error.message); }
}

async function loadSettings(){
  const isAdmin = state.user?.role === 'ADMIN';
  qsa('.admin-only').forEach(el => el.classList.toggle('hidden', !isAdmin));
  qs('#activateLicenseBtn')?.classList.toggle('hidden', !isAdmin);
  qs('#deactivateLicenseBtn')?.classList.toggle('hidden', !isAdmin);
  qs('#licenseKeyInput')?.classList.toggle('hidden', !isAdmin);
  if (isAdmin) {
    const config = await api('admin_settings', null, 'GET');
    qs('#sessionTimeoutMinutes').value = config.session_timeout_minutes;
    for (const role of ['MANAGER', 'EMPLOYEE']) {
      const prefix = role.toLowerCase();
      const permissions = config.permissions[role];
      qs(`#${prefix}SaleVoid`).checked = !!permissions['sale.void'];
      qs(`#${prefix}CostView`).checked = !!permissions['cost.view'];
      qs(`#${prefix}RecordsDelete`).checked = !!permissions['records.delete'];
      qs(`#${prefix}DiscountLimit`).value = permissions.discount_max_percent;
    }
  }
  renderLicensePanel();
  try {
    const r = await api('license_status', null, 'GET');
    state.license = r.license || state.license;
  } catch (error) { /* keep last known status */ }
  renderLicensePanel();
  renderLicenseBanner();
}
function renderLicensePanel(){
  const target = qs('#licenseStatus');
  if (!target) return;
  const l = state.license;
  if (!l) { target.innerHTML = '<span class="muted">License status unavailable.</span>'; return; }
  let expires = '—';
  if (l.expires_at) expires = receiptDate(l.expires_at);
  else if (l.state === 'trial' || l.state === 'trial_expired') {
    expires = l.trial_ends_at ? `Trial ends ${receiptDate(l.trial_ends_at)}` : '—';
  } else if (l.state === 'active') {
    expires = 'Perpetual';
  }
  const rows = [
    ['Status', l.state],
    ['Plan', l.plan || 'trial'],
    ['Customer', l.customer || '—'],
    ['Expires', expires],
    ['Days left', String(l.days_left || 0)],
    ['Machine ID', l.machine_id || '—'],
  ];
  if (l.message) rows.push(['Note', l.message]);
  target.innerHTML = rows.map(([k,v]) => `<div><span class="muted">${esc(k)}</span> ${esc(v)}</div>`).join('');
}

function syncBrandingForms(){
  const c = state.company;
  if (!c) return;
  const values = {
    company_name: c.name,
    company_email: c.email,
    company_phone: c.phone,
    company_location: c.location,
    company_tagline: c.tagline,
    brand_color: c.color,
    logo_path: c.logo,
    receipt_footer: c.footer,
  };
  [qs('#brandingForm'), qs('#setupWizardForm')].forEach(form => {
    if (!form) return;
    for (const [name, value] of Object.entries(values)) {
      const field = form.elements[name];
      if (field) field.value = value ?? '';
    }
  });
}
async function saveSetup(form, closeModal = true){
  const result = await api('save_setup', formData(form));
  state.company = result.company || state.company;
  state.license = result.license || state.license;
  state.setupCompleted = result.setup_completed === true;
  if (closeModal) {
    qs('#setupWizard')?.classList.add('hidden');
    const licenseInput = form.elements['license_key'];
    if (licenseInput) licenseInput.value = '';
  }
  applyBranding();
  syncBrandingForms();
  renderLicensePanel();
  renderLicenseBanner();
  toast('Business profile saved');
}

let importCsv = '';

async function readCsvFile(file){
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Could not read the CSV file'));
    reader.readAsText(file);
  });
}
async function downloadImportTemplate(){
  const type = qs('#importType').value;
  const result = await api(`import_template&type=${encodeURIComponent(type)}`, null, 'GET');
  const blob = new Blob([result.csv], {type: 'text/csv;charset=utf-8'});
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = result.filename || `${type}-template.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(link.href);
}
function renderImportPreview(result){
  const target = qs('#importPreviewResult');
  if (!target) return;
  const summary = `<p><b>${result.row_count}</b> data row${result.row_count === 1 ? '' : 's'}: ${result.insert} new, ${result.update} update${result.update === 1 ? '' : 's'}, ${result.skip} skipped, <b>${result.errors.length}</b> error${result.errors.length === 1 ? '' : 's'}.</p>`;
  const errors = result.errors.length
    ? `<div class="license-banner danger">${result.errors.slice(0, 20).map(esc).join('<br>')}${result.errors.length > 20 ? '<br>…' : ''}</div>`
    : '';
  const rows = (result.rows || []).map(row => `<tr><td>${esc(row.line)}</td><td class="import-action-${esc(row.action)}">${esc(row.action)}</td><td>${esc(row.summary)}</td><td>${esc(row.message)}</td></tr>`).join('');
  target.innerHTML = summary + errors + (rows ? `<table><thead><tr><th>Line</th><th>Action</th><th>Row</th><th>Note</th></tr></thead><tbody>${rows}</tbody></table>` : '');
}
async function previewImport(){
  const file = qs('#importFile').files[0];
  if (!file) return toast('Choose a CSV file first');
  importCsv = await readCsvFile(file);
  const result = await api('import_preview', {
    type: qs('#importType').value,
    mode: qs('#importMode').value,
    csv: importCsv,
  });
  renderImportPreview(result);
  const commit = qs('#importCommitBtn');
  if (commit) commit.disabled = result.errors.length > 0 || result.valid === 0;
  return result;
}
async function commitImport(){
  if (!importCsv) return toast('Preview a CSV file first');
  if (!window.confirm('Import this file now? Rows marked skip will not be changed.')) return;
  const result = await api('import_commit', {
    type: qs('#importType').value,
    mode: qs('#importMode').value,
    csv: importCsv,
  });
  toast(`Imported: ${result.insert} new, ${result.update} updated, ${result.skip} skipped`);
  importCsv = '';
  qs('#importFile').value = '';
  qs('#importCommitBtn').disabled = true;
  qs('#importPreviewResult').innerHTML = '';
  await loadAdmin();
}


qs('#loginForm').onsubmit=async e=>{
  e.preventDefault();
  const credentials = formData(e.target);
  try{
    const result = await api('login', credentials);
    csrfToken = result.csrf || csrfToken;
    state.user = result.user;
    state.permissions = result.permissions || {};
    state.pages = result.pages || [];
    state.license = result.license || state.license;
    state.company = result.company || state.company;
    state.setupCompleted = result.setup_completed !== false;
    showApp();
    maybeShowSetup();
  }catch(err){toast(err.message)}
};
qs('#activateLicenseBtn').onclick=async()=>{
  const key = qs('#licenseKeyInput').value.trim();
  if (!key) return toast('Paste a license key first');
  try{
    const r = await api('license_activate', {key});
    state.license = r.license;
    qs('#licenseKeyInput').value = '';
    renderLicensePanel(); renderLicenseBanner(); toast('License activated');
  }catch(err){toast(err.message)}
};
qs('#deactivateLicenseBtn').onclick=async()=>{
  if (!window.confirm('Remove the license from this installation?')) return;
  try{
    const r = await api('license_deactivate', {});
    state.license = r.license;
    renderLicensePanel(); renderLicenseBanner(); toast('License removed');
  }catch(err){toast(err.message)}
};
qs('#backupRefreshBtn').onclick=()=>loadBackups().catch(error=>toast(error.message));
qsa('[data-export-csv]').forEach(button=>button.onclick=()=>downloadCsv(button.dataset.exportCsv).catch(error=>toast(error.message)));
qs('#autoBackupToggle').onchange=async e=>{
  try{
    await api('save_settings', {backup_auto_enabled: e.target.checked ? 1 : 0});
    toast(e.target.checked ? 'Automatic backups enabled' : 'Automatic backups disabled');
  }catch(error){ e.target.checked = !e.target.checked; toast(error.message); }
};
qs('#logoutBtn').onclick=async()=>{await api('logout'); location.reload()};
qs('#cancelPaymentBtn').onclick=()=>{hidePaymentLoading(); toast('M-Pesa payment canceled. You can retry from the same sale.');};
qs('#paymentMethod').onchange = renderCart;
qs('#customerSelect').onchange = () => {
  const select = qs('#customerSelect');
  const nameInput = qs('#customerName');
  if (!select || !nameInput) return;
  if (select.value) {
    const customer = state.customerOptions.find(item => String(item.id) === select.value);
    nameInput.value = customer ? customer.name : '';
    nameInput.disabled = true;
  } else {
    nameInput.disabled = false;
  }
};
qs('#returnLookup').onclick = () => lookupReturnSale().catch(error => toast(error.message));
qs('#returnSubmit').onclick = () => recordReturn();
qs('#shiftOpenBtn').onclick = () => shiftOpen();
qs('#shiftCloseBtn').onclick = () => shiftClose();
qs('#poAddLine').onclick = () => addPurchaseOrderLine().catch(error => toast(error.message));
qs('#poCreateBtn').onclick = () => createPurchaseOrder().catch(error => toast(error.message));
qs('#poReceiveCancel').onclick = closePurchaseOrderReceive;
qs('#poReceiveModal').onclick = event => { if (event.target === qs('#poReceiveModal')) closePurchaseOrderReceive(); };
qs('#poReceiveForm').onsubmit = async event => {
  event.preventDefault();
  await submitPurchaseOrderReceive(event.target).catch(error => toast(error.message));
};
qs('#customerPaymentForm').onsubmit = async event => {
  event.preventDefault();
  const customerId = Number(qs('#paymentCustomer').value);
  if (!customerId) return toast('Choose a customer with an outstanding balance');
  const amount = qs('#paymentAmount').value;
  if (amount === '' || Number(amount) <= 0) return toast('Enter a payment amount');
  try {
    const result = await api('create_customer_payment', {
      customer_id: customerId,
      amount,
      method: qs('#paymentChannel').value,
      reference: qs('#paymentReference').value.trim(),
    });
    toast(`Payment recorded. ${result.customer} now owes ${money(result.balance)}`);
    qs('#paymentAmount').value = '';
    qs('#paymentReference').value = '';
    await loadPeople();
  } catch (error) {
    toast(error.message);
  }
};
qs('#supplierPaymentForm').onsubmit = async event => {
  event.preventDefault();
  const supplierId = Number(qs('#supplierPaymentSupplier').value);
  if (!supplierId) return toast('Choose a supplier with an outstanding balance');
  const amount = qs('#supplierPaymentAmount').value;
  if (amount === '' || Number(amount) <= 0) return toast('Enter a payment amount');
  try {
    const result = await api('create_supplier_payment', {
      supplier_id: supplierId,
      amount,
      method: qs('#supplierPaymentChannel').value,
      reference: qs('#supplierPaymentReference').value.trim(),
    });
    toast(`Payment recorded. We now owe ${result.supplier} ${money(result.balance)}`);
    qs('#supplierPaymentAmount').value = '';
    qs('#supplierPaymentReference').value = '';
    await loadPeople();
  } catch (error) {
    toast(error.message);
  }
};
qs('#adminProductLoad').onclick = () => adminLoadProduct().catch(error => toast(error.message));
qs('#adminProductEditForm').onsubmit = async event => {
  event.preventDefault();
  await adminSaveProduct(event.target);
};
qs('#adminSupplierLoad').onclick = () => adminLoadSupplier().catch(error => toast(error.message));
qs('#adminSupplierEditForm').onsubmit = async event => {
  event.preventDefault();
  await adminSaveSupplier(event.target);
};
qs('#adminCustomerLoad').onclick = () => adminLoadCustomer().catch(error => toast(error.message));
qs('#adminCustomerEditForm').onsubmit = async event => {
  event.preventDefault();
  await adminSaveCustomer(event.target);
};
qs('#mpesaPhone').addEventListener('input', () => {
  const current = qs('#mpesaPhone');
  current.value = String(current.value || '').replace(/\D/g, '').replace(/^254/, '').slice(0, 9);
});
qs('#productSearch').oninput=renderProducts; qs('#discount').oninput=renderCart; qs('#amountPaid').oninput=renderCart;
qs('#clearCart').onclick=()=>{state.cart=[];renderCart()}; qs('#checkoutBtn').onclick=()=>checkout().catch(e=>toast(e.message));
qs('#holdSale').onclick=async()=>{await api('hold_sale',{cart:state.cart, customer_name:qs('#customerName').value}); state.cart=[]; renderCart(); toast('Sale held')};
qs('#proforma').onclick=()=>window.print();
qs('#newSupplierBtn').onclick=()=>{qs('#newSupplierFields').classList.toggle('hidden'); if(!qs('#newSupplierFields').classList.contains('hidden')) qs('#newSupplierName').focus()};
qs('#productForm').onsubmit=async e=>{e.preventDefault(); try{const data=formData(e.target); if(!qs('#newSupplierFields').classList.contains('hidden')){const supplier=await api('create_supplier',{name:qs('#newSupplierName').value,phone:qs('#newSupplierPhone').value,email:qs('#newSupplierEmail').value,kra_pin:qs('#newSupplierKraPin').value}); data.supplier_id=supplier.id;} await api('save_product', data); e.target.reset(); qs('#newSupplierFields').classList.add('hidden'); await loadProducts(); toast('Product added')}catch(err){toast(err.message)}};
qs('#restockForm').onsubmit=async e=>{
  e.preventDefault();
  const button = qs('#restockSubmit');
  const productId = Number(qs('#restockProduct').value);
  const quantity = Number(qs('#restockQuantity').value);
  if (!productId || !Number.isInteger(quantity) || quantity < 1) {
    toast('Choose a product and enter units received');
    return;
  }
  const payload = {product_id: productId, quantity, reference: qs('#restockReference').value.trim()};
  const buyingPrice = qs('#restockBuyingPrice').value.trim();
  if (buyingPrice !== '') payload.buying_price = buyingPrice;
  button.disabled = true;
  try {
    const result = await api('restock_product', payload);
    e.target.reset();
    await loadProducts();
    qs('#restockSummary').textContent = `${result.name} (${result.sku}): ${result.previous_quantity} → ${result.new_quantity} units`;
    toast(`Stock updated: ${result.name}`);
  } catch (error) {
    toast(error.message);
  } finally {
    button.disabled = false;
  }
};
qs('#quoteDiscount').oninput=renderQuoteCart;
qs('#createQuoteBtn').onclick=()=>createQuotation().catch(error=>toast(error.message));
qs('#cashFlowPeriod').onchange=()=>loadCashFlow().catch(error=>toast(error.message));
qs('#cashFlowDate').onchange=()=>loadCashFlow().catch(error=>toast(error.message));
qs('#cashFlowRefresh').onclick=()=>loadCashFlow().catch(error=>toast(error.message));
qs('#cashMovementType').onchange=syncCashMovementCategory;
syncCashMovementCategory();
qs('#cashEntryEditForm').elements['type'].onchange=syncCashEntryCategory;
document.addEventListener('click', event=>{
  const button=event.target.closest('[data-view-quotation],[data-pdf-quotation],[data-accept-quotation],[data-convert-quotation],[data-delete-quotation],[data-view-sale]');
  if(!button) return;
  if(button.dataset.viewQuotation) printQuotation(+button.dataset.viewQuotation).catch(error=>toast(error.message));
  if(button.dataset.pdfQuotation) printQuotation(+button.dataset.pdfQuotation).catch(error=>toast(error.message));
  if(button.dataset.acceptQuotation) acceptQuotation(+button.dataset.acceptQuotation);
  if(button.dataset.convertQuotation) convertQuotation(+button.dataset.convertQuotation);
  if(button.dataset.deleteQuotation) deleteQuotation(+button.dataset.deleteQuotation, button.dataset.converted === 'true');
  if(button.dataset.viewSale) viewSaleReceipt(+button.dataset.viewSale).catch(error=>toast(error.message));
});
qsa('.simpleForm').forEach(f=>f.onsubmit=async e=>{e.preventDefault(); try{await api(`save_simple&table=${f.dataset.table}`, formData(f)); f.reset(); if (f.id === 'cashMovementForm') syncCashMovementCategory(); await openPage(state.page); if (f.dataset.table === 'cash_transactions') await refreshShiftPanel(); toast('Saved')}catch(err){toast(err.message)}});
qs('#userForm').onsubmit=async e=>{e.preventDefault(); try{await api('create_user', formData(e.target)); e.target.reset(); await loadAdmin(); toast('User created')}catch(err){toast(err.message)}};
qs('#resetBusinessForm').onsubmit=async e=>{e.preventDefault(); if(!window.confirm('This will permanently delete all business data. Continue?')) return; try{await api('reset_business_data',formData(e.target));e.target.reset();await openPage('dashboard');toast('Business data reset. User accounts were preserved.')}catch(err){toast(err.message)}};
qs('#productEditCancel').onclick=closeProductEditor;
qs('#productEditModal').onclick=e=>{ if(e.target===qs('#productEditModal')) closeProductEditor(); };
qs('#productEditForm').onsubmit=async e=>{
  e.preventDefault();
  try {
    const result = await api('update_product', formData(e.target));
    closeProductEditor();
    await loadProducts();
    toast(result.changed ? `Updated ${result.sku}` : 'No changes to save');
  } catch (error) {
    toast(error.message);
  }
};
qs('#personEditCancel').onclick=closePersonEditor;
qs('#personEditModal').onclick=e=>{ if(e.target===qs('#personEditModal')) closePersonEditor(); };
qs('#personEditForm').onsubmit=async e=>{
  e.preventDefault();
  try {
    await api('update_person', formData(e.target));
    closePersonEditor();
    await loadPeople();
    toast('Details updated');
  } catch (error) {
    toast(error.message);
  }
};
qs('#staffEditCancel').onclick=closeStaffEditor;
qs('#staffEditModal').onclick=e=>{ if(e.target===qs('#staffEditModal')) closeStaffEditor(); };
qs('#staffEditForm').onsubmit=async e=>{
  e.preventDefault();
  try {
    await api('update_staff', formData(e.target));
    closeStaffEditor();
    await loadAdmin();
    toast('Details updated');
  } catch (error) {
    toast(error.message);
  }
};
qs('#cashEntryEditCancel').onclick=closeCashEntryEditor;
qs('#cashEntryEditModal').onclick=e=>{ if(e.target===qs('#cashEntryEditModal')) closeCashEntryEditor(); };
qs('#cashEntryEditForm').onsubmit=async e=>{
  e.preventDefault();
  try {
    await api('update_cash_entry', formData(e.target));
    closeCashEntryEditor();
    if (state.page === 'analytics') await loadAnalytics();
    else await loadCashFlow();
    toast('Entry updated');
  } catch (error) {
    toast(error.message);
  }
};
qs('#quotationAddBtn').onclick=addQuotationEditorLine;
qs('#quotationEditCancel').onclick=closeQuotationEditor;
qs('#quotationEditModal').onclick=e=>{ if(e.target===qs('#quotationEditModal')) closeQuotationEditor(); };
qs('#quotationEditForm').onsubmit=async e=>{
  e.preventDefault();
  if(!quoteEditor.lines.length) return toast('Add at least one product');
  const data = formData(e.target);
  const payload = {
    id: quoteEditor.id,
    customer_name: data.customer_name,
    customer_kra_pin: data.customer_kra_pin,
    items: quoteEditor.lines.map(line => line.id ? {id:line.id, quantity:line.qty} : {product_id:line.product_id, quantity:line.qty}),
  };
  try {
    await api('update_quotation', payload);
    closeQuotationEditor();
    await loadQuotations();
    toast('Quotation updated');
  } catch (error) {
    toast(error.message);
  }
};
qs('#roleSettingsForm').onsubmit=async e=>{
  e.preventDefault();
  const permissions = {};
  for (const role of ['MANAGER', 'EMPLOYEE']) {
    const prefix = role.toLowerCase();
    permissions[role] = {
      'sale.void': qs(`#${prefix}SaleVoid`).checked,
      'cost.view': qs(`#${prefix}CostView`).checked,
      'records.delete': qs(`#${prefix}RecordsDelete`).checked,
      discount_max_percent: Number(qs(`#${prefix}DiscountLimit`).value),
    };
  }
  try {
    await api('save_role_settings', {session_timeout_minutes:Number(qs('#sessionTimeoutMinutes').value), permissions});
    toast('Session and permission settings saved');
  } catch (error) {
    toast(error.message);
  }
};
qs('#healthRefreshBtn').onclick=()=>loadSystemHealth().catch(error=>toast(error.message));
qs('#auditSearchBtn').onclick=()=>loadAudit(1).catch(error=>toast(error.message));
qs('#auditSearch').onkeydown=event=>{if(event.key==='Enter'){event.preventDefault();loadAudit(1).catch(error=>toast(error.message));}};
qs('#auditPreviousBtn').onclick=()=>loadAudit(state.auditPage-1).catch(error=>toast(error.message));
qs('#auditNextBtn').onclick=()=>loadAudit(state.auditPage+1).catch(error=>toast(error.message));
qs('#setupWizardForm').onsubmit=async e=>{e.preventDefault(); try{await saveSetup(e.target);}catch(err){toast(err.message)}};
qs('#setupLaterBtn').onclick=()=>qs('#setupWizard').classList.add('hidden');
qs('#brandingForm').onsubmit=async e=>{e.preventDefault(); try{await saveSetup(e.target,false);}catch(err){toast(err.message)}};
qs('#tempPasswordCloseBtn').onclick=()=>qs('#tempPasswordModal').classList.add('hidden');
qs('#importTemplateBtn').onclick=()=>downloadImportTemplate().catch(err=>toast(err.message));
qs('#importPreviewBtn').onclick=()=>previewImport().catch(err=>toast(err.message));
qs('#importCommitBtn').onclick=()=>commitImport().catch(err=>toast(err.message));
qs('#importFile').onchange=()=>{importCsv='';qs('#importCommitBtn').disabled=true;qs('#importPreviewResult').innerHTML='';};
qs('#importType').onchange=()=>{importCsv='';qs('#importCommitBtn').disabled=true;qs('#importPreviewResult').innerHTML='';};
qs('#importMode').onchange=()=>{importCsv='';qs('#importCommitBtn').disabled=true;qs('#importPreviewResult').innerHTML='';};
qs('#backupBtn').onclick=async()=>toast('Backup created: '+(await api('backup')).file);
qs('#retryBtn').onclick=async()=>{await api('retry_sync'); await loadReports(); toast('Sync queue updated')};
qsa('#analyticsPeriodTabs button').forEach(button=>button.onclick=()=>{
  analyticsState.period=button.dataset.period;
  analyticsState.start='';
  analyticsState.end='';
  qsa('#analyticsPeriodTabs button').forEach(tab=>tab.classList.toggle('active', tab===button));
  loadAnalytics().catch(error=>toast(error.message));
});
qs('#analyticsApplyRange').onclick=()=>{
  const start=qs('#analyticsStart').value, end=qs('#analyticsEnd').value;
  if(!start||!end){toast('Choose both a start and end date');return;}
  analyticsState.period='custom';
  analyticsState.start=start;
  analyticsState.end=end;
  qsa('#analyticsPeriodTabs button').forEach(tab=>tab.classList.remove('active'));
  loadAnalytics().catch(error=>toast(error.message));
};
qs('#analyticsRefresh').onclick=()=>loadAnalytics().catch(error=>toast(error.message));
initSidebar();
boot().catch(e=>toast(e.message));
