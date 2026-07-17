<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

session_start();

if (!in_array($_SESSION['epreem_role'] ?? null, ['admin', 'super_admin'], true)) {
    header('Location: admin-login.php');
    exit;
}

$pdo = db();
$notice = '';
$error = '';

if (empty($_SESSION['crud_token'])) {
    $_SESSION['crud_token'] = bin2hex(random_bytes(32));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    return trim($slug, '-');
}

function money_value(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function ensure_admin_schema(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM products LIKE 'sale_channel'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE products ADD sale_channel ENUM('retail', 'wholesale', 'both') NOT NULL DEFAULT 'retail' AFTER listing_type");
    }
}

ensure_admin_schema($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['crud_token'], (string) ($_POST['crud_token'] ?? ''))) {
            throw new RuntimeException('Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_user') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? 'buyer');
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '' || $email === '' || !in_array($role, ['buyer', 'seller', 'admin', 'super_admin'], true)) {
                throw new RuntimeException('User name, email and role are required.');
            }

            if ($id > 0) {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, password = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$name, $email, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$name, $email, $role, $id]);
                }
                $notice = 'User updated.';
            } else {
                if ($password === '') {
                    throw new RuntimeException('Password is required for new users.');
                }
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, verification_status, created_at, updated_at) VALUES (?, ?, ?, ?, 'verified', NOW(), NOW())");
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                $notice = 'User created.';
            }
        }

        if ($action === 'delete_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) ($_SESSION['epreem_user_id'] ?? 0)) {
                throw new RuntimeException('You cannot delete your own admin account.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $notice = 'User deleted.';
        }

        if ($action === 'verify_seller') {
            $id = (int) ($_POST['id'] ?? 0);
            $approved = (string) ($_POST['approve'] ?? '') === '1';
            $pdo->prepare('UPDATE users SET verification_status = ?, updated_at = NOW() WHERE id = ? AND role = "seller"')->execute([$approved ? 'verified' : 'rejected', $id]);
            $pdo->prepare('UPDATE businesses SET status = ?, updated_at = NOW() WHERE owner_id = ?')->execute([$approved ? 'approved' : 'rejected', $id]);
            $notice = $approved ? 'Seller approved.' : 'Seller rejected.';
        }

        if ($action === 'save_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = slugify((string) ($_POST['slug'] ?? $name));
            $icon = trim((string) ($_POST['icon'] ?? ''));

            if ($name === '' || $slug === '') {
                throw new RuntimeException('Category name and slug are required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, icon = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$name, $slug, $icon ?: null, $id]);
                $notice = 'Category updated.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO categories (name, slug, icon, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
                $stmt->execute([$name, $slug, $icon ?: null]);
                $notice = 'Category created.';
            }
        }

        if ($action === 'delete_category') {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            $notice = 'Category deleted.';
        }

        if ($action === 'save_product') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $sellerId = (int) ($_POST['seller_id'] ?? 0);
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'pending_review');
            $saleChannel = (string) ($_POST['sale_channel'] ?? 'retail');
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($title === '' || $sellerId <= 0 || $categoryId <= 0) {
                throw new RuntimeException('Product title, seller and category are required.');
            }
            if (!in_array($saleChannel, ['retail', 'wholesale', 'both'], true)) {
                throw new RuntimeException('Choose a valid sale channel.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE products SET title = ?, seller_id = ?, category_id = ?, price = ?, status = ?, sale_channel = ?, description = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$title, $sellerId, $categoryId, $price, $status, $saleChannel, $description ?: null, $id]);
                $notice = 'Product updated.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (title, seller_id, category_id, price, status, listing_type, sale_channel, description, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'fixed', ?, ?, 1, NOW(), NOW())");
                $stmt->execute([$title, $sellerId, $categoryId, $price, $status, $saleChannel, $description ?: null]);
                $notice = 'Product created.';
            }
        }

        if ($action === 'delete_product') {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            $notice = 'Product deleted.';
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$users = $pdo->query('SELECT id, name, email, role, verification_status FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
$categories = $pdo->query('SELECT id, name, slug, icon FROM categories ORDER BY name')->fetchAll();
$sellers = $pdo->query("SELECT id, name FROM users WHERE role IN ('seller', 'admin', 'super_admin') ORDER BY name")->fetchAll();
$products = $pdo->query(
    'SELECT p.id, p.title, p.price, p.status, p.sale_channel, p.description, p.seller_id, p.category_id, c.name AS category_name, u.name AS seller_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     JOIN users u ON u.id = p.seller_id
     ORDER BY p.id DESC LIMIT 100'
)->fetchAll();

$editUser = null;
$editCategory = null;
$editProduct = null;

if (isset($_GET['edit_user'])) {
    $stmt = $pdo->prepare('SELECT id, name, email, role, verification_status FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit_user']]);
    $editUser = $stmt->fetch() ?: null;
}

if (isset($_GET['edit_category'])) {
    $stmt = $pdo->prepare('SELECT id, name, slug, icon FROM categories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit_category']]);
    $editCategory = $stmt->fetch() ?: null;
}

