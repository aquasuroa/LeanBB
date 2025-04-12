<?php
/*
 * LeanBB
 * Original Author: DFFZMXJ
 * Secondary Development: Aquasuroa
 * Version: 2.3 (Refactoring & Optimization)
 * License: MIT
 */

// --- Configuration ---
const SITE_NAME_DEFAULT = 'LeanBB';
const DB_SALT = 'change_this_secret_salt_please'; // Important: Please change this value to enhance database security!
const HASH_ALGORITHM = PASSWORD_DEFAULT;
const POSTS_PER_PAGE = 20;
const ADMIN_USERNAME_DEFAULT = 'admin';
const ADMIN_PASSWORD_DEFAULT = 'password'; // Important: Please change the default admin password immediately!

// --- Error Reporting ---
// error_reporting(0); ini_set('display_errors', 0); // Production
error_reporting(E_ALL); ini_set('display_errors', 1); // Development

// --- Database Settings ---
define('DB_FILE', __DIR__ . '/' . substr(hash('sha256', __FILE__ . DB_SALT), 0, 16) . '.sqlite');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $needs_setup = !file_exists(DB_FILE);
        try {
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            if ($needs_setup) {
                setup_database($pdo);
            }
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

function setup_database(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL, is_admin INTEGER DEFAULT 0, created_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
            CREATE TABLE IF NOT EXISTS boards (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL,
                description TEXT, created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, board_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                title TEXT NOT NULL, content TEXT NOT NULL, created_at INTEGER NOT NULL,
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_posts_board_id ON posts(board_id);
            CREATE INDEX IF NOT EXISTS idx_posts_user_id ON posts(user_id);
            CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at);
            CREATE TABLE IF NOT EXISTS replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                content TEXT NOT NULL, created_at INTEGER NOT NULL,
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_replies_post_id ON replies(post_id);
            CREATE INDEX IF NOT EXISTS idx_replies_user_id ON replies(user_id);
        ");
        set_setting('site_title', SITE_NAME_DEFAULT);
        set_setting('logo_url', '');
        set_setting('copyright_info', '© ' . date('Y') . ' ' . SITE_NAME_DEFAULT . '. Powered by LeanBB');
        set_setting('allow_registration', '1');
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (username, password, is_admin, created_at) VALUES (?, ?, 1, ?)");
        $stmt->execute([ADMIN_USERNAME_DEFAULT, password_hash(ADMIN_PASSWORD_DEFAULT, HASH_ALGORITHM), time()]);
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO boards (name, description, created_at) VALUES (?, ?, ?)");
        $stmt->execute(['General', 'General discussion board', time()]);
    } catch (PDOException $e) {
        die("Database initialization failed: " . $e->getMessage());
    }
}

// --- Session Management ---
session_start();

// --- Helper Functions ---
function h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_setting(string $key, ?string $default = null): ?string {
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $stmt = get_db()->query("SELECT key, value FROM settings");
            while ($row = $stmt->fetch()) {
                $settings[$row['key']] = $row['value'];
            }
        } catch (PDOException $e) {
            /* Ignore during initialization */
        }
    }
    return $settings[$key] ?? $default;
}

function set_setting(string $key, string $value): bool {
    $stmt = get_db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    return $stmt->execute([$key, $value]);
}

