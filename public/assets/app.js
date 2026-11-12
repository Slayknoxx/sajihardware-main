let csrfToken = '';
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const api = (action, data, method = 'POST') => fetch(`../api.php?action=${action}`, {method, headers:{'Content-Type':'application/json', ...(csrfToken ? {'X-CSRF-Token':csrfToken} : {})}, body:data ? JSON.stringify(data) : undefined}).then(async r=>{const j=await r.json(); if(!r.ok){const error=new Error(j.error||'Request failed'); error.code=j.code; error.license=j.license; if(j.code==='PASSWORD_CHANGE_REQUIRED') showForcedPassword(); throw error;} return j});
const paymentApi = (path, data, method = 'POST') => fetch(`../api/${path}`, {method, credentials:'same-origin', headers:{'Content-Type':'application/json', ...(csrfToken ? {'X-CSRF-Token':csrfToken} : {})}, body:data ? JSON.stringify(data) : undefined}).then(async r=>{const j=await r.json(); if(!r.ok) throw new Error(j.error||'Payment request failed'); return j});
const state = {user:null, products:[], cart:[], page:'dashboard', license:null, company:null, setupCompleted:true};
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
  ['dashboard','Dashboard',['ADMIN','MANAGER','EMPLOYEE']],
  ['sales','Sales',['ADMIN','MANAGER','EMPLOYEE']],
  ['products','Products',['ADMIN','MANAGER','EMPLOYEE']],
  ['people','Customers & Suppliers',['ADMIN','MANAGER','EMPLOYEE']],
  ['quotations','Quotations',['ADMIN','MANAGER','EMPLOYEE']],
  ['analytics','Analytics',['ADMIN','MANAGER']],
  ['cashflow','Cash Flow',['ADMIN','MANAGER']],
  ['stockanalytics','Stock Analytics',['ADMIN','MANAGER']],
  ['admin','Admin',['ADMIN']],
  ['reports','Reports & Backups',['ADMIN','MANAGER']],
  ['settings','Settings',['ADMIN','MANAGER','EMPLOYEE']],
];

