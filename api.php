<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bearer_user(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $header, $match)) {
        $sessionUserId = (int) ($_SESSION['epreem_user_id'] ?? 0);
        if ($sessionUserId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionUserId]);
        return $stmt->fetch() ?: null;
    }

    $payload = json_decode(base64_decode($match[1], true) ?: '', true);
    if (!is_array($payload) || empty($payload['uid'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $payload['uid']]);
    return $stmt->fetch() ?: null;
}

function require_user(PDO $pdo): array
{
    $user = bearer_user($pdo);
    if (!$user) {
        json_response(['message' => 'Unauthenticated'], 401);
    }
    if (!empty($user['is_suspended'])) {
        json_response(['message' => 'This account has been suspended'], 403);
    }
    return $user;
}

function require_admin(PDO $pdo): array
{
    $user = require_user($pdo);
    if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
        json_response(['message' => 'Admin access required'], 403);
    }
    return $user;
}

function require_verified_seller(PDO $pdo): array
{
    $user = require_user($pdo);
    if (!in_array($user['role'] ?? '', ['seller', 'admin', 'super_admin'], true)) {
        json_response(['message' => 'Seller access required'], 403);
    }
    if (($user['role'] ?? '') === 'seller' && ($user['verification_status'] ?? '') !== 'verified') {
        json_response(['message' => 'Seller access is pending admin approval'], 403);
    }
    return $user;
}

function token_for(array $user): string
{
    return base64_encode(json_encode(['uid' => (int) $user['id'], 'iat' => time()]));
}

function public_user(array $user): array
{
    unset($user['password'], $user['remember_token']);
    $user['is_suspended'] = (bool) ($user['is_suspended'] ?? false);
    $user['seller_status'] = match ($user['verification_status'] ?? 'unverified') {
        'verified' => 'approved',
        default => $user['verification_status'] ?? 'pending',
    };
    return $user;
}

function ensure_local_schema(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'reason_for_joining'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE users ADD reason_for_joining ENUM('buyer', 'seller', 'both') NOT NULL DEFAULT 'buyer' AFTER role");
        $pdo->exec("UPDATE users SET reason_for_joining = CASE WHEN role = 'seller' THEN 'seller' ELSE 'buyer' END");
    }

    $column = $pdo->query("SHOW COLUMNS FROM products LIKE 'sale_channel'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE products ADD sale_channel ENUM('retail', 'wholesale', 'both') NOT NULL DEFAULT 'retail' AFTER listing_type");
    }

    $column = $pdo->query("SHOW COLUMNS FROM products LIKE 'currency'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE products ADD currency ENUM('USD', 'NGN') NOT NULL DEFAULT 'USD' AFTER price");
    }
}

function save_product_image(PDO $pdo, int $productId, ?string $imageData): void
{
    if (!$imageData || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $imageData, $match)) {
        return;
    }

    $binary = base64_decode($match[2], true);
    if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
        json_response(['message' => 'Use a JPG, PNG, or WebP image smaller than 5 MB.'], 422);
    }

    $directory = __DIR__ . '/uploads/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        json_response(['message' => 'Could not prepare product image storage.'], 500);
    }

    $extension = $match[1] === 'jpeg' ? 'jpg' : $match[1];
    $filename = 'product-' . $productId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (file_put_contents($directory . '/' . $filename, $binary) === false) {
        json_response(['message' => 'Could not save the product image.'], 500);
    }

    $pdo->prepare('INSERT INTO product_images (product_id, url, type, sort_order, created_at, updated_at) VALUES (?, ?, "image", 0, NOW(), NOW())')
        ->execute([$productId, 'uploads/products/' . $filename]);
}

function product_rows(PDO $pdo, string $where = 'p.status = "live"', array $params = [], string $suffix = ''): array
{
    $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
                   u.name AS seller_name, u.verification_status AS seller_verification_status,
                   b.company_name,
                   a.id AS auction_id, a.starting_price, a.current_bid, a.min_increment,
                   a.starts_at, a.ends_at, a.status AS auction_status
            FROM products p
            JOIN categories c ON c.id = p.category_id
            JOIN users u ON u.id = p.seller_id
            LEFT JOIN businesses b ON b.owner_id = u.id
            LEFT JOIN auctions a ON a.product_id = p.id
            WHERE {$where}
            {$suffix}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn ($row) => shape_product($pdo, $row), $stmt->fetchAll());
}