function get_current_user(): ?array {
    static $current_user = false;
    if ($current_user === false) {
        $user_id = $_SESSION['user_id'] ?? null;
        if ($user_id) {
            $stmt = get_db()->prepare("SELECT id, username, is_admin FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $current_user = $stmt->fetch();
            if (!$current_user) {
                unset($_SESSION['user_id']);
                $current_user = null;
            }
        } else {
            $current_user = null;
        }
    }
    return $current_user;
}

function is_admin(): bool {
    $user = get_current_user();
    return $user && $user['is_admin'];
}

function require_login(): void {
    if (!get_current_user()) {
        redirect('/auth?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

function require_admin(): void {
    if (!is_admin()) {
        render_error('Access Denied', 403, 'You do not have permission to access this page');
    }
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function get_path_info(): string {
    return strtolower(rtrim($_SERVER['PATH_INFO'] ?? '/', '/')) ?: '/';
}

function get_request_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
}

function relative_time(int $timestamp): string {
    $diff = time() - $timestamp;
    if ($diff < 0) {
        return 'in the future';
    }
    if ($diff < 10) {
        return 'just now';
    }
    $intervals = [
        31536000 => 'year',
        2592000 => 'month',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    ];
    foreach ($intervals as $secs => $label) {
        if ($diff >= $secs) {
            $num = floor($diff / $secs);
            return $num . ' ' . $label . ($num > 1 ? 's' : '') . ' ago';
        }
    }
    return ''; // Fallback, though loop ensures a return
}

function generate_pagination(int $total_items, int $per_page, int $current_page, string $base_url): string {
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages <= 1) {
        return '';
    }
    $html = '<nav aria-label="Page navigation"><ul class="pagination">';
    $query_params = $_GET;
    $link = function($page) use ($base_url, $query_params) {
        $query_params['page'] = $page;
        return h($base_url . '?' . http_build_query($query_params));
    };
    $html .= ($current_page > 1) ? '<li><a href="' . $link($current_page - 1) . '">« Previous</a></li>' : '<li class="disabled"><span>« Previous</span></li>';
    for ($i = 1; $i <= $total_pages; $i++) {
        $html .= ($i == $current_page) ? '<li class="active"><span>' . $i . '</span></li>' : '<li><a href="' . $link($i) . '">' . $i . '</a></li>';
    }
    $html .= ($current_page < $total_pages) ? '<li><a href="' . $link($current_page + 1) . '">Next »</a></li>' : '<li class="disabled"><span>Next »</span></li>';
    return $html . '</ul></nav>';
}

// --- Rendering Functions ---
function render_header(string $title): void {
    $user = get_current_user();
    $site_title = h(get_setting('site_title', SITE_NAME_DEFAULT));
    $logo_url = h(get_setting('logo_url', ''));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo h($title) . ' - ' . $site_title; ?></title>
        <style>
            :root {
                --primary-color: #4e82ff;
                --secondary-color: #e9ecef;
                --background-color: #f6f9ff;
                --text-color: #2b2d42;
                --link-color: #4e82ff;
                --button-bg: #4e82ff;
                --button-text: #ffffff;
                --border-color: #d1d5db;
                --hover-color: #3b6bff;
                --error-color: #e53e3e;
                --disabled-color: #9ca3af;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                line-height: 1.6;
                background-color: var(--background-color);
                color: var(--text-color);
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 960px;
                margin: 20px auto;
                background-color: #ffffff;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                padding: 0;
            }
            header {
                background-color: var(--secondary-color);
                padding: 15px;
                border-bottom: 1px solid var(--border-color);
                margin: -1px -1px 20px -1px;
                border-radius: 8px 8px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
            }
            header h1 {
                margin: 0;
                font-size: 1.5em;
            }
            header h1 a {
                text-decoration: none;
                color: var(--text-color);
            }
            header .logo {
                max-height: 40px;
                margin-right: 10px;
            }
            nav ul {
                list-style: none;
                padding: 0;
                margin: 0;
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            nav a {
                color: var(--link-color);
                padding: 5px 10px;
                border-radius: 4px;
                transition: background-color 0.2s, color 0.2s;
            }
            nav a:hover, nav a.active {
                background-color: var(--primary-color);
                color: var(--button-text);
            }
            main {
                padding: 20px;
            }
            a {
                color: var(--link-color);
                text-decoration: none;
                transition: color 0.2s;
            }
            a:hover {
                color: var(--hover-color);
                text-decoration: underline;
            }
            form fieldset {
                border: 1px solid var(--border-color);
                border-radius: 4px;
                padding: 15px;
                margin-bottom: 15px;
            }
            form legend {
                font-weight: bold;
                padding: 0 5px;
            }
            form label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            form input[type="text"],
            form input[type="password"],
            form textarea,
            form select {
                width: 100%;
                padding: 10px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                box-sizing: border-box;
                margin-bottom: 10px;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            form input[type="text"]:focus,
            form input[type="password"]:focus,
            form textarea:focus,
            form select:focus {
                border-color: var(--primary-color);
                box-shadow: 0 0 0 2px rgba(78, 130, 255, 0.2);
                outline: none;
            }
            form textarea {
                min-height: 150px;
                resize: vertical;
            }
            .button, form button[type="submit"] {
                background-color: var(--button-bg);
                color: var(--button-text);
                border: none;
                padding: 10px 15px;
                border-radius: 4px;
                cursor: pointer;
                transition: background-color 0.2s;
            }
            .button:hover, form button[type="submit"]:hover {
                background-color: var(--hover-color);
            }
            .button.danger {
                background-color: var(--error-color);
            }
            .button.danger:hover {
                background-color: #c53030;
            }
            .post-list, .pagination {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .post-list li {
                border-bottom: 1px solid var(--border-color);
                padding: 15px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .post-meta, .reply-meta {
                font-size: 0.9em;
                color: #6b7280;
            }
            .post-title {
                font-size: 1.2em;
                flex: 1;
            }
            .post-content, .reply-content {
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .reply-item {
                border-top: 1px dashed var(--border-color);
                padding: 15px 0;
            }
            .alert {
                padding: 15px;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .alert-error {
                color: #9b2c2c;
                background-color: #fed7d7;
                border: 1px solid #feb2b2;
            }
            .alert-success {
                color: #276749;
                background-color: #c6f6d5;
                border: 1px solid #9ae6b4;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                border: 1px solid var(--border-color);
                padding: 8px 12px;
                text-align: left;
            }
            th {
                background-color: var(--secondary-color);
            }
            tr:nth-child(even) {
                background-color: #f9fafb;
            }
            .admin-actions a, .admin-actions button {
                margin-right: 5px;
                padding: 5px 10px;
            }
            .board-filter a {
                margin: 0 5px;
                padding: 4px 8px;
                border-radius: 4px;
                color: var(--link-color);
                transition: background-color 0.2s, color 0.2s;
            }
            .board-filter a:hover, .board-filter a.active {
                background-color: var(--primary-color);
                color: var(--button-text);
            }
            .pagination {
                display: flex;
                justify-content: center;
                gap: 5px;
                margin-top: 20px;
            }
            .pagination li a, .pagination li span {
                padding: 6px 12px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                color: var(--link-color);
                transition: background-color 0.2s, color 0.2s;
            }
            .pagination li a:hover {
                background-color: var(--primary-color);
                color: var(--button-text);
            }
            .pagination li.active span {
                background-color: var(--primary-color);
                color: var(--button-text);
                border-color: var(--primary-color);
            }
            .pagination li.disabled span {
                color: var(--disabled-color);
            }
            footer {
                text-align: center;
                padding: 15px;
                font-size: 0.9em;
                color: #6b7280;
                border-top: 1px solid var(--border-color);
            }
            mark {
                background-color: rgba(78, 130, 255, 0.2);
                padding: 2px 4px;
            }
            @media (max-width: 768px) {
                header { flex-direction: column; align-items: flex-start; }
                nav ul { margin-top: 10px; }
                .container { margin: 10px; }
                .button, form button[type="submit"] { width: 100%; margin-bottom: 10px; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <h1>
                    <?php if ($logo_url): ?>
                        <img src="<?php echo $logo_url; ?>" alt="<?php echo $site_title; ?> Logo" class="logo">
                    <?php endif; ?>
                    <a href="/"><?php echo $site_title; ?></a>
                </h1>
                <nav>
                    <ul>
                        <li><a href="/">Home</a></li>
                        <?php if ($user): ?>
                            <li><a href="/profile"><?php echo h($user['username']); ?></a></li>
                            <li><a href="/post/new">New Post</a></li>
                            <?php if ($user['is_admin']): ?>
                                <li><a href="/admin">Admin</a></li>
                            <?php endif; ?>
                            <li><a href="/auth/logout">Logout</a></li>
                        <?php else: ?>
                            <li><a href="/auth">Login</a></li>
                        <?php endif; ?>
                        <li><a href="/search">Search</a></li>
                    </ul>
                </nav>
            </header>
            <main>
                <h2><?php echo h($title); ?></h2>
    <?php
}

function render_footer(): void {
    $copyright = h(get_setting('copyright_info', '© ' . date('Y')));
    ?>
            </main>
            <footer><?php echo $copyright; ?></footer>
        </div>
    </body>
    </html>
    <?php
}

function render_error(string $title, int $status_code = 500, string $message = 'An unexpected error occurred'): void {
    http_response_code($status_code);
    render_header($title);
    echo '<div class="alert alert-error">' . h($message) . '</div><p><a href="/">Back to Home</a></p>';
    render_footer();
    exit;
}

// --- Route Handlers ---
function handle_home(): void {
    $db = get_db();
    $current_page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * POSTS_PER_PAGE;
    $board_id = isset($_GET['board']) ? intval($_GET['board']) : null;
    $where_clause = $board_id ? 'WHERE p.board_id = ?' : '';
    $params = $board_id ? [$board_id] : [];
    $board_name = $board_id ? ($db->prepare("SELECT name FROM boards WHERE id = ?")->execute([$board_id]) ? $db->query("SELECT name FROM boards WHERE id = ?")->fetchColumn() : 'Unknown') : null;
    $page_title = $board_id ? 'Board: ' . h($board_name) : 'Latest Posts';
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM posts p " . $where_clause);
    $count_stmt->execute($params);
    $total_posts = $count_stmt->fetchColumn();
    $sql = "SELECT p.id, p.title, p.created_at, u.username as author_name, u.id as author_id, b.name as board_name, b.id as board_id
            FROM posts p JOIN users u ON p.user_id = u.id JOIN boards b ON p.board_id = b.id {$where_clause}
            ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
    $params[] = POSTS_PER_PAGE;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
    $boards = $db->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
    render_header($page_title);
    ?>
    <div class="board-filter">
        <strong>Board:</strong>
        <a href="/" class="<?php echo !$board_id ? 'active' : ''; ?>">All</a>
        <?php foreach ($boards as $b): ?>
            <a href="/?board=<?php echo $b['id']; ?>" class="<?php echo ($board_id == $b['id']) ? 'active' : ''; ?>"><?php echo h($b['name']); ?></a>
        <?php endforeach; ?>
    </div>
    <hr>
    <?php if (empty($posts)): ?>
        <p>This board has no posts yet</p>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="post-title"><a href="/post/<?php echo $post['id']; ?>"><?php echo h($post['title']); ?></a></div>
                    <div class="post-meta">Posted in <a href="/?board=<?php echo $post['board_id']; ?>"><?php echo h($post['board_name']); ?></a> by <a href="/profile/<?php echo $post['author_id']; ?>"><?php echo h($post['author_name']); ?></a> - <time datetime="<?php echo date('c', $post['created_at']); ?>"><?php echo relative_time($post['created_at']); ?></time></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php echo generate_pagination($total_posts, POSTS_PER_PAGE, $current_page, '/'); ?>
    <?php endif;
    render_footer();
}

function handle_view_post(int $post_id): void {
    $db = get_db();
    $stmt = $db->prepare("SELECT p.id, p.title, p.content, p.created_at, p.board_id, u.username as author_name, u.id as author_id, b.name as board_name FROM posts p JOIN users u ON p.user_id = u.id JOIN boards b ON p.board_id = b.id WHERE p.id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post) {
        render_error('Post Not Found', 404);
    }
    $reply_stmt = $db->prepare("SELECT r.id, r.content, r.created_at, u.username as author_name, u.id as author_id FROM replies r JOIN users u ON r.user_id = u.id WHERE r.post_id = ? ORDER BY r.created_at ASC");
    $reply_stmt->execute([$post_id]);
    $replies = $reply_stmt->fetchAll();
    render_header($post['title']);
    ?>
    <div class="post-meta">Posted in <a href="/?board=<?php echo $post['board_id']; ?>"><?php echo h($post['board_name']); ?></a> by <a href="/profile/<?php echo $post['author_id']; ?>"><?php echo h($post['author_name']); ?></a> - <time datetime="<?php echo date('c', $post['created_at']); ?>"><?php echo relative_time($post['created_at']); ?></time></div>
    <article class="post-content"><?php echo nl2br(h($post['content'])); ?></article>
    <hr>
    <h3><?php echo count($replies); ?> Replies</h3>
    <div class="replies-section">
        <?php if (empty($replies)): ?>
            <p>No replies yet</p>
        <?php else: ?>
            <?php foreach ($replies as $reply): ?>
                <div class="reply-item">
                    <div class="reply-meta"><a href="/profile/<?php echo $reply['author_id']; ?>"><?php echo h($reply['author_name']); ?></a> - <time datetime="<?php echo date('c', $reply['created_at']); ?>"><?php echo relative_time($reply['created_at']); ?></time></div>
                    <div class="reply-content"><?php echo nl2br(h($reply['content'])); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <hr>
    <?php if (get_current_user()): ?>
        <h3>Reply to Post</h3>
        <form action="/reply/submit" method="POST">
            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
            <fieldset>
                <label for="content">Content:</label>
                <textarea id="content" name="content" required></textarea>
            </fieldset>
            <button type="submit">Submit Reply</button>
        </form>
    <?php else: ?>
        <p><a href="/auth?redirect=/post/<?php echo $post['id']; ?>">Login</a> to reply</p>
    <?php endif;
    render_footer();
}

function handle_new_post_form(): void {
    require_login();
    $boards = get_db()->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
    if (empty($boards)) {
        render_error('Cannot Post', 500, 'No boards available. Please create a board in the admin panel.');
    }
    render_header('New Post');
    ?>
    <form action="/post/submit" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
        <fieldset>
            <label for="board_id">Board:</label>
            <select id="board_id" name="board_id" required>
                <?php foreach ($boards as $board): ?>
                    <option value="<?php echo $board['id']; ?>"><?php echo h($board['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="title">Title:</label>
            <input type="text" id="title" name="title" required maxlength="120">
            <label for="content">Content:</label>
            <textarea id="content" name="content" required></textarea>
            <p><small>Tip: Plain text only</small></p>
        </fieldset>
        <button type="submit">Submit Post</button>
    </form>
    <?php render_footer();
}

function handle_submit_post(): void {
    require_login();
    verify_csrf();
    $user = get_current_user();
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $board_id = intval($_POST['board_id'] ?? 0);
    if (empty($title) || empty($content) || $board_id <= 0) {
        render_error('Error', 400, 'Title, content, and board cannot be empty');
    }
    if (mb_strlen($title) > 120) {
        render_error('Error', 400, 'Title cannot exceed 120 characters');
    }
    $stmt = get_db()->prepare("SELECT id FROM boards WHERE id = ?");
    $stmt->execute([$board_id]);
    if (!$stmt->fetch()) {
        render_error('Error', 400, 'Selected board does not exist');
    }
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO posts (board_id, user_id, title, content, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$board_id, $user['id'], $title, $content, time()]);
    redirect('/post/' . $db->lastInsertId());
}

function handle_submit_reply(): void {
    require_login();
    verify_csrf();
    $user = get_current_user();
    $post_id = intval($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    if (empty($content) || $post_id <= 0) {
        render_error('Error', 400, 'Reply content cannot be empty and post ID must be specified');
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    if (!$stmt->fetch()) {
        render_error('Error', 404, 'The post to reply to does not exist');
    }
    $stmt = $db->prepare("INSERT INTO replies (post_id, user_id, content, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$post_id, $user['id'], $content, time()]);
    redirect('/post/' . $post_id);
}

function handle_login_register_form(): void {
    if (get_current_user()) {
        redirect($_GET['redirect'] ?? '/');
    }
    render_header('Welcome! Login or Register');
    $allow_registration = get_setting('allow_registration', '1') === '1';
    ?>
    <form action="/auth/submit" method="POST">
        <input type="hidden" name="redirect" value="<?php echo h($_GET['redirect'] ?? '/'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
        <fieldset>
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required maxlength="24">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </fieldset>
        <button type="submit" name="action" value="login">Login</button>
        <?php if ($allow_registration): ?>
            <button type="submit" name="action" value="register">Register</button>
        <?php else: ?>
            <p><small>New user registration is disabled</small></p>
        <?php endif; ?>
    </form>
    <?php render_footer();
}

function handle_auth_submit(): void {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $action = $_POST['action'] ?? 'login';
    $redirect_url = $_POST['redirect'] ?? '/';
    if (empty($username) || empty($password)) {
        render_error('Error', 400, 'Username and password cannot be empty');
    }
    if (mb_strlen($username) > 24) {
        render_error('Error', 400, 'Username cannot exceed 24 characters');
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($action === 'register') {
        if (get_setting('allow_registration', '1') !== '1') {
            render_error('Registration Closed', 403, 'Sorry, new user registration is currently disabled');
        }
        if ($user) {
            render_error('Error', 400, 'Username is already taken');
        }
        $hashed_password = password_hash($password, HASH_ALGORITHM);
        $stmt = $db->prepare("INSERT INTO users (username, password, is_admin, created_at) VALUES (?, ?, 0, ?)");
        $stmt->execute([$username, $hashed_password, time()]);
        $_SESSION['user_id'] = $db->lastInsertId();
        redirect($redirect_url);
    } else {
        if (!$user || !password_verify($password, $user['password'])) {
            render_error('Login Failed', 401, 'Incorrect username or password');
        }
        $_SESSION['user_id'] = $user['id'];
        session_regenerate_id(true);
        redirect($redirect_url);
    }
}

function handle_logout(): void {
    unset($_SESSION['user_id']);
    session_destroy();
    render_header('Logged Out');
    echo '<p>You have successfully logged out</p><p><a href="/">Back to Home</a></p>';
    render_footer();
}

function handle_profile(?int $user_id = null): void {
    $db = get_db();
    $current_user = get_current_user();
    $target_user_id = $user_id ?? ($current_user['id'] ?? null);
    if (!$target_user_id) {
        require_login();
    }
    $stmt = $db->prepare("SELECT id, username, is_admin, created_at FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        render_error('User Not Found', 404);
    }
    $posts_stmt = $db->prepare("SELECT p.id, p.title, p.created_at, b.name as board_name, b.id as board_id FROM posts p JOIN boards b ON p.board_id = b.id WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 50");
    $posts_stmt->execute([$user['id']]);
    $posts = $posts_stmt->fetchAll();
    $replies_stmt = $db->prepare("SELECT r.id, r.content, r.created_at, p.title as post_title, p.id as post_id FROM replies r JOIN posts p ON r.post_id = p.id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 50");
    $replies_stmt->execute([$user['id']]);
    $replies = $replies_stmt->fetchAll();
    render_header('Profile: ' . h($user['username']));
    ?>
    <p>Username: <?php echo h($user['username']); ?></p>
    <p>Registered: <time datetime="<?php echo date('c', $user['created_at']); ?>"><?php echo relative_time($user['created_at']); ?></time></p>
    <p>Role: <?php echo $user['is_admin'] ? 'Admin' : 'User'; ?></p>
    <hr>
    <h3><?php echo h($user['username']); ?>'s Posts (Recent <?php echo count($posts); ?>)</h3>
    <?php if (empty($posts)): ?>
        <p>This user has not posted yet</p>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="post-title"><a href="/post/<?php echo $post['id']; ?>"><?php echo h($post['title']); ?></a></div>
                    <div class="post-meta">Posted in <a href="/?board=<?php echo $post['board_id']; ?>"><?php echo h($post['board_name']); ?></a> - <time datetime="<?php echo date('c', $post['created_at']); ?>"><?php echo relative_time($post['created_at']); ?></time></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <hr>
    <h3><?php echo h($user['username']); ?>'s Replies (Recent <?php echo count($replies); ?>)</h3>
    <?php if (empty($replies)): ?>
        <p>This user has not replied yet</p>
    <?php else: ?>
        <dl>
            <?php foreach ($replies as $reply): ?>
                <dt>Replied to <a href="/post/<?php echo $reply['post_id']; ?>">"<?php echo h($reply['post_title']); ?>"</a> - <time datetime="<?php echo date('c', $reply['created_at']); ?>"><?php echo relative_time($reply['created_at']); ?></time></dt>
                <dd><?php echo nl2br(h(mb_substr($reply['content'], 0, 100) . (mb_strlen($reply['content']) > 100 ? '...' : ''))); ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif;
    render_footer();
}

function handle_search_form(): void {
    render_header('Search Posts');
    $keyword = trim($_GET['q'] ?? '');
    $results = [];
    $total_results = 0;
    $current_page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * POSTS_PER_PAGE;
    if (!empty($keyword)) {
        $db = get_db();
        $search_term = '%' . $keyword . '%';
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE title LIKE ? OR content LIKE ?");
        $count_stmt->execute([$search_term, $search_term]);
        $total_results = $count_stmt->fetchColumn();
        $stmt = $db->prepare("SELECT p.id, p.title, p.content, p.created_at, u.username as author_name, u.id as author_id, b.name as board_name, b.id as board_id FROM posts p JOIN users u ON p.user_id = u.id JOIN boards b ON p.board_id = b.id WHERE p.title LIKE ? OR p.content LIKE ? ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$search_term, $search_term, POSTS_PER_PAGE, $offset]);
        $results = $stmt->fetchAll();
    }
    ?>
    <form action="/search" method="GET">
        <fieldset>
            <label for="q">Keyword:</label>
            <input type="text" id="q" name="q" value="<?php echo h($keyword); ?>" required>
        </fieldset>
        <button type="submit">Search</button>
    </form>
    <hr>
    <?php if (!empty($keyword)): ?>
        <h3>Search Results for "<?php echo h($keyword); ?>" (<?php echo $total_results; ?> found)</h3>
        <?php if (empty($results)): ?>
            <p>No posts found containing this keyword</p>
        <?php else: ?>
            <ul class="post-list">
                <?php foreach ($results as $post): ?>
                    <li>
                        <div class="post-title"><a href="/post/<?php echo $post['id']; ?>"><?php echo h($post['title']); ?></a></div>
                        <div class="post-meta">Posted in <a href="/?board=<?php echo $post['board_id']; ?>"><?php echo h($post['board_name']); ?></a> by <a href="/profile/<?php echo $post['author_id']; ?>"><?php echo h($post['author_name']); ?></a> - <time datetime="<?php echo date('c', $post['created_at']); ?>"><?php echo relative_time($post['created_at']); ?></time></div>
                        <div class="post-content"><?php $snippet = h(mb_substr(strip_tags($post['content']), 0, 200)); echo preg_replace('/(' . preg_quote(h($keyword), '/') . ')/i', '<mark>$1</mark>', $snippet) . (mb_strlen($post['content']) > 200 ? '...' : ''); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php echo generate_pagination($total_results, POSTS_PER_PAGE, $current_page, '/search'); ?>
        <?php endif; ?>
    <?php endif;
    render_footer();
}

function handle_admin_dashboard(): void {
    require_admin();
    render_header('Admin Dashboard');
    echo '<p>Welcome to LeanBB, Admin!</p>';
    echo '<ul><li><a href="/admin/users">User Management</a></li><li><a href="/admin/boards">Board Management</a></li><li><a href="/admin/posts">Post Management</a></li><li><a href="/admin/settings">Settings</a></li></ul>';
    render_footer();
}

function handle_admin_users(): void {
    require_admin();
    $users = get_db()->query("SELECT id, username, is_admin, created_at FROM users ORDER BY username")->fetchAll();
    render_header('User Management');
    ?>
    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Admin?</th>
                <th>Registered</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><a href="/profile/<?php echo $user['id']; ?>"><?php echo h($user['username']); ?></a></td>
                    <td><?php echo $user['is_admin'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo relative_time($user['created_at']); ?></td>
                    <td class="admin-actions">
                        <form action="/admin/users/toggle_admin" method="POST" style="display:inline;">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
                            <button type="submit"><?php echo $user['is_admin'] ? 'Remove Admin' : 'Make Admin'; ?></button>
                        </form>
                        <form action="/admin/users/delete" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete user <?php echo h(addslashes($user['username'])); ?>? All their posts and replies will also be deleted!');">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php render_footer();
}

function handle_admin_toggle_admin(): void {
    require_admin();
    verify_csrf();
    $user_id = intval($_POST['user_id'] ?? 0);
    $current_user = get_current_user();
    if ($user_id <= 0) {
        render_error('Error', 400, 'Invalid user ID');
    }
    if ($user_id === $current_user['id']) {
        render_error('Error', 400, 'Cannot modify your own admin status');
    }
    $stmt = get_db()->prepare("UPDATE users SET is_admin = 1 - is_admin WHERE id = ?");
    $stmt->execute([$user_id]);
    redirect('/admin/users');
}

function handle_admin_delete_user(): void {
    require_admin();
    verify_csrf();
    $user_id = intval($_POST['user_id'] ?? 0);
    $current_user = get_current_user();
    if ($user_id <= 0) {
        render_error('Error', 400, 'Invalid user ID');
    }
    if ($user_id === $current_user['id']) {
        render_error('Error', 400, 'Cannot delete your own account');
    }
    $stmt = get_db()->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    redirect('/admin/users');
}

function handle_admin_boards(): void {
    require_admin();
    $boards = get_db()->query("SELECT id, name, description, created_at FROM boards ORDER BY name")->fetchAll();
    render_header('Board Management');
    ?>
    <h3>Add New Board</h3>
    <form action="/admin/boards/add" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
        <fieldset>
            <label for="name">Board Name:</label>
            <input type="text" id="name" name="name" required maxlength="50">
            <label for="description">Description (optional):</label>
            <input type="text" id="description" name="description" maxlength="200">
        </fieldset>
        <button type="submit">Add Board</button>
    </form>
    <hr>
    <h3>Existing Boards</h3>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($boards as $board): ?>
                <tr>
                    <td><?php echo h($board['name']); ?></td>
                    <td><?php echo h($board['description'] ?? ''); ?></td>
                    <td><?php echo relative_time($board['created_at']); ?></td>
                    <td class="admin-actions">
                        <form action="/admin/boards/delete" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete board <?php echo h(addslashes($board['name'])); ?>? All posts and replies in this board will also be deleted!');">
                            <input type="hidden" name="board_id" value="<?php echo $board['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php render_footer();
}

function handle_admin_add_board(): void {
    require_admin();
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (empty($name)) {
        render_error('Error', 400, 'Board name cannot be empty');
    }
    if (mb_strlen($name) > 50) {
        render_error('Error', 400, 'Board name cannot exceed 50 characters');
    }
    if (mb_strlen($description) > 200) {
        render_error('Error', 400, 'Description cannot exceed 200 characters');
    }
    try {
        $stmt = get_db()->prepare("INSERT INTO boards (name, description, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description, time()]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
            render_error('Error', 400, 'This board name is already in use');
        } else {
            throw $e;
        }
    }
    redirect('/admin/boards');
}

function handle_admin_delete_board(): void {
    require_admin();
    verify_csrf();
    $board_id = intval($_POST['board_id'] ?? 0);
    if ($board_id <= 0) {
        render_error('Error', 400, 'Invalid board ID');
    }
    $stmt = get_db()->prepare("DELETE FROM boards WHERE id = ?");
    $stmt->execute([$board_id]);
    redirect('/admin/boards');
}

function handle_admin_posts(): void {
    require_admin();
    $db = get_db();
    $current_page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * POSTS_PER_PAGE;
    $total_posts = $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $stmt = $db->prepare("SELECT p.id, p.title, p.created_at, u.username as author_name, u.id as author_id, b.name as board_name, b.id as board_id FROM posts p JOIN users u ON p.user_id = u.id JOIN boards b ON p.board_id = b.id ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([POSTS_PER_PAGE, $offset]);
    $posts = $stmt->fetchAll();
    render_header('Post Management');
    ?>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Board</th>
                <th>Author</th>
                <th>Posted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td><a href="/post/<?php echo $post['id']; ?>" title="<?php echo h($post['title']); ?>"><?php echo h(mb_substr($post['title'], 0, 40)) . (mb_strlen($post['title']) > 40 ? '...' : ''); ?></a></td>
                    <td><a href="/?board=<?php echo $post['board_id']; ?>"><?php echo h($post['board_name']); ?></a></td>
                    <td><a href="/profile/<?php echo $post['author_id']; ?>"><?php echo h($post['author_name']); ?></a></td>
                    <td><?php echo relative_time($post['created_at']); ?></td>
                    <td class="admin-actions">
                        <a href="/admin/posts/edit/<?php echo $post['id']; ?>" class="button">Edit</a>
                        <form action="/admin/posts/delete" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete post “<?php echo h(addslashes($post['title'])); ?>”? All its replies will also be deleted!');">
                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo generate_pagination($total_posts, POSTS_PER_PAGE, $current_page, '/admin/posts');
    render_footer();
}

function handle_admin_edit_post_form(int $post_id): void {
    require_admin();
    $db = get_db();
    $stmt = $db->prepare("SELECT id, board_id, title, content FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post) {
        render_error('Post Not Found', 404);
    }
    $boards = $db->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
    render_header('Edit Post: ' . h($post['title']));
    ?>
    <form action="/admin/posts/update" method="POST">
        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
        <fieldset>
            <label for="board_id">Board:</label>
            <select id="board_id" name="board_id" required>
                <?php foreach ($boards as $board): ?>
                    <option value="<?php echo $board['id']; ?>" <?php echo ($board['id'] == $post['board_id']) ? 'selected' : ''; ?>><?php echo h($board['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="title">Title:</label>
            <input type="text" id="title" name="title" value="<?php echo h($post['title']); ?>" required maxlength="120">
            <label for="content">Content:</label>
            <textarea id="content" name="content" required><?php echo h($post['content']); ?></textarea>
        </fieldset>
        <button type="submit">Update Post</button>
        <a href="/admin/posts" class="button">Cancel</a>
    </form>
    <?php render_footer();
}

function handle_admin_update_post(): void {
    require_admin();
    verify_csrf();
    $post_id = intval($_POST['post_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $board_id = intval($_POST['board_id'] ?? 0);
    if ($post_id <= 0 || empty($title) || empty($content) || $board_id <= 0) {
        render_error('Error', 400, 'Invalid input');
    }
    if (mb_strlen($title) > 120) {
        render_error('Error', 400, 'Title cannot exceed 120 characters');
    }
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM boards WHERE id = ?");
    $stmt->execute([$board_id]);
    if (!$stmt->fetch()) {
        render_error('Error', 400, 'Selected board does not exist');
    }
    $stmt = $db->prepare("UPDATE posts SET board_id = ?, title = ?, content = ? WHERE id = ?");
    $stmt->execute([$board_id, $title, $content, $post_id]);
    redirect('/admin/posts');
}

function handle_admin_delete_post(): void {
    require_admin();
    verify_csrf();
    $post_id = intval($_POST['post_id'] ?? 0);
    if ($post_id <= 0) {
        render_error('Error', 400, 'Invalid post ID');
    }
    $stmt = get_db()->prepare("DELETE FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    redirect('/admin/posts');
}

function handle_admin_settings_form(): void {
    require_admin();
    render_header('Settings');
    ?>
    <form action="/admin/settings/update" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf(); ?>">
        <fieldset>
            <legend>Basic Settings</legend>
            <label for="site_title">Site Title:</label>
            <input type="text" id="site_title" name="site_title" value="<?php echo h(get_setting('site_title', SITE_NAME_DEFAULT)); ?>" required>
            <label for="logo_url">Logo URL (optional):</label>
            <input type="text" id="logo_url" name="logo_url" value="<?php echo h(get_setting('logo_url', '')); ?>">
            <label for="copyright_info">Copyright Info:</label>
            <input type="text" id="copyright_info" name="copyright_info" value="<?php echo h(get_setting('copyright_info', '')); ?>">
        </fieldset>
        <fieldset>
            <legend>User Settings</legend>
            <label for="allow_registration">Allow New User Registration:</label>
            <select id="allow_registration" name="allow_registration">
                <option value="1" <?php echo get_setting('allow_registration', '1') === '1' ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?php echo get_setting('allow_registration', '1') === '0' ? 'selected' : ''; ?>>No</option>
            </select>
        </fieldset>
        <button type="submit">Save Settings</button>
    </form>
    <?php render_footer();
}

function handle_admin_settings_update(): void {
    require_admin();
    verify_csrf();
    $settings_to_update = [
        'site_title' => $_POST['site_title'] ?? SITE_NAME_DEFAULT,
        'logo_url' => $_POST['logo_url'] ?? '',
        'copyright_info' => $_POST['copyright_info'] ?? '',
        'allow_registration' => ($_POST['allow_registration'] ?? '1') === '1' ? '1' : '0',
    ];
    $db = get_db();
    $db->beginTransaction();
    try {
        foreach ($settings_to_update as $key => $value) {
            set_setting($key, $value);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        render_error('Failed to Save Settings', 500, $e->getMessage());
    }
    redirect('/admin/settings');
}

// --- CSRF Protection ---
function generate_csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        unset($_SESSION['csrf_token']);
        render_error('Invalid Request', 403, 'CSRF token mismatch');
    }
}

// --- Routing ---
$path = get_path_info();
$method = get_request_method();
try {
    if ($method === 'GET') {
        switch ($path) {
            case '/':
                handle_home();
                break;
            case '/post/new':
                handle_new_post_form();
                break;
            case '/auth':
                handle_login_register_form();
                break;
            case '/auth/logout':
                handle_logout();
                break;
            case '/profile':
                handle_profile();
                break;
            case '/search':
                handle_search_form();
                break;
            case '/admin':
                handle_admin_dashboard();
                break;
            case '/admin/users':
                handle_admin_users();
                break;
            case '/admin/boards':
                handle_admin_boards();
                break;
            case '/admin/posts':
                handle_admin_posts();
                break;
            case '/admin/settings':
                handle_admin_settings_form();
                break;
            default:
                if (preg_match('#^/post/(\d+)$#', $path, $m)) {
                    handle_view_post((int)$m[1]);
                } elseif (preg_match('#^/profile/(\d+)$#', $path, $m)) {
                    handle_profile((int)$m[1]);
                } elseif (preg_match('#^/admin/posts/edit/(\d+)$#', $path, $m)) {
                    handle_admin_edit_post_form((int)$m[1]);
                } else {
                    render_error('Page Not Found', 404);
                }
        }
    } elseif ($method === 'POST') {
        switch ($path) {
            case '/post/submit':
                handle_submit_post();
                break;
            case '/reply/submit':
                handle_submit_reply();
                break;
            case '/auth/submit':
                handle_auth_submit();
                break;
            case '/admin/users/toggle_admin':
                handle_admin_toggle_admin();
                break;
            case '/admin/users/delete':
                handle_admin_delete_user();
                break;
            case '/admin/boards/add':
                handle_admin_add_board();
                break;
            case '/admin/boards/delete':
                handle_admin_delete_board();
                break;
            case '/admin/posts/update':
                handle_admin_update_post();
                break;
            case '/admin/posts/delete':
                handle_admin_delete_post();
                break;
            case '/admin/settings/update':
                handle_admin_settings_update();
                break;
            default:
                render_error('Invalid Request', 405);
        }
    } else {
        render_error('Invalid Request', 405);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    render_error('Database Error', 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    render_error('Internal Server Error', 500);
}
?>