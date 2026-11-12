<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../lib/security.php';
init_db();
security_send_headers();
$company = company_profile();
$brandColor = preg_match('/^#[0-9a-fA-F]{6}$/', $company['color']) ? $company['color'] : '#a70437';
$defaultBranch = db()->query('SELECT name, location, phone FROM branches ORDER BY id LIMIT 1')->fetch() ?: ['name' => 'Main', 'location' => $company['location'], 'phone' => $company['phone']];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?> POS</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>:root{--brand:<?= htmlspecialchars($brandColor, ENT_QUOTES, 'UTF-8') ?>}</style>
</head>
<body>
<main id="login" class="login-screen">
    <section class="login-panel">
        <img id="loginLogo" src="<?= htmlspecialchars($company['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>" class="login-logo">
        <form id="loginForm" class="stack">
            <label>Username<input name="username" autocomplete="username"></label>
            <label>Password<input name="password" type="password" autocomplete="current-password"></label>
            <button class="primary" type="submit">Sign in</button>
        </form>
    </section>
</main>

<section id="app" class="app hidden">
    <aside class="sidebar">
        <img id="brandLogo" src="<?= htmlspecialchars($company['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>" class="brand-logo">
        <nav id="nav"></nav>
        <button id="logoutBtn" class="ghost">Sign out</button>
    </aside>
    <section class="workspace">
        <div id="licenseBanner" class="license-banner hidden"></div>
        <header class="topbar">
            <div>
                <h1 id="pageTitle">Dashboard</h1>
                <p id="companySubtitle"><?= htmlspecialchars(trim($company['name'] . ' · ' . $company['email'] . ' · ' . $company['location'], ' ·'), ENT_QUOTES, 'UTF-8') ?></p>
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
            <section class="panel held-sales-panel">
                <h2>Recent sales</h2>
                <div id="salesHistoryTable" class="table-wrap"></div>
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
                <input name="vat_rate" type="number" min="0" max="1" step="0.01" value="0.16" placeholder="VAT rate (0.16 = 16%)" required>
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
            <section id="importPanel" class="panel" style="margin-top:16px">
                <div class="section-heading"><h2>CSV import</h2><span class="muted">Preview first; nothing is written until you import</span></div>
                <div class="form-grid">
                    <label>Data type<select id="importType"><option value="products">Products</option><option value="customers">Customers</option><option value="suppliers">Suppliers</option></select></label>
                    <label>Duplicates<select id="importMode"><option value="skip">Skip existing rows</option><option value="update">Update existing rows</option></select></label>
                    <label>CSV file<input id="importFile" type="file" accept=".csv,text/csv"></label>
                </div>
                <div class="button-row">
                    <button id="importTemplateBtn" class="secondary" type="button">Download template</button>
                    <button id="importPreviewBtn" class="secondary" type="button">Preview</button>
                    <button id="importCommitBtn" class="primary" type="button" disabled>Import rows</button>
                </div>
                <div id="importPreviewResult" class="table-wrap"></div>
            </section>
            <div id="adminTables" class="panel table-wrap"></div>
            <section id="systemHealthPanel" class="panel" style="margin-top:16px">
                <div class="section-heading"><h2>System health</h2><button id="healthRefreshBtn" class="secondary" type="button">Refresh</button></div>
                <div id="systemHealth"></div>
            </section>
            <section id="auditPanel" class="panel" style="margin-top:16px">
                <div class="section-heading"><h2>Audit history</h2><div class="toolbar"><input id="auditSearch" placeholder="Search actor, action, details"><button id="auditSearchBtn" class="secondary" type="button">Search</button></div></div>
                <div id="auditTable" class="table-wrap"></div>
                <div class="button-row"><button id="auditPreviousBtn" class="secondary" type="button">Previous</button><span id="auditPageLabel" class="muted"></span><button id="auditNextBtn" class="secondary" type="button">Next</button></div>
            </section>
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
            <form id="roleSettingsForm" class="panel settings-panel admin-only" style="margin-top:16px">
                <h2>Sessions &amp; permissions</h2>
                <label>Session timeout (minutes)<input id="sessionTimeoutMinutes" type="number" min="5" max="1440" required></label>
                <div class="cards-2">
                    <fieldset><legend>Manager</legend>
                        <label class="inline-check"><input id="managerSaleVoid" type="checkbox"> Void completed sales</label>
                        <label class="inline-check"><input id="managerCostView" type="checkbox"> View cost prices</label>
                        <label class="inline-check"><input id="managerRecordsDelete" type="checkbox"> Delete customer/supplier/quotation records</label>
                        <label>Maximum discount (%)<input id="managerDiscountLimit" type="number" min="0" max="100" required></label>
                    </fieldset>
                    <fieldset><legend>Employee</legend>
                        <label class="inline-check"><input id="employeeSaleVoid" type="checkbox"> Void completed sales</label>
                        <label class="inline-check"><input id="employeeCostView" type="checkbox"> View cost prices</label>
                        <label class="inline-check"><input id="employeeRecordsDelete" type="checkbox"> Delete customer/supplier/quotation records</label>
                        <label>Maximum discount (%)<input id="employeeDiscountLimit" type="number" min="0" max="100" required></label>
                    </fieldset>
                </div>
                <button class="primary">Save session and permission settings</button>
            </form>
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
            <form id="brandingForm" class="panel settings-panel admin-only" style="margin-top:16px">
                <h2>Business profile &amp; branding</h2>
                <p class="muted">These details appear in the app header and on printed receipts.</p>
                <div class="form-grid">
                    <label>Company name<input name="company_name" value="<?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <label>Email<input name="company_email" type="email" value="<?= htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Phone<input name="company_phone" value="<?= htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Location<input name="company_location" value="<?= htmlspecialchars($company['location'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Tagline<input name="company_tagline" value="<?= htmlspecialchars($company['tagline'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Brand colour<input name="brand_color" type="color" value="<?= htmlspecialchars($brandColor, ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Logo path<input name="logo_path" value="<?= htmlspecialchars($company['logo'], ENT_QUOTES, 'UTF-8') ?>" placeholder="assets/saji-logo.png"></label>
                    <label>Receipt footer<input name="receipt_footer" value="<?= htmlspecialchars($company['footer'], ENT_QUOTES, 'UTF-8') ?>"></label>
                </div>
                <button class="primary">Save business profile</button>
            </form>
        </section>
    </section>
</section>

<div id="toast"></div>
<div id="paymentLoadingModal" class="hidden payment-loading-backdrop" aria-live="polite">
    <div class="payment-loading-panel">
        <div class="spinner"></div>
        <h3>Processing M-Pesa payment</h3>
        <p id="paymentLoadingText">Waiting for the customer to complete the transaction...</p>
        <button id="cancelPaymentBtn" class="secondary" type="button">Cancel</button>
    </div>
</div>
<div id="setupWizard" class="hidden payment-loading-backdrop" aria-live="polite">
    <form id="setupWizardForm" class="setup-panel stack">
        <h2>Set up your shop</h2>
        <p class="muted">Finish the business profile now, or choose Later and update it from Settings.</p>
        <div class="form-grid">
            <label>Company name<input name="company_name" value="<?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
            <label>Email<input name="company_email" type="email" value="<?= htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Phone<input name="company_phone" value="<?= htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Location<input name="company_location" value="<?= htmlspecialchars($company['location'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Tagline<input name="company_tagline" value="<?= htmlspecialchars($company['tagline'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Brand colour<input name="brand_color" type="color" value="<?= htmlspecialchars($brandColor, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Logo path<input name="logo_path" value="<?= htmlspecialchars($company['logo'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Receipt footer<input name="receipt_footer" value="<?= htmlspecialchars($company['footer'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>First branch<input name="branch_name" value="<?= htmlspecialchars($defaultBranch['name'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Branch location<input name="branch_location" value="<?= htmlspecialchars($defaultBranch['location'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Branch phone<input name="branch_phone" value="<?= htmlspecialchars($defaultBranch['phone'], ENT_QUOTES, 'UTF-8') ?>"></label>
        </div>
        <label>License key (optional)<textarea name="license_key" rows="3" placeholder="Paste a license key now, or activate it later from Settings"></textarea></label>
        <div class="button-row">
            <button id="setupLaterBtn" class="secondary" type="button">Later</button>
            <button class="primary">Save and finish</button>
        </div>
    </form>
</div>
<div id="tempPasswordModal" class="hidden payment-loading-backdrop" aria-live="polite">
    <div class="payment-loading-panel">
        <h3>Temporary password</h3>
        <p id="tempPasswordText"></p>
        <p class="muted">Give this password to the user securely.</p>
        <button id="tempPasswordCloseBtn" class="primary" type="button">Done</button>
    </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