function shape_product(PDO $pdo, array $row): array
{
    $images = [];
    try {
        $stmt = $pdo->prepare('SELECT url, type, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order');
        $stmt->execute([(int) $row['id']]);
        $images = $stmt->fetchAll();
    } catch (Throwable) {
        $images = [];
    }

    $auction = null;
    if (!empty($row['auction_id'])) {
        $bidStmt = $pdo->prepare('SELECT COUNT(*) FROM bids WHERE auction_id = ?');
        $bidStmt->execute([(int) $row['auction_id']]);
        $auction = [
            'id' => (int) $row['auction_id'],
            'starting_price' => $row['starting_price'],
            'current_bid' => $row['current_bid'],
            'min_increment' => $row['min_increment'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'status' => $row['auction_status'],
            'bids_count' => (int) $bidStmt->fetchColumn(),
            'bids' => [],
        ];
    }

    return [
        'id' => (int) $row['id'],
        'seller_id' => (int) $row['seller_id'],
        'category_id' => (int) $row['category_id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'price' => $row['price'],
        'currency' => $row['currency'] ?? 'USD',
        'old_price' => $row['old_price'],
        'listing_type' => $row['listing_type'],
        'sale_channel' => $row['sale_channel'] ?? 'retail',
        'condition' => $row['condition'],
        'location' => $row['location'],
        'warranty' => (bool) ($row['warranty'] ?? false),
        'stock' => (int) ($row['stock'] ?? 0),
        'status' => $row['status'],
        'product_status' => $row['status'] === 'live' ? 'approved' : $row['status'],
        'category' => ['id' => (int) $row['category_id'], 'name' => $row['category_name'], 'slug' => $row['category_slug'], 'icon' => $row['category_icon']],
        'seller' => ['id' => (int) $row['seller_id'], 'name' => $row['seller_name'], 'verification_status' => $row['seller_verification_status'], 'business' => $row['company_name'] ? ['company_name' => $row['company_name']] : null],
        'images' => $images,
        'auction' => $auction,
        'reviews' => [],
    ];
}

$pdo = db();
ensure_local_schema($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (str_starts_with($path, $script)) {
    $path = substr($path, strlen($script)) ?: '/';
}
$path = '/' . trim($path, '/');

try {
    if ($method === 'GET' && $path === '/categories') {
        json_response($pdo->query('SELECT id, name, slug, icon FROM categories ORDER BY name')->fetchAll());
    }

    if ($method === 'GET' && $path === '/products') {
        $where = 'p.status = "live"';
        $params = [];
        if (!empty($_GET['category'])) {
            $where .= ' AND c.slug = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['q'])) {
            $where .= ' AND (p.title LIKE ? OR u.name LIKE ?)';
            $params[] = '%' . $_GET['q'] . '%';
            $params[] = '%' . $_GET['q'] . '%';
        }
        if (!empty($_GET['listing_type']) && $_GET['listing_type'] !== 'all') {
            $where .= ' AND p.listing_type = ?';
            $params[] = $_GET['listing_type'];
        }
        if (!empty($_GET['sale_channel']) && $_GET['sale_channel'] !== 'all') {
            $where .= ' AND (p.sale_channel = ? OR p.sale_channel = "both")';
            $params[] = $_GET['sale_channel'];
        }
        $items = product_rows($pdo, $where, $params, 'ORDER BY p.created_at DESC LIMIT 100');
        json_response(['data' => $items, 'total' => count($items)]);
    }

    if ($method === 'GET' && preg_match('#^/products/(\d+)$#', $path, $m)) {
        $viewer = bearer_user($pdo);
        $where = 'p.id = ? AND p.status = "live"';
        $params = [(int) $m[1]];
        if ($viewer && in_array($viewer['role'] ?? '', ['admin', 'super_admin'], true)) {
            $where = 'p.id = ?';
        } elseif ($viewer) {
            $where = 'p.id = ? AND (p.status = "live" OR p.seller_id = ?)';
            $params[] = (int) $viewer['id'];
        }
        $items = product_rows($pdo, $where, $params, 'LIMIT 1');
        if (!$items) {
            json_response(['message' => 'Listing not found'], 404);
        }
        $product = $items[0];
        $related = product_rows($pdo, 'p.status = "live" AND p.category_id = ? AND p.id <> ?', [$product['category_id'], $product['id']], 'LIMIT 4');
        json_response(['product' => $product, 'related' => $related]);
    }

    if ($method === 'GET' && $path === '/auctions') {
        $items = product_rows($pdo, 'p.status = "live" AND p.listing_type = "auction"', [], 'ORDER BY a.ends_at ASC LIMIT 100');
        json_response(['data' => $items, 'total' => count($items)]);
    }

    if ($method === 'POST' && $path === '/auth/register') {
        $data = input_json();
        foreach (['name', 'email', 'password', 'reason_for_joining'] as $field) {
            if (empty($data[$field])) {
                json_response(['message' => 'Validation failed', 'errors' => [$field => ['This field is required.']]], 422);
            }
        }
        if (!in_array($data['reason_for_joining'], ['buyer', 'seller', 'both'], true)) {
            json_response(['message' => 'Invalid reason for joining'], 422);
        }

        $needsSellerApproval = in_array($data['reason_for_joining'], ['seller', 'both'], true);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, phone, password, role, reason_for_joining, verification_status, is_suspended, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())');
        $stmt->execute([
            trim((string) $data['name']),
            trim((string) $data['email']),
            $data['phone'] ?? null,
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
            $needsSellerApproval ? 'seller' : 'buyer',
            $data['reason_for_joining'],
            $needsSellerApproval ? 'pending' : 'verified',
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($needsSellerApproval) {
            $biz = $pdo->prepare('INSERT INTO businesses (owner_id, company_name, status, created_at, updated_at) VALUES (?, ?, "pending", NOW(), NOW())');
            $biz->execute([$id, trim((string) ($data['company_name'] ?? $data['name']))]);
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        json_response(['message' => $needsSellerApproval ? 'Account created. Seller access is pending admin approval.' : 'Account created', 'user' => public_user($user), 'token' => token_for($user)], 201);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $data = input_json();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([(string) ($data['email'] ?? '')]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string) ($data['password'] ?? ''), (string) $user['password'])) {
            json_response(['message' => 'Invalid credentials'], 401);
        }
        json_response(['user' => public_user($user), 'token' => token_for($user)]);
    }

    if ($method === 'GET' && $path === '/auth/me') {
        json_response(public_user(require_user($pdo)));
    }

    if ($method === 'GET' && $path === '/seller/stats') {
        $user = require_verified_seller($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND status = "live"');
        $stmt->execute([(int) $user['id']]);
        $activeListings = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ? AND status = "pending_review"');
        $stmt->execute([(int) $user['id']]);
        $pendingListings = (int) $stmt->fetchColumn();

        json_response([
            'total_revenue' => 0,
            'active_listings' => $activeListings,
            'orders_this_month' => 0,
            'avg_rating' => null,
            'pending_listings' => $pendingListings,
        ]);
    }

    if ($method === 'GET' && $path === '/seller/products') {
        $user = require_verified_seller($pdo);
        $items = product_rows($pdo, 'p.seller_id = ?', [(int) $user['id']], 'ORDER BY p.created_at DESC');
        json_response($items);
    }

    if ($method === 'POST' && $path === '/seller/products') {
        $user = require_verified_seller($pdo);
        $data = input_json();
        foreach (['title', 'category_id', 'price'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                json_response(['message' => 'Validation failed', 'errors' => [$field => ['This field is required.']]], 422);
            }
        }

        $listingType = in_array($data['listing_type'] ?? 'fixed', ['fixed', 'auction', 'rent'], true) ? $data['listing_type'] : 'fixed';
        $saleChannel = in_array($data['sale_channel'] ?? 'retail', ['retail', 'wholesale', 'both'], true) ? $data['sale_channel'] : 'retail';
        $businessId = null;
        $bizStmt = $pdo->prepare('SELECT id FROM businesses WHERE owner_id = ? AND status = "approved" ORDER BY id DESC LIMIT 1');
        $bizStmt->execute([(int) $user['id']]);
        $businessId = $bizStmt->fetchColumn() ?: null;

        $currency = in_array($data['currency'] ?? 'USD', ['USD', 'NGN'], true) ? $data['currency'] : 'USD';
        $stmt = $pdo->prepare('INSERT INTO products (seller_id, business_id, category_id, title, description, price, currency, listing_type, sale_channel, stock, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_review", NOW(), NOW())');
        $stmt->execute([
            (int) $user['id'],
            $businessId,
            (int) $data['category_id'],
            trim((string) $data['title']),
            trim((string) ($data['description'] ?? '')) ?: null,
            (float) $data['price'],
            $currency,
            $listingType,
            $saleChannel,
            max(1, (int) ($data['stock'] ?? 1)),
        ]);

        save_product_image($pdo, (int) $pdo->lastInsertId(), $data['image_data'] ?? null);

        $items = product_rows($pdo, 'p.id = ?', [(int) $pdo->lastInsertId()], 'LIMIT 1');
        json_response(['message' => 'Product submitted for admin review.', 'product' => $items[0] ?? null], 201);
    }

    if ($method === 'PUT' && preg_match('#^/seller/products/(\d+)$#', $path, $m)) {
        $user = require_verified_seller($pdo);
        $data = input_json();
        $productId = (int) $m[1];
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND seller_id = ? LIMIT 1');
        $stmt->execute([$productId, (int) $user['id']]);
        $product = $stmt->fetch();
        if (!$product) {
            json_response(['message' => 'Listing not found'], 404);
        }
        if ($product['status'] === 'live') {
            json_response(['message' => 'Live listings must be reviewed again before changes can be published'], 422);
        }

        $title = trim((string) ($data['title'] ?? $product['title']));
        $categoryId = (int) ($data['category_id'] ?? $product['category_id']);
        $price = (float) ($data['price'] ?? $product['price']);
        if ($title === '' || $categoryId <= 0 || $price < 0) {
            json_response(['message' => 'A title, category, and valid price are required'], 422);
        }
        $saleChannel = in_array($data['sale_channel'] ?? $product['sale_channel'], ['retail', 'wholesale', 'both'], true)
            ? ($data['sale_channel'] ?? $product['sale_channel'])
            : 'retail';
        $description = trim((string) ($data['description'] ?? $product['description']));
        $currency = in_array($data['currency'] ?? $product['currency'] ?? 'USD', ['USD', 'NGN'], true) ? ($data['currency'] ?? $product['currency'] ?? 'USD') : 'USD';
        $stmt = $pdo->prepare('UPDATE products SET title = ?, category_id = ?, price = ?, currency = ?, sale_channel = ?, description = ?, status = "pending_review", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$title, $categoryId, $price, $currency, $saleChannel, $description ?: null, $productId]);
        save_product_image($pdo, $productId, $data['image_data'] ?? null);
        $items = product_rows($pdo, 'p.id = ?', [$productId], 'LIMIT 1');
        json_response(['message' => 'Listing updated and submitted for admin review.', 'product' => $items[0] ?? null]);
    }

    if ($method === 'GET' && $path === '/seller/orders') {
        require_verified_seller($pdo);
        json_response([]);
    }

    if ($method === 'GET' && $path === '/admin/sellers/pending') {
        require_admin($pdo);
        $rows = $pdo->query("SELECT u.id, u.name, u.email, u.phone, u.role, u.reason_for_joining, u.verification_status, b.company_name, b.status AS business_status
                             FROM users u
                             LEFT JOIN businesses b ON b.owner_id = u.id
                             WHERE u.role = 'seller' AND u.verification_status = 'pending'
                             ORDER BY u.created_at DESC")->fetchAll();
        json_response(array_map('public_user', $rows));
    }

    if ($method === 'GET' && $path === '/admin/stats') {
        require_admin($pdo);
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $pendingSellers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller' AND verification_status = 'pending'")->fetchColumn();
        $pendingListings = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'pending_review'")->fetchColumn();
        $liveListings = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'live'")->fetchColumn();
        json_response([
            'total_users' => $totalUsers,
            'pending_sellers' => $pendingSellers,
            'pending_listings' => $pendingListings,
            'live_listings' => $liveListings,
            'gmv' => 0,
            'open_disputes' => 0,
        ]);
    }

    if ($method === 'GET' && $path === '/admin/users') {
        require_admin($pdo);
        $rows = $pdo->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 200')->fetchAll();
        json_response(['data' => array_map('public_user', $rows), 'total' => count($rows)]);
    }

    if ($method === 'PUT' && preg_match('#^/admin/users/(\d+)/suspend$#', $path, $m)) {
        $admin = require_admin($pdo);
        if ((int) $admin['id'] === (int) $m[1]) {
            json_response(['message' => 'You cannot suspend your own account'], 422);
        }
        $stmt = $pdo->prepare('UPDATE users SET is_suspended = IF(is_suspended = 1, 0, 1), updated_at = NOW() WHERE id = ?');
        $stmt->execute([(int) $m[1]]);
        json_response(['message' => 'User suspension status updated.']);
    }

    if ($method === 'PUT' && preg_match('#^/admin/sellers/(\d+)/verify$#', $path, $m)) {
        require_admin($pdo);
        $data = input_json();
        $approved = (bool) ($data['approve'] ?? false);
        $status = $approved ? 'verified' : 'rejected';
        $businessStatus = $approved ? 'approved' : 'rejected';
        $pdo->prepare('UPDATE users SET verification_status = ?, updated_at = NOW() WHERE id = ? AND role = "seller"')->execute([$status, (int) $m[1]]);
        $pdo->prepare('UPDATE businesses SET status = ?, updated_at = NOW() WHERE owner_id = ?')->execute([$businessStatus, (int) $m[1]]);
        json_response(['message' => $approved ? 'Seller approved.' : 'Seller rejected.']);
    }

    if ($method === 'GET' && $path === '/admin/listings') {
        require_admin($pdo);
        $status = $_GET['status'] ?? '';
        $where = $status !== '' && $status !== 'all' ? 'p.status = ?' : '1 = 1';
        $params = $where === '1 = 1' ? [] : [$status];
        $items = product_rows($pdo, $where, $params, 'ORDER BY p.created_at DESC LIMIT 200');
        json_response(['data' => $items, 'total' => count($items)]);
    }

    if ($method === 'POST' && $path === '/admin/products') {
        $admin = require_admin($pdo);
        $data = input_json();
        foreach (['title', 'category_id', 'price'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                json_response(['message' => 'A title, category, and price are required.'], 422);
            }
        }
        $currency = in_array($data['currency'] ?? 'USD', ['USD', 'NGN'], true) ? $data['currency'] : 'USD';
        $saleChannel = in_array($data['sale_channel'] ?? 'retail', ['retail', 'wholesale', 'both'], true) ? $data['sale_channel'] : 'retail';
        $stmt = $pdo->prepare('INSERT INTO products (seller_id, category_id, title, description, price, currency, listing_type, sale_channel, stock, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "fixed", ?, ?, "live", NOW(), NOW())');
        $stmt->execute([(int) $admin['id'], (int) $data['category_id'], trim((string) $data['title']), trim((string) ($data['description'] ?? '')) ?: null, (float) $data['price'], $currency, $saleChannel, max(1, (int) ($data['stock'] ?? 1))]);
        $productId = (int) $pdo->lastInsertId();
        save_product_image($pdo, $productId, $data['image_data'] ?? null);
        $items = product_rows($pdo, 'p.id = ?', [$productId], 'LIMIT 1');
        json_response(['message' => 'Product published.', 'product' => $items[0] ?? null], 201);
    }

    if ($method === 'PUT' && preg_match('#^/admin/listings/(\d+)/status$#', $path, $m)) {
        require_admin($pdo);
        $data = input_json();
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['draft', 'pending_review', 'live', 'sold', 'suspended'], true)) {
            json_response(['message' => 'Invalid listing status'], 422);
        }
        $pdo->prepare('UPDATE products SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, (int) $m[1]]);
        json_response(['message' => $status === 'live' ? 'Listing approved and published.' : 'Listing status updated.']);
    }

    if ($method === 'GET' && $path === '/admin/businesses') {
        require_admin($pdo);
        json_response($pdo->query('SELECT b.*, u.name AS owner_name, u.email AS owner_email FROM businesses b JOIN users u ON u.id = b.owner_id ORDER BY b.created_at DESC')->fetchAll());
    }

    if ($method === 'PUT' && preg_match('#^/admin/businesses/(\d+)/status$#', $path, $m)) {
        require_admin($pdo);
        $data = input_json();
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            json_response(['message' => 'Invalid business status'], 422);
        }
        $pdo->prepare('UPDATE businesses SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, (int) $m[1]]);
        json_response(['message' => 'Business status updated.']);
    }

    if ($method === 'GET' && in_array($path, ['/cart', '/wishlist', '/notifications', '/orders', '/conversations'], true)) {
        require_user($pdo);
        json_response($path === '/cart' ? ['items' => [], 'summary' => ['subtotal' => 0, 'shipping' => 0, 'total' => 0]] : []);
    }

    json_response(['message' => 'Endpoint not found', 'path' => $path], 404);
} catch (PDOException $exception) {
    $message = str_contains($exception->getMessage(), 'Duplicate') ? 'This email is already registered.' : 'Database request failed.';
    json_response(['message' => $message], 500);
} catch (Throwable $exception) {
    json_response(['message' => $exception->getMessage()], 500);
}
