<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
init_db();
security_send_headers();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SAJI Hardware POS</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main id="login" class="login-screen">
    <section class="login-panel">
        <img src="assets/saji-logo.png" alt="SAJI Hardware LTD" class="login-logo">
        <form id="loginForm" class="stack">
            <label>Username<input name="username" autocomplete="username"></label>
            <label>Password<input name="password" type="password" autocomplete="current-password"></label>
            <button class="primary" type="submit">Sign in</button>
        </form>
    </section>
</main>

<section id="app" class="app hidden">
    <aside class="sidebar">
        <img src="assets/saji-logo.png" alt="SAJI Hardware LTD" class="brand-logo">
        <nav id="nav"></nav>
        <button id="logoutBtn" class="ghost">Sign out</button>
    </aside>
    <section class="workspace">
        <div id="licenseBanner" class="license-banner hidden"></div>
        <header class="topbar">
            <div>
                <h1 id="pageTitle">Dashboard</h1>
                <p>SAJI HARDWARE LTD · sajihardwareltd@gmail.com · Kanduyi - Webuye Road</p>
            </div>
            <div class="topbar-actions">
                <div class="connection-pill offline" id="connectionPill">Offline</div>
                <div class="user-pill" id="userPill"></div>
            </div>
        </header>

        <section id="dashboard" class="view"></section>

        <section id="sales" class="view hidden">
            <div class="sales-grid">
                <section class="panel">
                    <div class="toolbar">
                        <input id="productSearch" class="search" placeholder="Scan barcode or search SKU/name">
                        <button id="clearCart" class="secondary">Clear</button>
                    </div>
                    <div id="productTiles" class="product-grid"></div>
                </section>
                <section class="panel checkout">
                    <h2>Cart</h2>
                    <div id="cartLines" class="cart-lines"></div>
                    <div class="checkout-form">
                        <input id="customerName" placeholder="Customer name">
                        <select id="paymentMethod">
                            <option value="" selected>Select payment method</option>
                            <option>Cash</option>
                            <option>M-Pesa</option>
                        </select>
                        <div class="mpesa-input-wrap hidden" id="mpesaPhoneWrap" style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                            <span style="font-weight:700; color:#0f172a;">254</span>
                            <input id="mpesaPhone" style="flex:1;" placeholder="712345678" inputmode="numeric" maxlength="9">
                        </div>
                        <input id="discount" type="number" min="0" step="0.01" placeholder="Discount">
                        <input id="amountPaid" type="number" min="0" step="0.01" placeholder="Amount paid">
                    </div>
                    <div class="totals" id="totals"></div>
                    <div class="button-row">
                        <button id="holdSale" class="secondary" type="button">Hold sale</button>
                        <button id="proforma" class="secondary" type="button">Proforma</button>
                        <button id="checkoutBtn" class="primary complete-sale" type="button">Complete sale</button>
                    </div>
                </section>
            </div>
            <section class="panel held-sales-panel">
                <div class="section-heading"><h2>Held sales</h2><span class="muted">Continue a sale saved for later</span></div>
                <div id="heldSalesList"></div>
            </section>
        </section>

        <section id="products" class="view hidden">
            <form id="productForm" class="panel form-grid">
                <input name="sku" placeholder="SKU" required>
                <input name="barcode" placeholder="Barcode">
                <input name="name" placeholder="Product name" required>
                <input name="category" placeholder="Category" required>
                <input name="brand" placeholder="Brand">
                <input name="image_path" placeholder="Product image path">
                <input name="barcode_image_path" placeholder="Barcode image path">
                <input name="warehouse" placeholder="Warehouse" value="Main">
                <input name="branch" placeholder="Branch" value="Main">
                <input name="buying_price" type="number" min="0" step="0.01" placeholder="Buying price" required>
                <input name="selling_price" type="number" min="0" step="0.01" placeholder="Selling price" required>
                <input name="quantity" type="number" min="0" step="1" placeholder="Stock quantity" required>
                <input name="reorder_level" type="number" min="0" step="1" placeholder="Reorder level" value="5">
                <div class="supplier-picker"><select name="supplier_id" id="productSupplier"><option value="">Supplier (optional)</option></select><button id="newSupplierBtn" class="secondary" type="button">New supplier</button></div>
                <div id="newSupplierFields" class="supplier-fields hidden"><input id="newSupplierName" placeholder="New supplier name"><input id="newSupplierPhone" placeholder="Supplier phone"><input id="newSupplierEmail" type="email" placeholder="Supplier email"><input id="newSupplierKraPin" placeholder="Supplier KRA PIN"></div>
                <button class="primary">Add product</button>
            </form>
            <div id="productsTable" class="panel table-wrap"></div>
        </section>

        <section id="people" class="view hidden">
            <div class="cards-2">
                <form class="panel simpleForm" data-table="customers"><h2>Customers</h2><input name="name" placeholder="Name"><input name="phone" placeholder="Phone"><input name="email" placeholder="Email"><input name="kra_pin" placeholder="KRA PIN"><button class="primary">Save customer</button></form>
                <form class="panel simpleForm" data-table="suppliers"><h2>Suppliers</h2><input name="name" placeholder="Name"><input name="phone" placeholder="Phone"><input name="email" placeholder="Email"><input name="kra_pin" placeholder="KRA PIN"><button class="primary">Save supplier</button></form>
            </div>
            <div id="peopleTables" class="panel table-wrap"></div>
        </section>

        <section id="quotations" class="view hidden">
            <section class="panel">
                <h2>New quotation</h2>
                <div class="form-grid">
                    <input id="quoteCustomer" placeholder="Customer name" value="Walk-in Customer">
                    <input id="quoteValidUntil" type="date" placeholder="Valid until">
                    <input id="quoteDiscount" type="number" min="0" step="0.01" placeholder="Discount">
                </div>
                <div id="quoteCart" class="cart-lines"></div>
                <div id="quoteTotals" class="totals"></div>
                <button id="createQuoteBtn" class="primary" type="button">Save quotation</button>
            </section>
            <section class="panel" style="margin-top:16px"><h2>Quotation history</h2><div id="quotationsTable" class="table-wrap"></div></section>
        </section>

        <section id="analytics" class="view hidden">
            <section class="panel"><h2>Business analytics</h2><div id="analyticsCards" class="kpis"></div></section>
        </section>

        <section id="cashflow" class="view hidden">
            <section class="panel">
                <div class="section-heading"><h2>Cash flow</h2><span class="muted">Cash sales are fetched from completed sales</span></div>
                <div class="toolbar"><select id="cashFlowPeriod"><option value="day">Daily</option><option value="month" selected>Monthly</option><option value="year">Yearly</option></select><input id="cashFlowDate" type="date"><button id="cashFlowRefresh" class="secondary" type="button">Refresh</button></div>
                <div id="cashFlowCardsPage" class="kpis"></div>
                <div id="cashFlowChart" class="cash-flow-chart"></div>
            </section>
            <form class="panel simpleForm" data-table="cash_transactions" style="margin-top:16px"><h2>Manual cash movement</h2><div class="form-grid"><select name="type"><option>INCOME</option><option>EXPENSE</option></select><input name="category" placeholder="Category" required><input name="description" placeholder="Description"><input name="amount" type="number" min="0" step="0.01" placeholder="Amount" required><input name="reference" placeholder="Reference"></div><button class="primary">Add movement</button></form>
        </section>

        <section id="stockanalytics" class="view hidden">
            <section class="panel"><h2>Stock analytics</h2><div id="stockAnalyticsPage" class="table-wrap"></div></section>
        </section>

        <section id="admin" class="view hidden">
            <div class="cards-2">
                <form id="userForm" class="panel"><h2>Create account</h2><input name="full_name" placeholder="Full name"><input name="username" placeholder="Username"><input name="password" placeholder="Temporary password"><select name="role"><option>EMPLOYEE</option><option>MANAGER</option><option>ADMIN</option></select><input name="branch" value="Main"><button class="primary">Create user</button></form>
                <form class="panel simpleForm" data-table="employees"><h2>Employees</h2><input name="name" placeholder="Name"><input name="phone" placeholder="Phone"><input name="role" placeholder="Role"><input name="salary" type="number" placeholder="Salary"><button class="primary">Save employee</button></form>
                <form class="panel simpleForm" data-table="branches"><h2>Branches</h2><input name="name" placeholder="Branch name"><input name="location" placeholder="Location"><input name="phone" placeholder="Phone"><button class="primary">Save branch</button></form>
                <form class="panel simpleForm" data-table="expenses"><h2>Expenses</h2><input name="title" placeholder="Title"><input name="category" placeholder="Category"><input name="amount" type="number" placeholder="Amount"><input name="paid_by" placeholder="Paid by"><button class="primary">Record expense</button></form>
                <form id="resetBusinessForm" class="panel"><h2>Start afresh</h2><p class="muted">Deletes sales, stock, customers, suppliers, quotations, expenses, cash movements, employees, branches, and operational history. User accounts are preserved.</p><input name="confirmation" placeholder="Type RESET BUSINESS DATA"><button class="danger" type="submit">Delete all business data</button></form>
            </div>
            <div id="adminTables" class="panel table-wrap"></div>
        </section>

        <section id="reports" class="view hidden">
            <div class="cards-2">
                <section class="panel"><h2>Sales history</h2><div id="salesTable" class="table-wrap"></div></section>
                <section class="panel"><h2>Reliability</h2><button id="backupBtn" class="primary">Create backup</button><button id="retryBtn" class="secondary">Retry sync queue</button><div id="syncTable"></div></section>
            </div>
            <section class="panel" style="margin-top:16px">
                <div class="section-heading"><h2>Backups</h2><span class="muted">Automatic daily backup, manual backup, restore and download</span></div>
                <div class="toolbar">
                    <label class="inline-check"><input id="autoBackupToggle" type="checkbox"> Automatic daily backup</label>
                    <button id="backupRefreshBtn" class="secondary" type="button">Refresh</button>
                </div>
                <div id="backupsTable" class="table-wrap"></div>
            </section>
        </section>

        <section id="settings" class="view hidden">
            <form id="passwordForm" class="panel settings-panel"><h2>Change password</h2><input name="current_password" type="password" placeholder="Current password"><input name="new_password" type="password" placeholder="New password (min 10 characters)" minlength="10"><button class="primary">Update password</button></form>
            <section id="licensePanel" class="panel settings-panel" style="margin-top:16px">
                <h2>License</h2>
                <div id="licenseStatus" class="license-status">Loading…</div>
                <p class="muted">Paste the license key supplied by your vendor. The key is verified offline against the app's public key.</p>
                <textarea id="licenseKeyInput" rows="4" placeholder="Paste license key here"></textarea>
                <div class="button-row">
                    <button id="activateLicenseBtn" class="primary" type="button">Activate license</button>
                    <button id="deactivateLicenseBtn" class="secondary" type="button">Remove license</button>
                </div>
            </section>
        </section>
    </section>
</section>

<div id="toast"></div>
<div id="forcedPasswordModal" class="hidden payment-loading-backdrop" aria-live="polite">
    <form id="forcedPasswordForm" class="payment-loading-panel stack">
        <h3>Set a new password</h3>
        <p class="muted">You must change the default or temporary password before continuing.</p>
        <input name="current_password" type="password" placeholder="Current password" autocomplete="current-password" required>
        <input name="new_password" type="password" placeholder="New password (min 10 characters)" autocomplete="new-password" minlength="10" required>
        <button class="primary">Update password</button>
    </form>
</div>
<div id="paymentLoadingModal" class="hidden payment-loading-backdrop" aria-live="polite">
    <div class="payment-loading-panel">
        <div class="spinner"></div>
        <h3>Processing M-Pesa payment</h3>
        <p id="paymentLoadingText">Waiting for the customer to complete the transaction...</p>
        <button id="cancelPaymentBtn" class="secondary" type="button">Cancel</button>
    </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
