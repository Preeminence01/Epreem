<?php
/**
 * EPREEM Admin Dashboard — dashboard.php
 *
 * This page is served by the PHP host, not the Laravel API (the API stays a
 * separate service reachable at js/config.js's API_BASE_URL). All dashboard
 * data still loads client-side from that API via js/api.js — every admin
 * endpoint it calls is already gated server-side by Laravel's `role:admin`
 * middleware, so the real authorization boundary lives there.
 *
 * What this PHP wrapper adds on top:
 *   1. Sends explicit, no-cache headers appropriate for an authenticated
 *      admin page (dashboards should never be served from a shared/browser
 *      cache).
 *   2. Gives you one place to add a *server-side* session gate later, if you
 *      move admin auth to PHP sessions/cookies instead of (or alongside) the
 *      bearer-token flow the JS client currently uses. That hook is stubbed
 *      in below, disabled by default so it doesn't fight the existing
 *      localStorage-token auth until you're ready to wire it up.
 */

session_start();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!in_array($_SESSION['epreem_role'] ?? null, ['admin', 'super_admin'], true)) {
    header('Location: admin-login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<link rel="manifest" href="manifest.json" />
<meta name="theme-color" content="#0E0D0B" />
<link rel="apple-touch-icon" href="icons/icon-180.png" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>Admin Dashboard — EPREEM</title>
<link rel="stylesheet" href="css/style.css" />
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="dash-shell">
    <aside class="dash-side">
      <div class="logo">
        <img class="dashboard-logo-image" src="logo.jpg" alt="EPREEM" />
        <span class="tag">Admin Console</span>
      </div>
      <nav class="dash-nav">
        <a href="#" class="active" data-pane="overview">📊 Platform Overview</a>
        <a href="#" data-pane="users">👤 Users</a>
        <a href="#" data-pane="sellers">🏷️ Seller Verification</a>
        <a href="#" data-pane="businesses">🏢 Businesses</a>
        <a href="#" data-pane="listings">📋 Listings</a>
        <a href="#" data-pane="disputes">⚖️ Disputes</a>
        <a href="#" data-pane="commissions">% Commissions</a>
        <a href="admin-crud.php">CRUD Manager</a>
        <div class="grp-label">Account</div>
        <a href="admin-logout.php">Sign Out</a>
        <a href="index.html">Exit to Marketplace</a>
      </nav>
    </aside>

    <div class="dash-main">

      <div id="pane-overview" class="dash-pane">
        <div class="dash-head"><div><span class="eyebrow">Platform health</span><h1>Admin Overview</h1></div><button class="btn btn-line">Send Announcement</button></div>
        <div class="stat-grid">
          <div class="stat-card"><div class="lbl">Total Users</div><div class="val">48,210</div><div class="delta">↑ 3.1% this week</div></div>
          <div class="stat-card"><div class="lbl">Active Listings</div><div class="val">12,438</div><div class="delta">↑ 220 new today</div></div>
          <div class="stat-card"><div class="lbl">GMV This Month</div><div class="val">$6.2M</div><div class="delta">↑ 18% vs last month</div></div>
          <div class="stat-card"><div class="lbl">Open Disputes</div><div class="val">7</div><div class="delta down">↑ 2 since yesterday</div></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Pending Seller Verifications</h3><a href="#" class="view-all" data-pane-trigger="sellers">View all →</a></div>
          <table class="dtable">
            <thead><tr><th>Applicant</th><th>Type</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr><td>Forgewright Equipment</td><td>Business</td><td>2 days ago</td><td><span class="status-pill pending">Pending</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Review →</a></td></tr>
              <tr><td>Th\u00e9or\u00e8me Gallery</td><td>Individual</td><td>1 day ago</td><td><span class="status-pill pending">Pending</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Review →</a></td></tr>
              <tr><td>Domus Living</td><td>Business</td><td>4 hours ago</td><td><span class="status-pill pending">Pending</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Review →</a></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="pane-users" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Directory</span><h1>Users</h1></div></div>
        <div class="panel">
          <table class="dtable">
            <thead><tr><th>Name</th><th>Role</th><th>Joined</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr><td>Ama Owusu</td><td>Buyer</td><td>Jan 2024</td><td><span class="status-pill ok">Active</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Manage →</a></td></tr>
              <tr><td>Kojo Mensah</td><td>Seller</td><td>Mar 2023</td><td><span class="status-pill ok">Active</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Manage →</a></td></tr>
              <tr><td>Linda Tetteh</td><td>Buyer</td><td>Nov 2024</td><td><span class="status-pill ok">Active</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Manage →</a></td></tr>
              <tr><td>James Owusu</td><td>Seller</td><td>Aug 2022</td><td><span class="status-pill bad">Suspended</span></td><td><a href="#" style="color:var(--gold-bright); font-size:12px;">Manage →</a></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="pane-sellers" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Identity & trust</span><h1>Seller Verification</h1></div></div>
        <div class="panel">
          <table class="dtable">
            <thead><tr><th>Seller</th><th>Document</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr><td>Forgewright Equipment</td><td>National ID + Business Cert.</td><td>2 days ago</td><td><span class="status-pill pending">Pending</span></td><td><button class="btn btn-sm btn-gold" onclick="toast('Seller approved')">Approve</button></td></tr>
              <tr><td>Th\u00e9or\u00e8me Gallery</td><td>National ID</td><td>1 day ago</td><td><span class="status-pill pending">Pending</span></td><td><button class="btn btn-sm btn-gold" onclick="toast('Seller approved')">Approve</button></td></tr>
              <tr><td>Aurelia Fine Jewelry</td><td>Business Cert.</td><td>3 weeks ago</td><td><span class="status-pill ok">Verified</span></td><td>—</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="pane-businesses" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Companies</span><h1>Business Accounts</h1></div></div>
        <div class="panel">
          <table class="dtable">
            <thead><tr><th>Company</th><th>Category</th><th>Staff</th><th>Status</th></tr></thead>
            <tbody>
              <tr><td>Crestline Estates</td><td>Real Estate</td><td>6</td><td><span class="status-pill ok">Approved</span></td></tr>
              <tr><td>Meridian Auto Group</td><td>Automotive</td><td>11</td><td><span class="status-pill ok">Approved</span></td></tr>
              <tr><td>CarePoint Medical Supply</td><td>Medical Equipment</td><td>4</td><td><span class="status-pill ok">Approved</span></td></tr>
              <tr><td>Domus Living</td><td>Home Appliances</td><td>3</td><td><span class="status-pill pending">Pending</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="pane-listings" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Moderation</span><h1>Listings</h1></div><button class="btn btn-gold" id="adminAddProduct">+ Post Product</button></div>
        <div class="panel" id="adminListingsPanel">
          <table class="dtable" id="adminListingsTable"><thead><tr><th>Listing</th><th>Category</th><th>Seller</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody></tbody></table>
        </div>
      </div>

      <div id="pane-disputes" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Resolution center</span><h1>Disputes</h1></div></div>
        <div class="panel">
          <table class="dtable">
            <thead><tr><th>Order</th><th>Issue</th><th>Buyer</th><th>Seller</th><th>Status</th></tr></thead>
            <tbody>
              <tr><td>#EP-3375</td><td>Item not as described</td><td>Yaw B.</td><td>Crestline Estates</td><td><span class="status-pill bad">Open</span></td></tr>
              <tr><td>#EP-3201</td><td>Delayed handover</td><td>Esi A.</td><td>Forgewright Equipment</td><td><span class="status-pill pending">In Review</span></td></tr>
              <tr><td>#EP-3099</td><td>Payment mismatch</td><td>Kwesi N.</td><td>Aurelia Fine Jewelry</td><td><span class="status-pill ok">Resolved</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="pane-commissions" class="dash-pane" style="display:none;">
        <div class="dash-head"><div><span class="eyebrow">Revenue</span><h1>Commission Settings</h1></div></div>
        <div class="panel" style="padding:24px;">
          <div class="grid-cards cols-3" style="margin-bottom:0;">
            <div class="field"><label>Jewelry & Gems</label><input type="text" value="6.5%" /></div>
            <div class="field"><label>Real Estate</label><input type="text" value="2.0%" /></div>
            <div class="field"><label>Automotive</label><input type="text" value="4.0%" /></div>
            <div class="field"><label>Medical Equipment</label><input type="text" value="3.5%" /></div>
            <div class="field"><label>Industrial Machinery</label><input type="text" value="3.0%" /></div>
            <div class="field"><label>Home Appliances</label><input type="text" value="5.0%" /></div>
          </div>
          <button class="btn btn-gold" onclick="toast('Commission rates updated')">Save Changes</button>
        </div>
      </div>

    </div>
  </div>
</main>

<div class="modal-overlay" id="adminProductModal">
  <div class="modal-box" style="max-width:520px;">
    <button class="close" type="button" onclick="document.getElementById('adminProductModal').classList.remove('open')">×</button>
    <h3>Publish a Product</h3>
    <p style="color:var(--ink-dim); font-size:13px; margin-bottom:18px;">Admin listings publish immediately.</p>
    <form id="adminProductForm">
      <div class="field"><label>Product title</label><input name="title" required /></div>
      <div class="field-row"><div class="field"><label>Category</label><select name="category_id" required><option value="">Choose category</option></select></div><div class="field"><label>Price</label><input name="price" type="number" min="0" step="0.01" required /></div></div>
      <div class="field-row"><div class="field"><label>Currency</label><select name="currency"><option value="USD">US Dollar (USD)</option><option value="NGN">Nigerian Naira (NGN)</option></select></div><div class="field"><label>Sell as</label><select name="sale_channel"><option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="both">Retail and wholesale</option></select></div></div>
      <div class="field"><label>Product picture</label><input name="image" type="file" accept="image/jpeg,image/png,image/webp" /></div>
      <div class="field"><label>Description</label><textarea name="description" rows="3"></textarea></div>
      <button class="btn btn-gold btn-block" type="submit">Publish Product</button>
    </form>
  </div>
</div>

<div id="site-footer"></div>

<script src="js/config.js?v=2"></script>
<script src="js/api.js?v=2"></script>
<script src="js/app.js?v=2"></script>
<script>
window.EPREEM_PHP_ADMIN_SESSION = true;
</script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
  const user = Auth.getUser();
  if(!window.EPREEM_PHP_ADMIN_SESSION && (!user || !['admin', 'super_admin'].includes(user.role))){
    toast('Sign in as an administrator to view this page');
    setTimeout(() => location.href = 'admin-login.php', 800);
    return;
  }

  async function loadOverview(){
    try{
      const stats = await EpreemAPI.admin.stats();
      const vals = document.querySelectorAll('#pane-overview .stat-card .val');
      vals[0].textContent = stats.total_users.toLocaleString();
      vals[1].textContent = stats.active_listings.toLocaleString();
      vals[2].textContent = EpreemUI.money(stats.gmv_this_month);
      vals[3].textContent = stats.open_disputes;
    }catch(err){ /* leave placeholders */ }

    try{
      const pending = await EpreemAPI.admin.pendingSellers();
      const tbody = document.querySelectorAll('#pane-overview .dtable tbody')[0];
      tbody.innerHTML = pending.slice(0,5).map(u => `
        <tr>
          <td>${u.name}</td>
          <td>Individual</td>
          <td>${new Date(u.created_at).toLocaleDateString()}</td>
          <td><span class="status-pill pending">Pending</span></td>
          <td><a href="#" style="color:var(--gold-bright); font-size:12px;" data-pane-trigger="sellers">Review →</a></td>
        </tr>`).join('') || `<tr><td colspan="5" style="text-align:center; color:var(--ink-faint); padding:24px;">No pending verifications.</td></tr>`;
      tbody.querySelectorAll('[data-pane-trigger]').forEach(a => a.addEventListener('click', (e) => { e.preventDefault(); showPane('sellers'); }));
    }catch(err){ /* ignore */ }
  }

  async function loadUsers(){
    try{
      const res = await EpreemAPI.admin.users();
      document.querySelector('#pane-users .dtable tbody').innerHTML = res.data.map(u => `
        <tr>
          <td>${u.name}</td>
          <td>${u.role.charAt(0).toUpperCase() + u.role.slice(1)}</td>
          <td>${new Date(u.created_at).toLocaleDateString('en-US', { month:'short', year:'numeric' })}</td>
          <td><span class="status-pill ${u.is_suspended ? 'bad' : 'ok'}">${u.is_suspended ? 'Suspended' : 'Active'}</span></td>
          <td><button class="btn btn-sm btn-line" onclick="toggleSuspend(${u.id})">${u.is_suspended ? 'Reinstate' : 'Suspend'}</button></td>
        </tr>`).join('');
    }catch(err){ /* ignore */ }
  }

  window.toggleSuspend = async function(userId){
    try{ await EpreemAPI.admin.toggleSuspend(userId); toast('User status updated'); loadUsers(); }
    catch(err){ toast(err.message || 'Could not update user'); }
  };

  async function loadSellers(){
    try{
      const pending = await EpreemAPI.admin.pendingSellers();
      document.querySelector('#pane-sellers .dtable tbody').innerHTML = pending.map(u => `
        <tr>
          <td>${u.name}</td>
          <td>${u.phone ? 'ID + Phone on file' : 'National ID'}</td>
          <td>${new Date(u.created_at).toLocaleDateString()}</td>
          <td><span class="status-pill pending">Pending</span></td>
          <td>
            <button class="btn btn-sm btn-gold" onclick="verifySeller(${u.id}, true)">Approve</button>
            <button class="btn btn-sm btn-danger" onclick="verifySeller(${u.id}, false)">Reject</button>
          </td>
        </tr>`).join('') || `<tr><td colspan="5" style="text-align:center; color:var(--ink-faint); padding:24px;">No pending seller verifications.</td></tr>`;
    }catch(err){ /* ignore */ }
  }

  window.verifySeller = async function(userId, approve){
    try{ await EpreemAPI.admin.verifySeller(userId, approve); toast(approve ? 'Seller approved' : 'Seller rejected'); loadSellers(); loadOverview(); }
    catch(err){ toast(err.message || 'Could not update seller'); }
  };

  async function loadBusinesses(){
    try{
      const businesses = await EpreemAPI.admin.businesses();
      document.querySelector('#pane-businesses .dtable tbody').innerHTML = businesses.map(b => `
        <tr>
          <td>${b.company_name}</td>
          <td>${b.industry || '—'}</td>
          <td>—</td>
          <td><span class="status-pill ${b.status === 'approved' ? 'ok' : (b.status === 'rejected' ? 'bad' : 'pending')}">${b.status.charAt(0).toUpperCase()+b.status.slice(1)}</span></td>
        </tr>`).join('');
    }catch(err){ /* ignore */ }
  }

  async function loadListings(){
    try{
      const res = await EpreemAPI.admin.listings();
      document.querySelector('#adminListingsTable tbody').innerHTML = res.data.map(p => `
        <tr>
          <td>${p.title}</td>
          <td>${p.category ? p.category.name : ''}</td>
          <td>${p.seller ? p.seller.name : ''}</td>
          <td>${EpreemUI.money(p.price, p.currency)}</td>
          <td><span class="status-pill ${p.status === 'live' ? 'ok' : (p.status === 'suspended' ? 'bad' : 'pending')}">${p.status.replace('_',' ')}</span></td>
          <td>
            ${p.status === 'pending_review' ? `<button class="btn btn-sm btn-gold" onclick="updateListingStatus(${p.id}, 'live')">Approve</button>` : ''}
            ${p.status !== 'suspended' ? `<button class="btn btn-sm btn-danger" onclick="updateListingStatus(${p.id}, 'suspended')">Suspend</button>` : ''}
          </td>
        </tr>`).join('');
    }catch(err){ /* ignore */ }
  }

  window.updateListingStatus = async function(listingId, status){
    try{ await EpreemAPI.admin.updateListingStatus(listingId, status); toast(status === 'live' ? 'Listing approved' : 'Listing updated'); loadListings(); loadOverview(); }
    catch(err){ toast(err.message || 'Could not update listing'); }
  };

  async function loadDisputes(){
    try{
      const disputes = await EpreemAPI.admin.disputes();
      const statusClass = { open:'bad', in_review:'pending', resolved:'ok', rejected:'bad' };
      document.querySelector('#pane-disputes .dtable tbody').innerHTML = disputes.map(d => `
        <tr>
          <td>#${d.order.order_number}</td>
          <td>${d.reason}</td>
          <td>${d.order.buyer ? d.order.buyer.name : ''}</td>
          <td>${d.raised_by ? d.raisedBy.name : ''}</td>
          <td><span class="status-pill ${statusClass[d.status]}">${d.status.replace('_',' ')}</span></td>
        </tr>`).join('') || `<tr><td colspan="5" style="text-align:center; color:var(--ink-faint); padding:24px;">No disputes.</td></tr>`;
    }catch(err){ /* ignore */ }
  }

  async function loadCommissions(){
    try{
      const commissions = await EpreemAPI.admin.commissions();
      const panel = document.querySelector('#pane-commissions .grid-cards');
      panel.innerHTML = commissions.map(c => `
        <div class="field">
          <label>${c.category.name}</label>
          <input type="text" value="${c.rate_percent}%" data-commission-id="${c.id}" />
        </div>`).join('');
    }catch(err){ /* ignore */ }
  }

  document.querySelector('#pane-commissions .btn-gold').addEventListener('click', async () => {
    const inputs = document.querySelectorAll('[data-commission-id]');
    try{
      await Promise.all([...inputs].map(input =>
        EpreemAPI.admin.updateCommission(input.dataset.commissionId, parseFloat(input.value))
      ));
      toast('Commission rates updated');
    }catch(err){ toast(err.message || 'Could not update commissions'); }
  });

  function showPane(name){
    document.querySelectorAll('.dash-pane').forEach(p => p.style.display = 'none');
    document.getElementById('pane-' + name).style.display = 'block';
    document.querySelectorAll('.dash-nav a[data-pane]').forEach(a => a.classList.toggle('active', a.dataset.pane === name));
    const loaders = { users: loadUsers, sellers: loadSellers, businesses: loadBusinesses, listings: loadListings, disputes: loadDisputes, commissions: loadCommissions };
    if(loaders[name]) loaders[name]();
  }
  document.querySelectorAll('.dash-nav a[data-pane]').forEach(a => {
    a.addEventListener('click', (e) => { e.preventDefault(); showPane(a.dataset.pane); });
  });
  document.querySelectorAll('[data-pane-trigger]').forEach(a => {
    a.addEventListener('click', (e) => { e.preventDefault(); showPane(a.dataset.paneTrigger); });
  });

  document.getElementById('adminAddProduct').addEventListener('click', async () => {
    const select = document.querySelector('#adminProductForm [name="category_id"]');
    if(select.options.length === 1){
      try{
        const categories = await EpreemAPI.categories.list();
        select.innerHTML += categories.map(category => `<option value="${category.id}">${category.name}</option>`).join('');
      }catch(err){ toast('Could not load categories'); return; }
    }
    document.getElementById('adminProductModal').classList.add('open');
  });

  document.getElementById('adminProductForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const file = form.elements.image.files[0];
    if(file && file.size > 5 * 1024 * 1024){ toast('Use an image smaller than 5 MB'); return; }
    const imageData = file ? await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); }) : null;
    try{
      await EpreemAPI.admin.createProduct({ title: form.elements.title.value, category_id: Number(form.elements.category_id.value), price: Number(form.elements.price.value), currency: form.elements.currency.value, sale_channel: form.elements.sale_channel.value, description: form.elements.description.value, image_data: imageData });
      form.reset(); document.getElementById('adminProductModal').classList.remove('open'); toast('Product published'); loadListings(); loadOverview();
    }catch(err){ toast(err.message || 'Could not publish product'); }
  });

  loadOverview();
});
</script>
</body>
</html>
