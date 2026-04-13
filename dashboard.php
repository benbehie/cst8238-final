<?php
// ─────────────────────────────────────────────
//  dashboard.php  –  socmedia_db
//  MySpace-style personal dashboard
// ─────────────────────────────────────────────
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ── DB connection ─────────────────────────────
$host   = "localhost";
$dbuser = "root";
$dbpass = "";
$dbname = "socmedia_db";

$conn = new mysqli($host, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

$me_id   = (int)$_SESSION['user_id'];
$me_name = $_SESSION['username'];

// ── Bootstrap tables (run once, harmless after) ───────────────────────────────

// follows: follower_id → followed_id
$conn->query("
    CREATE TABLE IF NOT EXISTS follows (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        follower_id INT NOT NULL,
        followed_id INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_follow (follower_id, followed_id),
        KEY idx_follower (follower_id),
        KEY idx_followed (followed_id)
    )
");

// profile_widgets: freeform anchored items on a user's canvas
$conn->query("
    CREATE TABLE IF NOT EXISTS profile_widgets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        type       ENUM('text','image') NOT NULL DEFAULT 'text',
        content    TEXT,
        img_url    VARCHAR(512),
        pos_x      INT NOT NULL DEFAULT 10,
        pos_y      INT NOT NULL DEFAULT 10,
        width      INT NOT NULL DEFAULT 200,
        height     INT NOT NULL DEFAULT 150,
        z_index    INT NOT NULL DEFAULT 1,
        KEY idx_user (user_id)
    )
");

// ── Handle POST actions ───────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

// Follow / Unfollow
if ($action === 'follow' && isset($_POST['target_id'])) {
    $tid = (int)$_POST['target_id'];
    if ($tid !== $me_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO follows (follower_id, followed_id) VALUES (?,?)");
        $stmt->bind_param("ii", $me_id, $tid);
        $stmt->execute();
        $stmt->close();
    }
}
if ($action === 'unfollow' && isset($_POST['target_id'])) {
    $tid = (int)$_POST['target_id'];
    $stmt = $conn->prepare("DELETE FROM follows WHERE follower_id=? AND followed_id=?");
    $stmt->bind_param("ii", $me_id, $tid);
    $stmt->execute();
    $stmt->close();
}

// Add widget
if ($action === 'add_widget') {
    $type    = ($_POST['widget_type'] ?? 'text') === 'image' ? 'image' : 'text';
    $content = trim($_POST['widget_content'] ?? '');
    $img_url = trim($_POST['widget_img_url'] ?? '');
    $pos_x   = max(0, (int)($_POST['pos_x'] ?? 10));
    $pos_y   = max(0, (int)($_POST['pos_y'] ?? 10));
    $width   = max(80, (int)($_POST['widget_w'] ?? 200));
    $height  = max(40, (int)($_POST['widget_h'] ?? 150));

    $stmt = $conn->prepare("
        INSERT INTO profile_widgets (user_id,type,content,img_url,pos_x,pos_y,width,height)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param("issssiiii", $me_id, $type, $content, $img_url, $pos_x, $pos_y, $width, $height);
    $stmt->execute();
    $stmt->close();
}

// Delete widget
if ($action === 'delete_widget' && isset($_POST['widget_id'])) {
    $wid = (int)$_POST['widget_id'];
    $stmt = $conn->prepare("DELETE FROM profile_widgets WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $wid, $me_id);
    $stmt->execute();
    $stmt->close();
}

// Save widget position (AJAX drag)
if ($action === 'move_widget' && isset($_POST['widget_id'])) {
    $wid = (int)$_POST['widget_id'];
    $px  = (int)$_POST['pos_x'];
    $py  = (int)$_POST['pos_y'];
    $stmt = $conn->prepare("UPDATE profile_widgets SET pos_x=?,pos_y=? WHERE id=? AND user_id=?");
    $stmt->bind_param("iiii", $px, $py, $wid, $me_id);
    $stmt->execute();
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Fetch social data ─────────────────────────────────────────────────────────

// People I follow
$following = [];
$r = $conn->prepare("
    SELECT u.id, u.User_name
    FROM follows f
    JOIN users u ON u.id = f.followed_id
    WHERE f.follower_id = ?
    ORDER BY u.User_name
");
$r->bind_param("i", $me_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $following[] = $row;
$r->close();

// People who follow me
$followers_ids = [];
$r = $conn->prepare("SELECT follower_id FROM follows WHERE followed_id = ?");
$r->bind_param("i", $me_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $followers_ids[] = $row['follower_id'];
$r->close();

// Friends = I follow them AND they follow me
$friends = [];
foreach ($following as $f) {
    if (in_array($f['id'], $followers_ids)) {
        $friends[] = $f;
    }
}

// All users (for follow suggestions)
$all_users = [];
$r = $conn->prepare("SELECT id, User_name FROM users WHERE id != ? ORDER BY User_name");
$r->bind_param("i", $me_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $all_users[] = $row;
$r->close();

$following_ids = array_column($following, 'id');

// ── Fetch mutual friends (friends of my friends who also follow me back) ──────
// Defined here as: people I am not yet friends with who share at least one mutual friend with me
$mutuals = [];
if (!empty($friends)) {
    $friend_ids_sql = implode(',', array_column($friends, 'id'));
    $res = $conn->query("
        SELECT DISTINCT u.id, u.User_name
        FROM follows f1
        JOIN follows f2 ON f2.followed_id = f1.followed_id
        JOIN users u ON u.id = f1.follower_id
        WHERE f2.follower_id IN ($friend_ids_sql)
          AND f1.follower_id != $me_id
          AND f1.follower_id NOT IN (SELECT followed_id FROM follows WHERE follower_id = $me_id)
        ORDER BY u.User_name
        LIMIT 20
    ");
    while ($row = $res->fetch_assoc()) $mutuals[] = $row;
}

// ── Fetch widgets ─────────────────────────────────────────────────────────────
$widgets = [];
$r = $conn->prepare("SELECT * FROM profile_widgets WHERE user_id=? ORDER BY z_index, id");
$r->bind_param("i", $me_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $widgets[] = $row;
$r->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – <?= htmlspecialchars($me_name) ?></title>
<style>
/* ── Reset & base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --sidebar-bg:   #1a1a2e;
    --sidebar-text: #e0e0ff;
    --accent:       #7b68ee;
    --accent2:      #ff6b9d;
    --canvas-bg:    #0f0f1a;
    --widget-bg:    rgba(30,30,60,0.92);
    --widget-border:#3a3a7a;
    --header-h:     52px;
    --sidebar-w:    20%;
}

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--canvas-bg);
    color: #ddd;
    height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* ── Top bar ── */
#topbar {
    height: var(--header-h);
    background: #111128;
    border-bottom: 2px solid var(--accent);
    display: flex;
    align-items: center;
    padding: 0 18px;
    gap: 16px;
    flex-shrink: 0;
    z-index: 200;
}
#topbar h1 { font-size: 1.1rem; color: var(--accent); letter-spacing: 2px; flex: 1; }
#topbar .tb-user { font-size: .85rem; color: #aaa; }
#topbar a.logout {
    background: #3a1a3a; color: var(--accent2); border: 1px solid var(--accent2);
    padding: 5px 14px; border-radius: 20px; text-decoration: none; font-size: .8rem;
    transition: background .2s;
}
#topbar a.logout:hover { background: var(--accent2); color: #fff; }

/* ── Body split ── */
#main-layout {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* ── Sidebar ── */
#sidebar {
    width: var(--sidebar-w);
    min-width: 190px;
    background: var(--sidebar-bg);
    border-right: 2px solid #2a2a5a;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    flex-shrink: 0;
}
.sb-section {
    border-bottom: 1px solid #2a2a5a;
    padding: 14px 12px;
}
.sb-section h3 {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--accent);
    margin-bottom: 10px;
}
.sb-section p { font-size: .82rem; color: var(--sidebar-text); line-height: 1.6; }
.sb-section p span { color: var(--accent2); font-weight: bold; }

/* user chips */
.user-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 4px;
    border-radius: 6px;
    transition: background .15s;
    font-size: .82rem;
    color: var(--sidebar-text);
}
.user-chip:hover { background: rgba(123,104,238,.15); }
.avatar {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-weight: bold; font-size: .75rem; color: #fff;
    flex-shrink: 0;
}
.chip-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chip-btn {
    background: none; border: 1px solid var(--accent); color: var(--accent);
    padding: 2px 7px; border-radius: 10px; font-size: .7rem; cursor: pointer;
    transition: all .15s;
}
.chip-btn:hover { background: var(--accent); color: #fff; }
.chip-btn.unfollow { border-color: var(--accent2); color: var(--accent2); }
.chip-btn.unfollow:hover { background: var(--accent2); color: #fff; }

.empty-note { font-size: .75rem; color: #555; font-style: italic; }

/* follow search */
#follow-search {
    width: 100%; padding: 5px 8px; border-radius: 6px;
    background: #111130; border: 1px solid #3a3a7a; color: #ccc;
    font-size: .8rem; margin-bottom: 8px;
}
#follow-search::placeholder { color: #555; }

/* ── Canvas panel ── */
#canvas-panel {
    flex: 1;
    position: relative;
    overflow: auto;
    background:
        radial-gradient(ellipse at 20% 30%, rgba(123,104,238,.07) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 70%, rgba(255,107,157,.05) 0%, transparent 60%),
        var(--canvas-bg);
}

/* fine grid overlay */
#canvas-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(123,104,238,.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(123,104,238,.08) 1px, transparent 1px);
    background-size: 32px 32px;
    pointer-events: none;
    z-index: 0;
}

#canvas-inner {
    position: relative;
    min-width: 1200px;
    min-height: 1400px;
    z-index: 1;
}

/* ── Widgets ── */
.widget {
    position: absolute;
    background: var(--widget-bg);
    border: 1px solid var(--widget-border);
    border-radius: 8px;
    overflow: hidden;
    cursor: grab;
    box-shadow: 0 4px 24px rgba(0,0,0,.5);
    display: flex;
    flex-direction: column;
    user-select: none;
    transition: box-shadow .15s;
    min-width: 80px;
    min-height: 40px;
}
.widget:hover { box-shadow: 0 6px 32px rgba(123,104,238,.3); }
.widget.dragging { cursor: grabbing; box-shadow: 0 12px 48px rgba(123,104,238,.5); z-index: 1000 !important; }

.widget-handle {
    background: linear-gradient(90deg, #1e1e50, #2a2a6a);
    padding: 5px 8px;
    font-size: .7rem;
    color: #888;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    cursor: grab;
}
.widget-handle span { pointer-events: none; }
.widget-del {
    background: none; border: none; color: #ff4466; cursor: pointer;
    font-size: .8rem; padding: 0 2px; line-height: 1;
}
.widget-del:hover { color: #ff88aa; }

.widget-body {
    flex: 1;
    overflow: auto;
    padding: 10px;
    font-size: .85rem;
    line-height: 1.6;
    color: #ccc;
    word-break: break-word;
}
.widget-body img {
    max-width: 100%;
    height: auto;
    border-radius: 4px;
}

/* resize handle */
.resize-handle {
    position: absolute; bottom: 0; right: 0;
    width: 16px; height: 16px;
    cursor: se-resize;
    background: linear-gradient(135deg, transparent 50%, var(--widget-border) 50%);
    border-radius: 0 0 8px 0;
}

/* ── Add widget toolbar ── */
#widget-toolbar {
    position: fixed;
    bottom: 18px;
    right: 24px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    z-index: 300;
}
#add-widget-btn {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 52px; height: 52px;
    font-size: 1.6rem;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(123,104,238,.6);
    display: flex; align-items: center; justify-content: center;
    transition: transform .2s;
}
#add-widget-btn:hover { transform: rotate(45deg) scale(1.1); }

#widget-form-popup {
    display: none;
    background: #1a1a3a;
    border: 1px solid var(--accent);
    border-radius: 12px;
    padding: 18px;
    width: 300px;
    box-shadow: 0 8px 40px rgba(0,0,0,.7);
}
#widget-form-popup h3 { color: var(--accent); font-size: .9rem; margin-bottom: 12px; }
#widget-form-popup label { font-size: .78rem; color: #999; display: block; margin-bottom: 2px; }
#widget-form-popup select,
#widget-form-popup input,
#widget-form-popup textarea {
    width: 100%; padding: 6px 8px; border-radius: 6px;
    background: #111130; border: 1px solid #3a3a7a; color: #ccc;
    font-size: .82rem; margin-bottom: 10px; font-family: inherit;
}
#widget-form-popup textarea { height: 80px; resize: vertical; }
.form-row { display: flex; gap: 8px; }
.form-row > * { flex: 1; }

#widget-form-popup .btn-row { display: flex; gap: 8px; margin-top: 4px; }
.popup-btn {
    flex: 1; padding: 7px; border-radius: 7px; font-size: .82rem;
    cursor: pointer; border: none; font-family: inherit;
}
.popup-btn.primary { background: var(--accent); color: #fff; }
.popup-btn.primary:hover { background: #9580ff; }
.popup-btn.cancel { background: #2a2a4a; color: #888; }
.popup-btn.cancel:hover { color: #ccc; }

/* type-conditional fields */
#text-fields, #image-fields { display: none; }

/* scrollbar styling */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #3a3a7a; border-radius: 3px; }
</style>
</head>
<body>

<!-- ═══════════════ TOP BAR ═══════════════ -->
<div id="topbar">
    <h1>✦ SOCMEDIA</h1>
    <span class="tb-user">Logged in as <strong style="color:#e0e0ff"><?= htmlspecialchars($me_name) ?></strong></span>
    <a href="login.php" class="logout">Log out</a>
</div>

<!-- ═══════════════ MAIN ═══════════════ -->
<div id="main-layout">

    <!-- ───────── SIDEBAR ───────── -->
    <aside id="sidebar">

        <!-- Account info -->
        <div class="sb-section">
            <h3>👤 My Account</h3>
            <p>Username: <span><?= htmlspecialchars($me_name) ?></span></p>
            <p>User ID: <span>#<?= $me_id ?></span></p>
            <p>Following: <span><?= count($following) ?></span></p>
            <p>Followers: <span><?= count($followers_ids) ?></span></p>
            <p>Friends: <span><?= count($friends) ?></span></p>
        </div>

        <!-- Following -->
        <div class="sb-section">
            <h3>➜ Following</h3>
            <?php if (empty($following)): ?>
                <p class="empty-note">You're not following anyone yet.</p>
            <?php else: foreach ($following as $u): ?>
                <div class="user-chip">
                    <div class="avatar"><?= strtoupper(substr($u['User_name'],0,1)) ?></div>
                    <span class="chip-name"><?= htmlspecialchars($u['User_name']) ?></span>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="unfollow">
                        <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="chip-btn unfollow">−</button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Friends (mutual follows) -->
        <div class="sb-section">
            <h3>♥ Friends</h3>
            <?php if (empty($friends)): ?>
                <p class="empty-note">No mutual follows yet.</p>
            <?php else: foreach ($friends as $u): ?>
                <div class="user-chip">
                    <div class="avatar" style="background:linear-gradient(135deg,#ff6b9d,#ff9a5c)"><?= strtoupper(substr($u['User_name'],0,1)) ?></div>
                    <span class="chip-name"><?= htmlspecialchars($u['User_name']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Mutual friends (friends of friends) -->
        <?php if (!empty($mutuals)): ?>
        <div class="sb-section">
            <h3>⟳ Mutual Friends</h3>
            <?php foreach ($mutuals as $u): ?>
                <div class="user-chip">
                    <div class="avatar" style="background:linear-gradient(135deg,#50c878,#1a8a8a)"><?= strtoupper(substr($u['User_name'],0,1)) ?></div>
                    <span class="chip-name"><?= htmlspecialchars($u['User_name']) ?></span>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="follow">
                        <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="chip-btn">+ Follow</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Find people -->
        <div class="sb-section">
            <h3>🔍 Find People</h3>
            <input type="text" id="follow-search" placeholder="Search users…" oninput="filterUsers(this.value)">
            <div id="user-list">
            <?php foreach ($all_users as $u): ?>
                <div class="user-chip" data-name="<?= strtolower(htmlspecialchars($u['User_name'])) ?>">
                    <div class="avatar"><?= strtoupper(substr($u['User_name'],0,1)) ?></div>
                    <span class="chip-name"><?= htmlspecialchars($u['User_name']) ?></span>
                    <?php if (in_array($u['id'], $following_ids)): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="unfollow">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="chip-btn unfollow">Unfollow</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="follow">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="chip-btn">+ Follow</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

    </aside><!-- /sidebar -->

    <!-- ───────── CANVAS PANEL ───────── -->
    <div id="canvas-panel">
        <div id="canvas-inner">

            <?php foreach ($widgets as $w): ?>
            <div class="widget"
                 id="widget-<?= $w['id'] ?>"
                 data-id="<?= $w['id'] ?>"
                 style="left:<?= (int)$w['pos_x'] ?>px;
                         top:<?= (int)$w['pos_y'] ?>px;
                         width:<?= (int)$w['width'] ?>px;
                         height:<?= (int)$w['height'] ?>px;
                         z-index:<?= (int)$w['z_index'] ?>">
                <div class="widget-handle">
                    <span>⠿ <?= $w['type'] === 'image' ? '🖼 image' : '📝 text' ?></span>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="delete_widget">
                        <input type="hidden" name="widget_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="widget-del" title="Delete">✕</button>
                    </form>
                </div>
                <div class="widget-body">
                    <?php if ($w['type'] === 'image' && $w['img_url']): ?>
                        <img src="<?= htmlspecialchars($w['img_url']) ?>" alt="widget image">
                    <?php endif; ?>
                    <?php if ($w['content']): ?>
                        <div><?= nl2br(htmlspecialchars($w['content'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="resize-handle" data-id="<?= $w['id'] ?>"></div>
            </div>
            <?php endforeach; ?>

        </div>
    </div><!-- /canvas-panel -->

</div><!-- /main-layout -->

<!-- ═══════════════ ADD WIDGET BUTTON ═══════════════ -->
<div id="widget-toolbar">
    <!-- popup form -->
    <div id="widget-form-popup">
        <h3>✦ Add Widget</h3>
        <form method="POST" id="add-widget-form">
            <input type="hidden" name="action" value="add_widget">
            <input type="hidden" name="pos_x" id="new-pos-x" value="40">
            <input type="hidden" name="pos_y" id="new-pos-y" value="40">

            <label>Type</label>
            <select name="widget_type" id="widget-type-select" onchange="toggleWidgetFields(this.value)">
                <option value="text">Text / Note</option>
                <option value="image">Image</option>
            </select>

            <div id="text-fields">
                <label>Content</label>
                <textarea name="widget_content" placeholder="Write anything…"></textarea>
            </div>

            <div id="image-fields">
                <label>Image URL</label>
                <input type="url" name="widget_img_url" placeholder="https://…">
                <label>Caption (optional)</label>
                <input type="text" name="widget_content" placeholder="Caption…">
            </div>

            <div class="form-row">
                <div>
                    <label>Width (px)</label>
                    <input type="number" name="widget_w" value="220" min="80">
                </div>
                <div>
                    <label>Height (px)</label>
                    <input type="number" name="widget_h" value="160" min="40">
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="popup-btn primary">Add to Canvas</button>
                <button type="button" class="popup-btn cancel" onclick="closePopup()">Cancel</button>
            </div>
        </form>
    </div>

    <button id="add-widget-btn" onclick="togglePopup()" title="Add widget">+</button>
</div>

<script>
// ── User search filter ────────────────────────
function filterUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#user-list .user-chip').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
}

// ── Popup toggle ─────────────────────────────
const popup = document.getElementById('widget-form-popup');
let popupOpen = false;
function togglePopup() {
    popupOpen = !popupOpen;
    popup.style.display = popupOpen ? 'block' : 'none';
    if (popupOpen) toggleWidgetFields('text');
}
function closePopup() { popupOpen = false; popup.style.display = 'none'; }

// type-conditional fields
function toggleWidgetFields(val) {
    document.getElementById('text-fields').style.display  = val === 'text'  ? 'block' : 'none';
    document.getElementById('image-fields').style.display = val === 'image' ? 'block' : 'none';
}

// Place new widget near last click on canvas
const canvasPanel = document.getElementById('canvas-panel');
canvasPanel.addEventListener('dblclick', function(e) {
    const rect = document.getElementById('canvas-inner').getBoundingClientRect();
    const scrollLeft = canvasPanel.scrollLeft;
    const scrollTop  = canvasPanel.scrollTop;
    const x = e.clientX - rect.left + scrollLeft - canvasPanel.getBoundingClientRect().left;
    const y = e.clientY - rect.top  + scrollTop  - canvasPanel.getBoundingClientRect().top;
    document.getElementById('new-pos-x').value = Math.max(0, Math.round(x));
    document.getElementById('new-pos-y').value = Math.max(0, Math.round(y));
    if (!popupOpen) togglePopup();
});

// ── Drag-to-move ──────────────────────────────
let dragging = null, dragOffX = 0, dragOffY = 0;

document.querySelectorAll('.widget').forEach(w => {
    const handle = w.querySelector('.widget-handle');
    handle.addEventListener('mousedown', e => {
        if (e.target.closest('form, button')) return; // don't drag on delete btn
        dragging  = w;
        const rect = w.getBoundingClientRect();
        const panelRect = canvasPanel.getBoundingClientRect();
        dragOffX = e.clientX - rect.left;
        dragOffY = e.clientY - rect.top;
        w.classList.add('dragging');
        w.style.zIndex = 999;
        e.preventDefault();
    });
});

document.addEventListener('mousemove', e => {
    if (!dragging) return;
    const panelRect  = canvasPanel.getBoundingClientRect();
    const scrollLeft = canvasPanel.scrollLeft;
    const scrollTop  = canvasPanel.scrollTop;
    const x = e.clientX - panelRect.left + scrollLeft - dragOffX;
    const y = e.clientY - panelRect.top  + scrollTop  - dragOffY;
    dragging.style.left = Math.max(0, x) + 'px';
    dragging.style.top  = Math.max(0, y) + 'px';
});

document.addEventListener('mouseup', e => {
    if (!dragging) return;
    dragging.classList.remove('dragging');
    const wid = dragging.dataset.id;
    const x   = parseInt(dragging.style.left);
    const y   = parseInt(dragging.style.top);
    dragging.style.zIndex = '';
    dragging = null;
    // Persist via AJAX
    fetch('dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=move_widget&widget_id=${wid}&pos_x=${x}&pos_y=${y}`
    }).catch(() => {});
});

// ── Resize ────────────────────────────────────
let resizing = null, resStartX = 0, resStartY = 0, resStartW = 0, resStartH = 0;

document.querySelectorAll('.resize-handle').forEach(rh => {
    rh.addEventListener('mousedown', e => {
        resizing  = document.getElementById('widget-' + rh.dataset.id);
        resStartX = e.clientX;
        resStartY = e.clientY;
        resStartW = resizing.offsetWidth;
        resStartH = resizing.offsetHeight;
        e.preventDefault();
        e.stopPropagation();
    });
});

document.addEventListener('mousemove', e => {
    if (!resizing) return;
    const dw = e.clientX - resStartX;
    const dh = e.clientY - resStartY;
    resizing.style.width  = Math.max(80,  resStartW + dw) + 'px';
    resizing.style.height = Math.max(40, resStartH + dh) + 'px';
});

document.addEventListener('mouseup', () => { resizing = null; });

// ── Bring widget to front on click ────────────
let topZ = 10;
document.querySelectorAll('.widget').forEach(w => {
    w.addEventListener('mousedown', () => {
        topZ++;
        w.style.zIndex = topZ;
    });
});
</script>
</body>
</html>