async function boot(){
  state.user = null;
  qs('#app').classList.add('hidden');
  qs('#login').classList.remove('hidden');
  startConnectionMonitor();

  try {
    const me = await api('me', null, 'GET');
    csrfToken = me.csrf || csrfToken;
    state.license = me.license || null;
    state.company = me.company || state.company;
    state.setupCompleted = me.setup_completed !== false;
    if (me.user && me.user.id) {
      state.user = me.user;
      showApp();
      if (me.must_change_password) showForcedPassword();
      else maybeShowSetup();
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
function showApp(){
  qs('#login').classList.add('hidden'); qs('#app').classList.remove('hidden');
  qs('#userPill').textContent = `${state.user.full_name || state.user.username} · ${state.user.role}`;
  applyBranding();
  renderNav(); renderLicenseBanner(); openPage('dashboard').catch(err=>toast(err.message));
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
function showForcedPassword(currentPassword){
  const modal = qs('#forcedPasswordModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  const form = qs('#forcedPasswordForm');
  if (form) {
    const current = form.querySelector('[name="current_password"]');
    if (current && currentPassword) current.value = currentPassword;
    const next = form.querySelector('[name="new_password"]');
    if (next) next.focus();
  }
}
function renderNav(){
  qs('#nav').innerHTML = pages.filter(p=>p[2].includes(state.user.role)).map(p=>`<button data-page="${p[0]}">${p[1]}</button>`).join('');
  qsa('#nav button').forEach(b=>b.onclick=()=>openPage(b.dataset.page).catch(err=>toast(err.message)));
}
async function openPage(page){
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
    <section class="panel kpi">Stock value<strong>${money(d.stock_value)}</strong></section>
    <section class="panel kpi">Low stock<strong>${d.low_stock}</strong></section>
  </div><section class="panel" style="margin-top:16px"><h2>Recent sales</h2>${table(d.recent_sales)}</section>`;
}
async function loadSales(){
  state.products = (await api('list&table=products', null, 'GET')).rows;
  const heldSales = (await api('list&table=held_sales', null, 'GET')).rows;
  renderProducts(); renderCart(); renderHeldSales(heldSales);
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

  qs('#cartLines').innerHTML = state.cart.length ? state.cart.map(x=>`<div class="cart-line"><div><b>${esc(x.name)}</b><small>${money(x.price*x.qty)}</small></div><input type="number" min="1" value="${esc(x.qty)}" data-qty="${x.id}"><button data-remove="${x.id}">×</button></div>`).join('') : '<p class="muted">Scan or tap products to add them.</p>';
  qsa('[data-qty]').forEach(i=>i.onchange=()=>{state.cart.find(x=>x.id===+i.dataset.qty).qty=Math.max(1,+i.value);renderCart()});
  qsa('[data-remove]').forEach(b=>b.onclick=()=>{state.cart=state.cart.filter(x=>x.id!==+b.dataset.remove);renderCart()});
  const subtotal=state.cart.reduce((s,x)=>s+x.price*x.qty,0), discount=+qs('#discount').value||0, total=Math.max(0,subtotal-discount), vat=total*0.16/1.16, paid=+qs('#amountPaid').value||total, change = paymentMethod === 'Cash' ? Math.max(0, paid-total) : 0;
  qs('#totals').innerHTML = `<div><span>Subtotal</span><b>${money(subtotal)}</b></div><div><span>VAT inclusive</span><b>${money(vat)}</b></div><div><span>Discount</span><b>${money(discount)}</b></div><div class="grand"><span>Total</span><b>${money(total)}</b></div><div><span>Amount paid</span><b>${money(paid)}</b></div><div><span>Change</span><b>${money(change)}</b></div>`;
}
function resetCheckoutForm(){
  state.cart = [];
  qs('#customerName').value = '';
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
  let mpesaPhone = '';
  if (paymentMethod === 'M-Pesa') {
    mpesaPhone = normalizeMpesaPhone(qs('#mpesaPhone').value);
    if (!/^254\d{9,10}$/.test(mpesaPhone)) {
      throw new Error('Enter a valid 254-format mobile number.');
    }
    qs('#mpesaPhone').value = mpesaPhone.replace(/^254/, '');
  }
  const payload={cart:state.cart, customer_name:qs('#customerName').value, payment_method:paymentMethod, discount:+qs('#discount').value||0, amount_paid:+qs('#amountPaid').value||0, mpesa_phone: paymentMethod === 'M-Pesa' ? mpesaPhone : ''};
  const r=await api('checkout', payload);
  if (r.payment_status === 'PENDING_PAYMENT') {
    const stk = await paymentApi('stk_push.php', {sale_id:r.sale_id, reference:r.payment_reference, amount:r.amount, phone:r.phone});
    showPaymentLoading('Sending STK push to the customer phone...');
    toast(`STK Push sent. Waiting for payment: ${r.receipt_no}`);
    const paid = await waitForMpesaPayment(r.sale_id, r.payment_reference, stk.poll_seconds || r.poll_seconds || 90);
    hidePaymentLoading();
    if (!paid) {
      state.cart = [];
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
      return false;
    }
    showPaymentLoading(`Waiting for customer confirmation... ${Math.max(0, Math.ceil(timeoutSeconds - ((Date.now() - started) / 1000)))}s left`);
  }
  hidePaymentLoading();
  toast('M-Pesa confirmation timed out. You can retry the payment from this sale.');
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
      <p>Subtotal: ${money(s.subtotal)}<br>Discount: ${money(s.discount)}<br>VAT (16%): ${money(s.tax)}<br>Total: <b>${money(s.total)}</b></p>
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
  qs('#productsTable').innerHTML=table(rows);
  const suppliers=(await api('list&table=suppliers', null, 'GET')).rows;
  qs('#productSupplier').innerHTML='<option value="">Supplier (optional)</option>'+suppliers.map(s=>`<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('');
}
async function loadPeople(){
  const c=(await api('list&table=customers', null, 'GET')).rows, s=(await api('list&table=suppliers', null, 'GET')).rows;
  qs('#peopleTables').innerHTML='<h2>Customers</h2>'+peopleTable(c,'customers')+'<h2>Suppliers</h2>'+peopleTable(s,'suppliers');
  qsa('[data-delete-person]').forEach(button => button.onclick = () => deletePerson(button.dataset.table, +button.dataset.deletePerson));
}
function peopleTable(rows, tableName){
  if (!rows.length) return '<p class="muted">No records yet.</p>';
  return `<table><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>KRA PIN</th><th>Action</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.name||'')}</td><td>${esc(row.phone||'')}</td><td>${esc(row.email||'')}</td><td>${esc(row.kra_pin||'')}</td><td><button class="danger" type="button" data-delete-person="${row.id}" data-table="${esc(tableName)}">Delete</button></td></tr>`).join('')}</tbody></table>`;
}
async function deletePerson(table, id){
  if (!window.confirm('Delete this record permanently?')) return;
  try { await api('delete_person',{table,id}); await loadPeople(); toast('Record deleted'); } catch (error) { toast(error.message); }
}
async function loadQuotations(){
  const rows=(await api('list&table=quotations', null, 'GET')).rows;
  qs('#quotationsTable').innerHTML=renderQuotationHistory(rows);
  renderQuoteCart();
}
function renderQuotationHistory(rows){
  if(!rows.length) return '<p class="muted">No quotations yet.</p>';
  return `<table><thead><tr><th>Quotation</th><th>Customer</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>${rows.map(row=>{
    const accepted= row.status === 'ACCEPTED';
    const converted= row.status === 'CONVERTED';
    return `<tr><td>${esc(row.quote_no)}</td><td>${esc(row.customer_name||'')}</td><td>${money(row.total)}</td><td>${esc(row.status)}</td><td>${esc(receiptDate(row.created_at))}</td><td><button class="secondary" type="button" data-view-quotation="${row.id}">Receipt</button> <button class="secondary" type="button" data-pdf-quotation="${row.id}">PDF</button> ${!converted && !accepted ? `<button class="secondary" type="button" data-accept-quotation="${row.id}">Accept</button>` : ''} ${accepted ? `<button class="primary" type="button" data-convert-quotation="${row.id}">Add to sales</button>` : ''} ${converted ? `<button class="secondary" type="button" data-view-sale="${row.converted_sale_id}">Sale receipt</button>` : ''} ${!converted ? `<button class="danger" type="button" data-delete-quotation="${row.id}">Delete</button>` : ''}</td></tr>`;
  }).join('')}</tbody></table>`;
}
async function quotationDetail(id){ return api(`quotation_detail&id=${id}`, null, 'GET'); }
async function printQuotation(id){
  const r=await quotationDetail(id), q=r.quotation;
  const company = r.company || state.company || {};
  const items=(r.items||[]).map(item=>`<tr><td>${esc(item.product_name)}</td><td>${esc(item.quantity)}</td><td>${money(item.unit_price)}</td><td>${money(item.total)}</td></tr>`).join('');
  const div=document.createElement('section'); div.className='receipt-print';
  div.innerHTML=`<div class="receipt-shell quotation-receipt"><img class="receipt-logo" src="${esc(company.logo)}"><h2>${esc(company.name)}</h2><p>${esc(company.location)}<br>${esc(company.email)}${company.phone ? `<br>${esc(company.phone)}` : ''}</p><h3>QUOTATION RECEIPT</h3><p>Quotation No: <b>${esc(q.quote_no)}</b><br>${esc(receiptDate(q.created_at))}<br>Valid until: ${esc(q.valid_until||'Not specified')}</p><p>Customer: <b>${esc(q.customer_name||'Walk-in Customer')}</b></p><table><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amt</th></tr></thead><tbody>${items}</tbody></table><div class="receipt-divider"></div><p>Subtotal: ${money(q.subtotal)}<br>Discount: ${money(q.discount)}<br>VAT: ${money(q.tax)}<br>Total: <b>${money(q.total)}</b></p><div class="receipt-divider"></div><p>This is a quotation, not an official tax receipt.</p></div>`;
  const previousTitle=document.title;
  document.title=`Quotation Receipt ${q.quote_no}`;
  document.body.appendChild(div); window.print(); div.remove(); document.title=previousTitle;
}
async function deleteQuotation(id){ if(!window.confirm('Delete this quotation permanently?')) return; try{await api('delete_quotation',{id});await loadQuotations();toast('Quotation deleted')}catch(error){toast(error.message)} }
async function acceptQuotation(id){ try{await api('accept_quotation',{id});await loadQuotations();toast('Quotation marked as accepted')}catch(error){toast(error.message)} }
async function convertQuotation(id){
  const paymentMethod=window.prompt('Payment method (Cash, M-Pesa, Card, Credit, etc.)','Cash');
  if(!paymentMethod) return;
  const amountPaid=window.prompt('Amount paid', '0');
  if(amountPaid===null) return;
  try{const r=await api('convert_quotation',{id,payment_method:paymentMethod,amount_paid:+amountPaid||0});await loadQuotations();toast(`Sale created: ${r.receipt_no}`);await printReceipt(r.sale_id)}catch(error){toast(error.message)}
}
async function viewSaleReceipt(id){ await printReceipt(id); }
async function loadCashFlow(){
  const period=qs('#cashFlowPeriod').value, date=qs('#cashFlowDate').value || new Date().toISOString().slice(0,10);
  const result=await api('cash_flow',{period,date});
  const cash=result.summary, profitable=Number(cash.net_cash)>=0;
  qs('#cashFlowCardsPage').innerHTML=`<section class="kpi">Cash sales<strong>${money(cash.cash_sales)}</strong></section><section class="kpi">Manual income<strong>${money(cash.other_income)}</strong></section><section class="kpi">Total expenses<strong>${money(cash.expenses)}</strong></section><section class="kpi">Net cash<strong>${money(cash.net_cash)}</strong><span>${profitable?'Profit':'Loss'}</span></section>`;
  const max=Math.max(1,...result.series.map(row=>Math.max(Number(row.income),Number(row.expenses))));
  qs('#cashFlowChart').innerHTML=result.series.length ? result.series.map(row=>{const income=Number(row.income), expenses=Number(row.expenses), net=income-expenses;return `<div class="cash-flow-bar ${net<0?'loss':''}" title="Income ${money(income)} | Expenses ${money(expenses)} | ${net<0?'Loss':'Profit'} ${money(Math.abs(net))}"><b>${net<0?'Loss':'Profit'} ${money(Math.abs(net))}</b><span style="height:${Math.max(4,Math.round(Math.max(income,expenses)/max*150))}px"></span><small>${esc(row.bucket)}</small></div>`}).join('') : '<p class="muted">No cash-flow activity for this period.</p>';
  qs('#cashFlowDate').value=date;
}
async function loadStockAnalytics(){
  const rows=(await api('stock_analytics', null, 'GET')).rows;
  qs('#stockAnalyticsPage').innerHTML=renderStockAnalytics(rows);
}
function renderStockAnalytics(rows){
  if(!rows.length) return '<p class="muted">No supplier-linked products yet.</p>';
  return `<table><thead><tr><th>Supplier name</th><th>Product</th><th>Total units supplied</th><th>Units available in stock</th><th>Units sold</th><th>Remaining units</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.supplier_name||'Unassigned')}</td><td>${esc(row.product||'')}</td><td>${esc(row.total_units_supplied||0)}</td><td>${esc(row.units_available_in_stock||0)}</td><td>${esc(row.units_sold||0)}</td><td>${esc(row.remaining_units||0)}</td></tr>`).join('')}</tbody></table>`;
}
async function loadAnalytics(){
  const d=await api('dashboard', null, 'GET'), etims=await api('etims_summary', null, 'GET');
  const pending=etims.rows.find(row=>row.etims_status==='PENDING')?.count||0;
  qs('#analyticsCards').innerHTML=`<section class="kpi">Today<strong>${money(d.today.total)}</strong><span>${d.today.count} transactions</span></section><section class="kpi">This month<strong>${money(d.month_total)}</strong></section><section class="kpi">Stock value<strong>${money(d.stock_value)}</strong></section><section class="kpi">eTIMS pending<strong>${pending}</strong><span>VAT ${Number(etims.vat_rate*100).toFixed(0)}%</span></section>`;
}
function renderQuoteCart(){
  const subtotal=state.cart.reduce((sum,item)=>sum+item.price*item.qty,0), discount=+qs('#quoteDiscount').value||0, total=Math.max(0,subtotal-discount), tax=total*0.16/1.16;
  qs('#quoteCart').innerHTML=state.cart.length ? state.cart.map(item=>`<div class="cart-line"><div><b>${esc(item.name)}</b><small>${money(item.price*item.qty)}</small></div><span>${esc(item.qty)}</span><button type="button" data-quote-remove="${item.id}">x</button></div>`).join('') : '<p class="muted">Add products from the Sales screen before saving a quotation.</p>';
  qsa('[data-quote-remove]').forEach(button=>button.onclick=()=>{state.cart=state.cart.filter(item=>item.id!==+button.dataset.quoteRemove);renderQuoteCart()});
  qs('#quoteTotals').innerHTML=`<div><span>Subtotal</span><b>${money(subtotal)}</b></div><div><span>VAT</span><b>${money(tax)}</b></div><div class="grand"><span>Total</span><b>${money(total)}</b></div>`;
}
async function createQuotation(){
  const r=await api('create_quotation',{customer_name:qs('#quoteCustomer').value,valid_until:qs('#quoteValidUntil').value,discount:+qs('#quoteDiscount').value||0,cart:state.cart});
  state.cart=[]; renderQuoteCart(); await loadQuotations(); toast(`Quotation saved: ${r.quote_no}`);
}
async function loadAdmin(){
  const parts=[];
  const users = (await api('list&table=users', null, 'GET')).rows;
  parts.push('<h2>Employee accounts</h2>' + renderUserAccounts(users));
  for (const t of ['employees','branches','expenses']) parts.push(`<h2>${t}</h2>`+table((await api(`list&table=${t}`, null, 'GET')).rows));
  qs('#adminTables').innerHTML=parts.join('');
  qsa('[data-toggle-user]').forEach(button => button.onclick = () => setUserActive(+button.dataset.toggleUser, button.dataset.active !== '1'));
  qsa('[data-delete-user]').forEach(button => button.onclick = () => deleteUser(+button.dataset.deleteUser));
  qsa('[data-reset-user]').forEach(button => button.onclick = () => resetUserPassword(+button.dataset.resetUser));
}
function renderUserAccounts(rows){
  if (!rows.length) return '<p class="muted">No employee accounts yet.</p>';
  return `<div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Branch</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.map(row => {
    const active = +row.active === 1;
   const isMainAdmin = row.id === 1;
   const isSelf = row.id === state.user?.id;
   const protectedLabel = isMainAdmin ? '<span class="muted">Protected</span> ' : '';
   const resetButton = isSelf ? '' : `<button class="secondary" type="button" data-reset-user="${row.id}">Reset password</button> `;
   const accountButtons = isMainAdmin ? '' : `<button class="secondary" type="button" data-toggle-user="${row.id}" data-active="${active ? 1 : 0}">${active ? 'Deactivate' : 'Activate'}</button> <button class="danger" type="button" data-delete-user="${row.id}">Delete</button>`;
   return `<tr><td>${esc(row.full_name || '')}</td><td>${esc(row.username || '')}</td><td>${esc(row.role || '')}</td><td>${esc(row.branch || '')}</td><td>${active ? 'Active' : 'Deactivated'}</td><td>${protectedLabel}${resetButton}${accountButtons}</td></tr>`;
  }).join('')}</tbody></table></div>`;
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
  if (!window.confirm('Generate a new temporary password? The user will be signed out and must change it at next login.')) return;
  try {
    const r = await api('reset_user_password', {id});
    const target = qs('#tempPasswordText');
    if (target) target.textContent = `${r.full_name || r.username} (${r.username}): ${r.temporary_password}`;
    qs('#tempPasswordModal')?.classList.remove('hidden');
    await loadAdmin();
    toast('Password reset');
  } catch (error) {
    toast(error.message);
  }
}
async function loadReports(){
  const salesRows = (await api('list&table=sales', null, 'GET')).rows;
  qs('#salesTable').innerHTML = `<table><thead><tr><th>Receipt</th><th>Customer</th><th>Method</th><th>Cashier</th><th>Total</th><th>Time</th><th>Action</th></tr></thead><tbody>${salesRows.map(r=>`<tr><td>${esc(r.receipt_no)}</td><td>${esc(r.customer_name || 'Walk-in Customer')}</td><td>${esc(r.payment_method)}</td><td>${esc(r.cashier_name || 'Unknown')}</td><td>${money(r.total)}</td><td>${esc(receiptDate(r.created_at))}</td><td><button class="secondary" data-print-receipt="${r.id}">Print</button></td></tr>`).join('')}</tbody></table>`;
  qsa('[data-print-receipt]').forEach(btn => btn.onclick = async () => await printReceipt(+btn.dataset.printReceipt));
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
  try {
    await api('backup_restore', {file, confirm: confirmText});
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
  const summary = `<p><b>${result.total}</b> data row${result.total === 1 ? '' : 's'}: ${result.insert} new, ${result.update} update${result.update === 1 ? '' : 's'}, ${result.skip} skipped, <b>${result.errors.length}</b> error${result.errors.length === 1 ? '' : 's'}.</p>`;
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
    state.license = result.license || state.license;
    state.company = result.company || state.company;
    state.setupCompleted = result.setup_completed !== false;
    showApp();
    if (result.must_change_password) showForcedPassword(credentials.password);
    else maybeShowSetup();
  }catch(err){toast(err.message)}
};
qs('#forcedPasswordForm').onsubmit=async e=>{
  e.preventDefault();
  try{
    await api('change_password', formData(e.target));
    qs('#forcedPasswordModal').classList.add('hidden');
    e.target.reset();
    toast('Password updated');
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
qs('#autoBackupToggle').onchange=async e=>{
  try{
    await api('save_settings', {backup_auto_enabled: e.target.checked ? 1 : 0});
    toast(e.target.checked ? 'Automatic backups enabled' : 'Automatic backups disabled');
  }catch(error){ e.target.checked = !e.target.checked; toast(error.message); }
};
qs('#logoutBtn').onclick=async()=>{await api('logout'); location.reload()};
qs('#cancelPaymentBtn').onclick=()=>{hidePaymentLoading(); toast('M-Pesa payment canceled. You can retry from the same sale.');};
qs('#paymentMethod').onchange = renderCart;
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
qs('#quoteDiscount').oninput=renderQuoteCart;
qs('#createQuoteBtn').onclick=()=>createQuotation().catch(error=>toast(error.message));
qs('#cashFlowPeriod').onchange=()=>loadCashFlow().catch(error=>toast(error.message));
qs('#cashFlowDate').onchange=()=>loadCashFlow().catch(error=>toast(error.message));
qs('#cashFlowRefresh').onclick=()=>loadCashFlow().catch(error=>toast(error.message));
document.addEventListener('click', event=>{
  const button=event.target.closest('[data-view-quotation],[data-pdf-quotation],[data-accept-quotation],[data-convert-quotation],[data-delete-quotation],[data-view-sale]');
  if(!button) return;
  if(button.dataset.viewQuotation) printQuotation(+button.dataset.viewQuotation).catch(error=>toast(error.message));
  if(button.dataset.pdfQuotation) printQuotation(+button.dataset.pdfQuotation).catch(error=>toast(error.message));
  if(button.dataset.acceptQuotation) acceptQuotation(+button.dataset.acceptQuotation);
  if(button.dataset.convertQuotation) convertQuotation(+button.dataset.convertQuotation);
  if(button.dataset.deleteQuotation) deleteQuotation(+button.dataset.deleteQuotation);
  if(button.dataset.viewSale) viewSaleReceipt(+button.dataset.viewSale).catch(error=>toast(error.message));
});
qsa('.simpleForm').forEach(f=>f.onsubmit=async e=>{e.preventDefault(); try{await api(`save_simple&table=${f.dataset.table}`, formData(f)); f.reset(); await openPage(state.page); toast('Saved')}catch(err){toast(err.message)}});
qs('#userForm').onsubmit=async e=>{e.preventDefault(); try{await api('create_user', formData(e.target)); e.target.reset(); await loadAdmin(); toast('User created')}catch(err){toast(err.message)}};
qs('#resetBusinessForm').onsubmit=async e=>{e.preventDefault(); if(!window.confirm('This will permanently delete all business data. Continue?')) return; try{await api('reset_business_data',formData(e.target));e.target.reset();await openPage('dashboard');toast('Business data reset. User accounts were preserved.')}catch(err){toast(err.message)}};
qs('#passwordForm').onsubmit=async e=>{e.preventDefault(); try{await api('change_password', formData(e.target)); e.target.reset(); toast('Password changed')}catch(err){toast(err.message)}};
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
boot().catch(e=>toast(e.message));
