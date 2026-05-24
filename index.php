<?php
// ---------- Friendly setup-error page ----------
function showSetupError(string $title, string $message, string $detail = '', string $command = '') {
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?> &mdash; Setup required</title>
        <link rel="icon" type="image/svg+xml" href="favicon.svg">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            body { background: #f5f7fb; }
            .hero { background: linear-gradient(135deg, #dc2626, #f97316); color: #fff; }
            .err-card { max-width: 720px; margin: 0 auto; }
            .err-cmd {
                background: #1e293b; color: #f1f5f9;
                padding: .85rem 1rem; border-radius: 6px;
                font-family: ui-monospace, Menlo, monospace;
                font-size: .9rem; overflow-x: auto;
                white-space: pre;
            }
        </style>
    </head>
    <body>
        <div class="hero py-4 mb-4 shadow-sm">
            <div class="container">
                <h1 class="h3 mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($title) ?></h1>
                <p class="mb-0 opacity-75">Setup required before the playground can run.</p>
            </div>
        </div>
        <div class="container pb-5">
            <div class="card shadow-sm err-card">
                <div class="card-body p-4">
                    <p class="fs-5 mb-3"><?= $message ?></p>
                    <?php if ($detail): ?>
                        <p class="text-muted mb-2"><?= $detail ?></p>
                    <?php endif; ?>
                    <?php if ($command): ?>
                        <div class="err-cmd"><?= htmlspecialchars($command) ?></div>
                        <p class="text-muted small mt-2 mb-0">Or open <a href="schema.html">schema.html</a> &rarr; click <strong>Copy</strong> &rarr; paste into phpMyAdmin's SQL tab.</p>
                    <?php endif; ?>
                    <hr class="my-4">
                    <a href="" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Retry</a>
                    <a href="schema.html" class="btn btn-outline-secondary"><i class="bi bi-database me-1"></i>View Schema</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------- DB connection (WAMP defaults) ----------
try {
    $pdo = new PDO('mysql:host=localhost;dbname=hierarchical_data;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'Unknown database') !== false) {
        showSetupError(
            'Database not found',
            'The database <code>hierarchical_data</code> does not exist on your MySQL server yet.',
            'Import <code>schema.sql</code> to create it:',
            'mysql -u root < schema.sql'
        );
    }
    if (stripos($msg, 'Access denied') !== false) {
        showSetupError(
            'Database access denied',
            'MySQL refused the credentials in <code>index.php</code>.',
            'Edit the connection string near the top of <code>index.php</code> with the correct user / password.',
            'Detail: ' . htmlspecialchars($msg)
        );
    }
    showSetupError(
        'Cannot connect to MySQL',
        'Could not reach the MySQL server at <code>localhost</code>.',
        'Make sure WAMP (or your MySQL service) is running, then click <strong>Retry</strong>.',
        'Detail: ' . htmlspecialchars($msg)
    );
}

// ---------- Verify required tables exist ----------
$missing = [];
foreach (['department', 'department_ns', 'employee'] as $t) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$t]);
    if (!$stmt->fetch()) $missing[] = $t;
}
if ($missing) {
    showSetupError(
        'Tables not found',
        'The database <code>hierarchical_data</code> exists, but is missing tables: <code>' . implode('</code>, <code>', $missing) . '</code>.',
        'Run <code>schema.sql</code> against the <code>hierarchical_data</code> database:',
        'mysql -u root hierarchical_data < schema.sql'
    );
}

// ---------- Rebuild nested set from adjacency list ----------
function rebuildNestedSet(PDO $pdo) {
    $rows = $pdo->query("SELECT id, name, parent_id FROM department ORDER BY id")->fetchAll();
    $kids = [];  $root = null;
    foreach ($rows as $r) {
        if ($r['parent_id'] === null) $root = $r;
        else $kids[$r['parent_id']][] = $r;
    }
    $pdo->exec("DELETE FROM department_ns");
    if (!$root) return;
    $counter = 1;
    $insert  = $pdo->prepare("INSERT INTO department_ns (id, name, lft, rgt) VALUES (?, ?, ?, ?)");
    $walk    = function ($n) use (&$walk, &$counter, $kids, $insert) {
        $lft = $counter++;
        foreach ($kids[$n['id']] ?? [] as $c) $walk($c);
        $insert->execute([$n['id'], $n['name'], $lft, $counter++]);
    };
    $walk($root);
}

// ---------- Handle add / delete (POST → mutate → rebuild → redirect) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' && trim($_POST['name'] ?? '') !== '') {
        $pdo->prepare("INSERT INTO department (name, parent_id) VALUES (?, ?)")
            ->execute([trim($_POST['name']), (int)$_POST['parent_id']]);
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        // Collect node + all descendants
        $ids = [(int)$_POST['id']];
        $queue = $ids;
        $stmt = $pdo->prepare("SELECT id FROM department WHERE parent_id = ?");
        while ($queue) {
            $stmt->execute([array_shift($queue)]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) { $ids[] = $cid; $queue[] = $cid; }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM employee   WHERE department_id IN ($ph)")->execute($ids);
        $pdo->prepare("DELETE FROM department WHERE id            IN ($ph)")->execute($ids);
    }
    rebuildNestedSet($pdo);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ---------- Queries ----------

// Adjacency list — fetch rows and build a nested tree
$rows = $pdo->query("SELECT id, name, parent_id FROM department ORDER BY id")->fetchAll();
$children = [];
$root = null;
foreach ($rows as $r) {
    if ($r['parent_id'] === null) $root = $r;
    else $children[$r['parent_id']][] = $r;
}
// lft / rgt lookup from the nested-set table (kept in sync by rebuildNestedSet)
$bounds = [];
foreach ($pdo->query("SELECT id, lft, rgt FROM department_ns") as $b) {
    $bounds[$b['id']] = ['lft' => $b['lft'], 'rgt' => $b['rgt']];
}
function renderOrgNode($node, $children, $bounds) {
    $id   = (int)$node['id'];
    $name = htmlspecialchars($node['name'], ENT_QUOTES);
    $isRoot = is_null($node['parent_id']);
    $lft  = $bounds[$id]['lft'] ?? '–';
    $rgt  = $bounds[$id]['rgt'] ?? '–';
    $html  = '<li><div class="org-node-wrap">';
    $html .= '<span class="org-node">';
    $html .=     '<span class="org-name">' . $name . '</span>';
    $html .=     '<span class="org-bounds"><span class="b-lft">' . $lft . '</span><span class="b-rgt">' . $rgt . '</span></span>';
    $html .= '</span>';
    $html .= '<button type="button" class="org-btn org-btn-add" data-action="add" data-id="' . $id . '" data-name="' . $name . '" title="Add sub-category">+</button>';
    if (!$isRoot) {
        $html .= '<button type="button" class="org-btn org-btn-del" data-action="delete" data-id="' . $id . '" data-name="' . $name . '" title="Delete">&times;</button>';
    }
    $html .= '</div>';
    if (!empty($children[$id])) {
        $html .= '<ul>';
        foreach ($children[$id] as $c) $html .= renderOrgNode($c, $children, $bounds);
        $html .= '</ul>';
    }
    return $html . '</li>';
}

// Nested set — fetch ordered by lft, then derive parent-child via a stack
$nsRows = $pdo->query("SELECT id, name, lft, rgt FROM department_ns ORDER BY lft")->fetchAll();
$nsChildren = [];
$nsRoot = null;
$stack = [];
foreach ($nsRows as $n) {
    while (!empty($stack) && end($stack)['rgt'] < $n['lft']) array_pop($stack);
    if (empty($stack)) $nsRoot = $n;
    else $nsChildren[end($stack)['id']][] = $n;
    $stack[] = $n;
}
function renderAsciiTree($node, $children, $prefix = '', $isLast = true, $isRoot = true, $depth = 0) {
    $name = '<span class="ns-name">' . htmlspecialchars($node['name']) . '</span>'
          . ' <span class="ns-meta">[' . $node['lft'] . ', ' . $node['rgt'] . '] &middot; depth ' . $depth . '</span>';
    $out  = ($isRoot ? '' : $prefix . ($isLast ? '└── ' : '├── ')) . $name . "\n";
    $kids = $children[$node['id']] ?? [];
    $last = count($kids) - 1;
    foreach ($kids as $i => $k) {
        $childPrefix = $isRoot ? '' : $prefix . ($isLast ? '    ' : '│   ');
        $out .= renderAsciiTree($k, $children, $childPrefix, $i === $last, false, $depth + 1);
    }
    return $out;
}


?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hierarchical Tree Structures in MySQL</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
    body { background: #f5f7fb; }
    .tree-row { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .badge-num { font-variant-numeric: tabular-nums; }
    .hero { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: #fff; }

    /* --- Org chart (pure CSS) --- */
    .orgchart-wrap { overflow-x: auto; padding: 2rem 1rem; text-align: center; }
    .orgchart, .orgchart ul { list-style: none; margin: 0; padding: 0; position: relative; }
    .orgchart { display: inline-flex; justify-content: center; min-width: 100%; }
    .orgchart ul { display: flex; justify-content: center; padding-top: 28px; }
    .orgchart li { position: relative; padding: 28px 14px 0; text-align: center; }
    .orgchart li::before, .orgchart li::after {
        content: ''; position: absolute; top: 0; right: 50%;
        width: 50%; height: 28px; border-top: 2px solid #cbd5e1;
    }
    .orgchart li::after { right: auto; left: 50%; border-left: 2px solid #cbd5e1; }
    .orgchart li:first-child::before, .orgchart li:last-child::after { border: 0 none; }
    .orgchart li:last-child::before { border-right: 2px solid #cbd5e1; border-radius: 0 6px 0 0; }
    .orgchart li:first-child::after { border-radius: 6px 0 0 0; }
    /* only-child: hide horizontal, show single vertical line */
    .orgchart li:only-child::before { display: none; }
    .orgchart li:only-child::after {
        border-top: 0; border-left: 2px solid #cbd5e1;
        width: 0; left: 50%; right: auto;
    }
    /* vertical line from each parent box down to its children's connector */
    .orgchart ul::before {
        content: ''; position: absolute; top: 0; left: 50%;
        width: 0; height: 28px; border-left: 2px solid #cbd5e1;
    }
    /* root has no parent above it */
    .orgchart > li { padding-top: 0; }
    .orgchart > li::before, .orgchart > li::after { display: none; }
    .org-node {
        display: inline-block; padding: 8px 16px 6px;
        min-width: 110px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff; font-weight: 500; border-radius: 8px;
        box-shadow: 0 4px 10px rgba(99, 102, 241, .25);
        white-space: nowrap; transition: transform .15s, box-shadow .15s;
    }
    .org-node:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(99, 102, 241, .4); }
    .org-name { display: block; }
    .org-bounds {
        display: flex; justify-content: space-between;
        font-size: .7rem; font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: rgba(255, 255, 255, .8);
        margin-top: 2px;
    }
    .b-lft, .b-rgt {
        background: rgba(0, 0, 0, .18);
        padding: 1px 6px; border-radius: 4px;
    }
    .org-node-wrap { position: relative; display: inline-block; }
    .org-btn {
        position: absolute; top: -9px;
        width: 22px; height: 22px;
        border: none; border-radius: 50%;
        background: #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,.2);
        font-size: 16px; line-height: 1; font-weight: 700;
        padding: 0; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity .15s, transform .15s;
    }
    .org-node-wrap:hover .org-btn { opacity: 1; }
    .org-btn:hover { transform: scale(1.15); }
    .org-btn-add { right: -9px; color: #16a34a; }
    .org-btn-del { left:  -9px; color: #dc2626; }

    /* --- Nested-set ASCII tree --- */
    .ns-tree {
        font-family: ui-monospace, SFMono-Regular, "Cascadia Code", Menlo, monospace;
        font-size: 1rem;
        line-height: 1.8;
        color: #334155;
        background: #fff;
        padding: 1.75rem 2rem;
        margin: 0;
        border-radius: 0 0 .375rem .375rem;
        white-space: pre;
        overflow-x: auto;
    }
    .ns-name { color: #1e293b; font-weight: 600; }
    .ns-meta { color: #94a3b8; font-size: .85em; font-weight: 400; }
</style>
</head>
<body>

<div class="hero py-4 mb-4 shadow-sm">
    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-diagram-3-fill me-2"></i>Hierarchical Tree Structures in MySQL</h1>
            <p class="mb-0 opacity-75">Adjacency list vs. nested set — explore the same org chart two ways.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="schema.html" class="btn btn-light">
                <i class="bi bi-database me-1"></i>Schema
            </a>
            <a href="docs.html" class="btn btn-light">
                <i class="bi bi-book me-1"></i>Docs
            </a>
        </div>
    </div>
</div>

<div class="container pb-5">
    <ul class="nav nav-pills mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#t-adj">Adjacency List</button></li>
        <li class="nav-item"><button class="nav-link"        data-bs-toggle="pill" data-bs-target="#t-ns">Nested Set</button></li>
    </ul>

    <div class="tab-content">

        <!-- Adjacency list — visual org chart -->
        <div class="tab-pane fade show active" id="t-adj">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <strong>Org chart</strong>
                    <span class="text-muted small">— rendered from <code>parent_id</code> pointers</span>
                </div>
                <div class="orgchart-wrap">
                    <ul class="orgchart"><?= renderOrgNode($root, $children, $bounds) ?></ul>
                </div>
            </div>
        </div>

        <!-- Nested set — ASCII tree -->
        <div class="tab-pane fade" id="t-ns">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <strong>Nested Set</strong>
                    <span class="text-muted small">— tree derived from <code>lft</code> / <code>rgt</code> ranges, with depth</span>
                </div>
                <pre class="ns-tree mb-0"><?= renderAsciiTree($nsRoot, $nsChildren) ?></pre>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function postForm(data) {
    const f = document.createElement('form');
    f.method = 'POST';
    for (const k in data) {
        const i = document.createElement('input');
        i.name = k; i.value = data[k]; f.appendChild(i);
    }
    document.body.appendChild(f); f.submit();
}
document.addEventListener('click', e => {
    const btn = e.target.closest('.org-btn');
    if (!btn) return;
    const { id, name, action } = btn.dataset;
    if (action === 'add') {
        const child = prompt(`Add a sub-category under "${name}":`);
        if (child && child.trim()) postForm({ action: 'add', parent_id: id, name: child.trim() });
    } else if (action === 'delete') {
        if (confirm(`Delete "${name}" and all its sub-categories?`)) {
            postForm({ action: 'delete', id });
        }
    }
});
</script>
</body>
</html>