if (isset($_GET['edit_product'])) {
    $stmt = $pdo->prepare('SELECT id, title, seller_id, category_id, price, status, sale_channel, description FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['edit_product']]);
    $editProduct = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Admin CRUD - EPREEM</title>
<link rel="stylesheet" href="css/style.css" />
<style>
.crud-shell{max-width:1280px;margin:0 auto;padding:30px var(--gutter) 70px;}
.crud-top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px;flex-wrap:wrap;}
.crud-grid{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:22px;align-items:start;margin-bottom:34px;}
.crud-panel{border:1px solid var(--line-soft);background:var(--bg-card);padding:22px;border-radius:var(--radius);}
.crud-panel h2{font-size:22px;margin-bottom:16px;}
.crud-table{width:100%;border-collapse:collapse;font-size:13px;}
.crud-table th,.crud-table td{border-bottom:1px solid var(--line-soft);padding:10px;text-align:left;vertical-align:top;}
.crud-table th{color:var(--ink-faint);font-size:10px;text-transform:uppercase;letter-spacing:.08em;}
.crud-actions{display:flex;gap:8px;flex-wrap:wrap;}
.crud-actions form{display:inline;}
.crud-message{margin-bottom:18px;}
@media (max-width:900px){.crud-grid{grid-template-columns:1fr}.crud-table{display:block;overflow-x:auto;}}
</style>
</head>
<body>
<div id="site-header"></div>
<main class="crud-shell">
  <div class="crud-top">
    <div><span class="eyebrow">Admin console</span><h1>CRUD Manager</h1></div>
    <div class="crud-actions"><a class="btn btn-line" href="dashboard.php">Dashboard</a><a class="btn btn-line" href="admin-logout.php">Sign Out</a></div>
  </div>

  <?php if ($notice !== ''): ?><div class="form-alert success crud-message"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="form-alert crud-message"><?= h($error) ?></div><?php endif; ?>

  <section class="crud-grid" id="users">
    <form class="crud-panel" method="post">
      <h2><?= $editUser ? 'Edit User' : 'Create User' ?></h2>
      <input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" />
      <input type="hidden" name="action" value="save_user" />
      <input type="hidden" name="id" value="<?= h((string) ($editUser['id'] ?? 0)) ?>" />
      <div class="field"><label>Name</label><input name="name" required value="<?= h((string) ($editUser['name'] ?? '')) ?>" /></div>
      <div class="field"><label>Email</label><input type="email" name="email" required value="<?= h((string) ($editUser['email'] ?? '')) ?>" /></div>
      <div class="field"><label>Role</label><select name="role"><?php foreach (['buyer','seller','admin','super_admin'] as $role): ?><option value="<?= h($role) ?>" <?= (($editUser['role'] ?? 'buyer') === $role) ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $role))) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Password <?= $editUser ? '(leave blank to keep)' : '' ?></label><input type="password" name="password" <?= $editUser ? '' : 'required' ?> /></div>
      <button class="btn btn-gold btn-block" type="submit"><?= $editUser ? 'Update User' : 'Create User' ?></button>
    </form>
    <div class="crud-panel">
      <h2>Users</h2>
      <table class="crud-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Approval</th><th></th></tr></thead><tbody>
        <?php foreach ($users as $user): ?><tr><td><?= h($user['name']) ?></td><td><?= h($user['email']) ?></td><td><?= h($user['role']) ?></td><td><?= h($user['verification_status']) ?></td><td class="crud-actions"><a class="btn btn-line btn-sm" href="admin-crud.php?edit_user=<?= h((string) $user['id']) ?>#users">Edit</a><?php if ($user['role'] === 'seller' && $user['verification_status'] === 'pending'): ?><form method="post"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="verify_seller" /><input type="hidden" name="id" value="<?= h((string) $user['id']) ?>" /><input type="hidden" name="approve" value="1" /><button class="btn btn-gold btn-sm" type="submit">Approve</button></form><form method="post"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="verify_seller" /><input type="hidden" name="id" value="<?= h((string) $user['id']) ?>" /><input type="hidden" name="approve" value="0" /><button class="btn btn-danger btn-sm" type="submit">Reject</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this user?');"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="delete_user" /><input type="hidden" name="id" value="<?= h((string) $user['id']) ?>" /><button class="btn btn-danger btn-sm" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <section class="crud-grid" id="categories">
    <form class="crud-panel" method="post">
      <h2><?= $editCategory ? 'Edit Category' : 'Create Category' ?></h2>
      <input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" />
      <input type="hidden" name="action" value="save_category" />
      <input type="hidden" name="id" value="<?= h((string) ($editCategory['id'] ?? 0)) ?>" />
      <div class="field"><label>Name</label><input name="name" required value="<?= h((string) ($editCategory['name'] ?? '')) ?>" /></div>
      <div class="field"><label>Slug</label><input name="slug" value="<?= h((string) ($editCategory['slug'] ?? '')) ?>" /></div>
      <div class="field"><label>Icon</label><input name="icon" value="<?= h((string) ($editCategory['icon'] ?? '')) ?>" /></div>
      <button class="btn btn-gold btn-block" type="submit"><?= $editCategory ? 'Update Category' : 'Create Category' ?></button>
    </form>
    <div class="crud-panel">
      <h2>Categories</h2>
      <table class="crud-table"><thead><tr><th>Name</th><th>Slug</th><th>Icon</th><th></th></tr></thead><tbody>
        <?php foreach ($categories as $category): ?><tr><td><?= h($category['name']) ?></td><td><?= h($category['slug']) ?></td><td><?= h((string) $category['icon']) ?></td><td class="crud-actions"><a class="btn btn-line btn-sm" href="admin-crud.php?edit_category=<?= h((string) $category['id']) ?>#categories">Edit</a><form method="post" onsubmit="return confirm('Delete this category?');"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="delete_category" /><input type="hidden" name="id" value="<?= h((string) $category['id']) ?>" /><button class="btn btn-danger btn-sm" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <section class="crud-grid" id="products">
    <form class="crud-panel" method="post">
      <h2><?= $editProduct ? 'Edit Product' : 'Create Product' ?></h2>
      <input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" />
      <input type="hidden" name="action" value="save_product" />
      <input type="hidden" name="id" value="<?= h((string) ($editProduct['id'] ?? 0)) ?>" />
      <div class="field"><label>Title</label><input name="title" required value="<?= h((string) ($editProduct['title'] ?? '')) ?>" /></div>
      <div class="field"><label>Seller</label><select name="seller_id" required><option value="">Choose seller</option><?php foreach ($sellers as $seller): ?><option value="<?= h((string) $seller['id']) ?>" <?= ((int) ($editProduct['seller_id'] ?? 0) === (int) $seller['id']) ? 'selected' : '' ?>><?= h($seller['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Category</label><select name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= h((string) $category['id']) ?>" <?= ((int) ($editProduct['category_id'] ?? 0) === (int) $category['id']) ? 'selected' : '' ?>><?= h($category['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Price</label><input type="number" step="0.01" min="0" name="price" required value="<?= h(isset($editProduct['price']) ? money_value($editProduct['price']) : '') ?>" /></div>
      <div class="field"><label>Status</label><select name="status"><?php foreach (['draft','pending_review','live','sold','suspended'] as $status): ?><option value="<?= h($status) ?>" <?= (($editProduct['status'] ?? 'pending_review') === $status) ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Sale channel</label><select name="sale_channel"><?php foreach (['retail','wholesale','both'] as $channel): ?><option value="<?= h($channel) ?>" <?= (($editProduct['sale_channel'] ?? 'retail') === $channel) ? 'selected' : '' ?>><?= h(ucfirst($channel === 'both' ? 'Wholesale and retail' : $channel)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Description</label><textarea name="description" rows="4"><?= h((string) ($editProduct['description'] ?? '')) ?></textarea></div>
      <button class="btn btn-gold btn-block" type="submit"><?= $editProduct ? 'Update Product' : 'Create Product' ?></button>
    </form>
    <div class="crud-panel">
      <h2>Products</h2>
      <table class="crud-table"><thead><tr><th>Title</th><th>Seller</th><th>Category</th><th>Price</th><th>Channel</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><tr><td><?= h($product['title']) ?></td><td><?= h($product['seller_name']) ?></td><td><?= h($product['category_name']) ?></td><td><?= h(money_value($product['price'])) ?></td><td><?= h($product['sale_channel']) ?></td><td><?= h($product['status']) ?></td><td class="crud-actions"><a class="btn btn-line btn-sm" href="admin-crud.php?edit_product=<?= h((string) $product['id']) ?>#products">Edit</a><?php if ($product['status'] === 'pending_review'): ?><form method="post"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="save_product" /><input type="hidden" name="id" value="<?= h((string) $product['id']) ?>" /><input type="hidden" name="title" value="<?= h($product['title']) ?>" /><input type="hidden" name="seller_id" value="<?= h((string) $product['seller_id']) ?>" /><input type="hidden" name="category_id" value="<?= h((string) $product['category_id']) ?>" /><input type="hidden" name="price" value="<?= h((string) $product['price']) ?>" /><input type="hidden" name="sale_channel" value="<?= h($product['sale_channel']) ?>" /><input type="hidden" name="description" value="<?= h((string) $product['description']) ?>" /><input type="hidden" name="status" value="live" /><button class="btn btn-gold btn-sm" type="submit">Approve</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Delete this product?');"><input type="hidden" name="crud_token" value="<?= h($_SESSION['crud_token']) ?>" /><input type="hidden" name="action" value="delete_product" /><input type="hidden" name="id" value="<?= h((string) $product['id']) ?>" /><button class="btn btn-danger btn-sm" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </section>
</main>
<div id="site-footer"></div>
<script src="js/config.js"></script>
<script src="js/api.js"></script>
<script src="js/password-visibility.js?v=1"></script>
<script src="js/app.js"></script>
</body>
</html>
