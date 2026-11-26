<?php
declare(strict_types=1);

/* ============ 1. 数据库初始化 ============ */
/* 服务器默认时区若是 UTC，「今天」和「本月」会错位一天/一月，这里兜底成本地时区 */
if (date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('Asia/Shanghai');
}
$dbFile = __DIR__ . '/orders.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA synchronous = NORMAL");   /* WAL 下足够安全，写入更快 */
    $pdo->exec("PRAGMA busy_timeout = 5000");    /* 并发写时自动等待，而不是立刻报 database is locked */
    $pdo->exec("PRAGMA temp_store = MEMORY");    /* 排序/临时表走内存 */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS batches (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            wholesaler TEXT NOT NULL DEFAULT '',
            odate      TEXT NOT NULL DEFAULT '',
            note       TEXT NOT NULL DEFAULT '',
            flag       INTEGER NOT NULL DEFAULT 0,
            flag_note  TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            name     TEXT NOT NULL,
            spec     TEXT NOT NULL DEFAULT '',
            unit     TEXT NOT NULL DEFAULT '',
            qty      REAL NOT NULL DEFAULT 0,
            price    REAL NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_items_batch ON items(batch_id)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wholesalers (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL DEFAULT '',
            contact    TEXT NOT NULL DEFAULT '',
            phone      TEXT NOT NULL DEFAULT '',
            note       TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_wholesalers_name ON wholesalers(name)");
    /* 列表按日期倒序、按批发商筛选；统计按月区间筛选 —— 补上索引避免全表扫描 */
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_date ON batches(odate, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batches_wh ON batches(wholesaler, odate)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_items_name ON items(name)");

    /* 每次启动顺手把批发商名的首尾空白清一遍（有可疑数据才真的动表），
       让「档案里的名字」和「进货单里存的名字」始终是同一个口径 */
    normalizeWholesalerData($pdo);

    /* 老库补列：规格。CREATE TABLE IF NOT EXISTS 不会给已有表加字段，
       所以每次启动都探一次表结构，缺了才 ALTER，避免报 duplicate column */
    $hasSpec = false;
    foreach ($pdo->query("PRAGMA table_info(items)")->fetchAll() as $c) {
        if (($c['name'] ?? '') === 'spec') { $hasSpec = true; break; }
    }
    if (!$hasSpec) {
        $pdo->exec("ALTER TABLE items ADD COLUMN spec TEXT NOT NULL DEFAULT ''");
    }

    /* 老库补列：待复核标记 + 标记说明。
       手写单看不清、补录旧单时先勾一下，事后能按标记集中筛出来复核 */
    $batchCols = array_column($pdo->query("PRAGMA table_info(batches)")->fetchAll(), 'name');
    if (!in_array('flag', $batchCols, true)) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN flag INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('flag_note', $batchCols, true)) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN flag_note TEXT NOT NULL DEFAULT ''");
    }
} catch (Throwable $e) {
    exit('数据库初始化失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/* ============ 2. 工具函数 ============ */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function highlight($text, $keyword) {
    if ($keyword === '') return h($text);
    $text = h($text);
    $kw = h($keyword);
    /* 极端长的关键词可能触发回溯上限让 preg_replace 返回 null，兜底回原文 */
    $out = preg_replace('/(' . preg_quote($kw, '/') . ')/iu', '<mark class="hl">$1</mark>', $text);
    return $out === null ? $text : $out;
}

function fmtNum($v): string {
    $s = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/* 单价最多 4 位小数、最少 2 位：整数价显示 45.60，除不尽的显示 2.0833。
   和前端 formatPrice 保持同一口径，避免存 4 位、显示 4 位对不上 */
function fmtPrice($v): string {
    $s = rtrim(number_format(round((float)$v, 4), 4, '.', ''), '0');
    $dot = strpos($s, '.');
    $frac = $dot === false ? '' : substr($s, $dot + 1);
    if (strlen($frac) < 2) $s .= str_repeat('0', 2 - strlen($frac));
    return $s;
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');                 /* 数据接口不缓存，避免拿到旧快照 */
    header('X-Content-Type-Options: nosniff');

    /* 非法 UTF-8 字节会让 json_encode 直接返回 false，前端只会拿到空白响应；
       JSON_INVALID_UTF8_SUBSTITUTE 把它换成一个替代字符，至少还能解析 */
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $json  = json_encode($data, $flags);
    if ($json === false) {
        $json = json_encode(['ok' => false, 'msg' => '数据编码失败'], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

/* 把 2026/1/5、2026.1.5、2026-01-05 归一化成 2026-01-05；
   不存在的日期（2 月 31 日、13 月、0 日等）一律返回 null */
function normalizeDate(string $s): ?string {    $s = trim(preg_replace('#[/.\s]#', '-', $s));
    if ($s === '' || !preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) return null;

    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if ($y < 1900 || $y > 2100) return null;      /* 年份超出合理范围 */
    if (!checkdate($mo, $d, $y)) return null;     /* 含闰年判断，2 月 29 日只在闰年成立 */

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/* 读 JSON 请求体：解析失败或不是对象就返回 null，调用处统一 400，
   省掉每个 POST 接口里那三行 decode + is_array 判断 */
function bodyJson(): ?array {
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : null;
}

/* 取查询参数：去首尾空白并截断到 $max 个字（超长关键词会拖慢 SQL 与正则） */
function qParam(string $key, int $max = 60): string {
    return mb_substr(trim((string)($_GET[$key] ?? '')), 0, $max);
}

function getStats(PDO $pdo): array {
    /* 四条查询合成两条：总量一条、本月一条；本月改用日期区间比较，能走 idx_batches_date */
    $tot = $pdo->query("SELECT
                            (SELECT COUNT(*) FROM batches) AS c,
                            (SELECT COUNT(*) FROM batches WHERE flag = 1) AS f,
                            (SELECT COALESCE(SUM(qty * price), 0) FROM items) AS s")->fetch();

    $mStart = date('Y-m-01');
    $mEnd   = date('Y-m-01', strtotime($mStart . ' +1 month'));

    $stmt = $pdo->prepare("SELECT
                              (SELECT COUNT(*) FROM batches WHERE odate >= :s1 AND odate < :e1) AS c,
                              (SELECT COALESCE(SUM(i.qty * i.price), 0)
                                 FROM items i JOIN batches b ON b.id = i.batch_id
                                WHERE b.odate >= :s2 AND b.odate < :e2) AS s");
    $stmt->execute([':s1' => $mStart, ':e1' => $mEnd, ':s2' => $mStart, ':e2' => $mEnd]);
    $mon = $stmt->fetch();

    return [
        'total_count'  => (int)$tot['c'],
        'flagged_count' => (int)$tot['f'],
        'total_amount' => number_format((float)$tot['s'], 2),
        'month_count'  => (int)$mon['c'],
        'month_amount' => number_format((float)$mon['s'], 2),
    ];
}

/* 批发商名的统一口径：只去首尾空白（含半角空格、全角空格 U+3000、不换行空格 U+00A0、制表 / 换行），
   名字中间的字不动。档案表、进货单表、保存入库、统计 JOIN 全走这一套 ——
   否则「档案里 trim 过、进货单里带空格」时 JOIN 对不上，那家的单数和金额就会漏算。 */
function normalizeWholesalerName(string $s): string {
    $s = (string)preg_replace('/^[\s\x{00A0}\x{3000}]+|[\s\x{00A0}\x{3000}]+$/u', '', (string)$s);
    return $s;
}

/* 老数据清洗（幂等）：首尾带空白的批发商名就地规范化，让统计 JOIN 重新对齐。
   绝大多数行都是干净的，逐行比一下就走；同名撞车的行跳过（不因为清洗把数据搞成重复）。 */
function normalizeWholesalerData(PDO $pdo): void {
    foreach ([['batches', 'wholesaler'], ['wholesalers', 'name']] as $t) {
        [$table, $col] = $t;
        try {
            /* 先用一条 SQL 探一下有没有可疑行（首尾半角空格 / 内部含全角空格或 NBSP），
               干净的话直接返回，不必把整表拉进 PHP 逐行比对 */
            $probe = (int)$pdo->query("SELECT COUNT(*) AS c FROM {$table}
                                       WHERE {$col} <> '' AND ({$col} <> TRIM({$col})
                                          OR {$col} LIKE '%　%' OR {$col} LIKE '%' || char(160) || '%')")->fetch()['c'];
            if ($probe === 0) continue;
            $rows = $pdo->query("SELECT id, {$col} AS v FROM {$table} WHERE {$col} <> ''")->fetchAll();
        } catch (Throwable $e) {
            continue;
        }
        $upd = null;
        foreach ($rows as $r) {
            $raw = (string)$r['v'];
            $val = normalizeWholesalerName($raw);
            if ($val === $raw || $val === '') continue;
            try {
                if ($upd === null) {
                    $upd = $pdo->prepare("UPDATE {$table} SET {$col} = :v WHERE id = :id");
                }
                $upd->execute([':v' => $val, ':id' => (int)$r['id']]);
            } catch (Throwable $e) {
                /* 撞上同名唯一索引就跳过这一行，保持原样，不影响其它行 */
            }
        }
    }
}

/* 首次使用（批发商表为空）时，把历史进货单里出现过的批发商名导入进来；
   之后只在新增进货单时增量补充，删除过的不会自动“复活” */
function syncWholesalers(PDO $pdo): void {
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) AS c FROM wholesalers")->fetch()['c'];
        if ($cnt > 0) return;
        $pdo->exec("INSERT OR IGNORE INTO wholesalers (name, created_at)
                    SELECT DISTINCT TRIM(b.wholesaler), '' FROM batches b
                    WHERE TRIM(b.wholesaler) <> ''");
    } catch (Throwable $e) {
        /* 同步失败不影响主流程 */
    }
}

/* 批发商列表（附各自的进货单数与累计金额）
   原来每家批发商跑两条相关子查询 = 2N 次扫描，这里合成一条 JOIN + 聚合 */
function getWholesalers(PDO $pdo): array {
    $sql = "SELECT w.id, w.name, w.contact, w.phone, w.note,
                   COUNT(DISTINCT b.id) AS orders,
                   COALESCE(SUM(i.qty * i.price), 0) AS amount
            FROM wholesalers w
            LEFT JOIN batches b ON b.wholesaler = w.name
            LEFT JOIN items   i ON i.batch_id   = b.id
            WHERE w.name <> ''
            GROUP BY w.id
            ORDER BY w.id ASC";
    $rows = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $rows[] = [
            'id'      => (int)$r['id'],
            'name'    => (string)$r['name'],
            'contact' => (string)$r['contact'],
            'phone'   => (string)$r['phone'],
            'note'    => (string)$r['note'],
            'orders'  => (int)$r['orders'],
            'amount'  => number_format((float)$r['amount'], 2),
        ];
    }
    return $rows;
}

/* 页面首屏只为了填两个下拉框，只需要名字；不必为此把每家的单数和金额都算一遍 */
function getWholesalerNames(PDO $pdo): array {
    $rows = [];
    foreach ($pdo->query("SELECT name FROM wholesalers WHERE name <> '' ORDER BY id ASC")->fetchAll() as $r) {
        $rows[] = ['name' => (string)$r['name']];
    }
    return $rows;
}

/* 某个批发商的商品清单（按商品名聚合它的进货明细） */
function getWholesalerItems(PDO $pdo, string $name): array {
    $sql = "SELECT i.name AS pname,
                   GROUP_CONCAT(DISTINCT i.unit) AS units,
                   COUNT(DISTINCT i.batch_id) AS cnt,
                   SUM(i.qty) AS qty,
                   SUM(i.qty * i.price) AS amount,
                   MAX(b.odate) AS last_date
            FROM items i JOIN batches b ON b.id = i.batch_id
            WHERE b.wholesaler = :n
            GROUP BY i.name
            ORDER BY amount DESC, pname ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':n' => $name]);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'name'      => (string)$r['pname'],
            'units'     => (string)($r['units'] ?? ''),
            'cnt'       => (int)$r['cnt'],
            'qty'       => fmtNum($r['qty'] ?? 0),
            'amount'    => number_format((float)($r['amount'] ?? 0), 2),
            'last_date' => (string)($r['last_date'] ?? ''),
        ];
    }
    return $rows;
}

function buildPager(int $page, int $totalPages, int $totalRows, int $perPage): string {
    if ($totalRows === 0) return '';
    
    $html  = '<div class="pager">';
    $html .= '<div class="pager-nav">';
    $html .= '<button type="button" class="page-btn" data-page="' . max(1, $page - 1) . '"'
           . ($page <= 1 ? ' disabled' : '') . '>上一页</button>';

    $pages = [];
    if ($totalPages <= 7) {
        for ($i = 1; $i <= $totalPages; $i++) $pages[] = $i;
    } else {
        $pages[] = 1;
        $start = max(2, $page - 1);
        $end   = min($totalPages - 1, $page + 1);
        if ($start > 2) $pages[] = '...';
        for ($i = $start; $i <= $end; $i++) $pages[] = $i;
        if ($end < $totalPages - 1) $pages[] = '...';
        $pages[] = $totalPages;
    }

    foreach ($pages as $p) {
        if ($p === '...') {
            $html .= '<span class="page-ellipsis">…</span>';
        } else {
            $active = ((int)$p === $page) ? ' active' : '';
            $html .= '<button type="button" class="page-btn' . $active . '" data-page="' . (int)$p . '">' . (int)$p . '</button>';
        }
    }

    $html .= '<button type="button" class="page-btn" data-page="' . min($totalPages, $page + 1) . '"'
           . ($page >= $totalPages ? ' disabled' : '') . '>下一页</button>';
    $html .= '</div>';

    /* 右侧元信息：总数 + 每页条数（下拉框在分页区，不再挤在搜索栏） */
    $html .= '<div class="pager-meta">';
    $html .= '<span class="page-info">共 ' . $totalRows . ' 条</span>';
    $html .= '<span class="pager-per">每页 <select class="per-select" data-current="' . $perPage . '">';
    $opts  = [10, 20, 50, 100];
    if (!in_array($perPage, $opts, true)) $opts[] = $perPage;
    sort($opts);
    foreach ($opts as $o) {
        $html .= '<option value="' . (int)$o . '"' . ($o === $perPage ? ' selected' : '') . '>' . (int)$o . '</option>';
    }
    $html .= '</select> 条</span>';
    $html .= '</div>';

    $html .= '</div>';
    return $html;
}

function renderList(PDO $pdo, string $q, string $date, string $shop = '', int $page = 1, int $perPage = 10, bool $flagOnly = false): string {
    $perPage = max(1, min(200, $perPage));   /* 兜底：每页最多 200 条 */
    $cond   = [];
    $params = [];

    /* 搜索：关键词（商品名 / 规格 / 单位 / 批发商 / 备注 / 标记说明） */
    if ($q !== '') {
        $like = '%' . $q . '%';
        $cond[] = "(b.wholesaler LIKE :q1 OR b.note LIKE :q2 OR b.flag_note LIKE :q6
                    OR EXISTS (SELECT 1 FROM items i WHERE i.batch_id = b.id
                               AND (i.name LIKE :q3 OR i.spec LIKE :q4 OR i.unit LIKE :q5)))";
        $params += [':q1'=>$like, ':q2'=>$like, ':q3'=>$like, ':q4'=>$like, ':q5'=>$like, ':q6'=>$like];
    }

    /* 筛选：只看待复核 */
    if ($flagOnly) {
        $cond[] = "b.flag = 1";
    }

    /* 筛选：日期 */
    if ($date !== '') {
        $cond[] = "b.odate = :date";
        $params[':date'] = $date;
    }

    /* 筛选：批发商 */
    if ($shop !== '') {
        $cond[] = "b.wholesaler = :shop";
        $params[':shop'] = $shop;
    }

    $where = $cond ? (' WHERE ' . implode(' AND ', $cond)) : '';

    $cntStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM batches b" . $where);
    $cntStmt->execute($params);
    $totalRows  = (int)$cntStmt->fetch()['c'];
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page < 1) $page = 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT b.* FROM batches b" . $where . " ORDER BY b.odate DESC, b.id DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $batches = $stmt->fetchAll();

    if (!$batches) {
        $hasFilter = ($q !== '' || $date !== '' || $shop !== '' || $flagOnly);
        return '<div class="empty">'
            . ($hasFilter ? '没有找到符合条件的记录 🔍' : '暂无记录，切换到「录入进货单」开始记录吧 ✨')
            . '</div>';
    }

    $ids  = array_column($batches, 'id');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    $items = [];
    foreach ($stmt->fetchAll() as $it) {
        $items[$it['batch_id']][] = $it;
    }

    $html = '';
    foreach ($batches as $b) {
        $list  = $items[$b['id']] ?? [];
        $totalMoney = 0;
        $totalQty = 0;
        $flagOn   = ((int)($b['flag'] ?? 0)) === 1;
        $flagNote = (string)($b['flag_note'] ?? '');
        foreach ($list as $it) {
            $totalMoney += (float)$it['qty'] * (float)$it['price'];
            $totalQty += (float)$it['qty'];
        }

        $html .= '<div class="batch" data-id="' . (int)$b['id'] . '">';
        $html .=   '<div class="batch-head">';
        $html .=     '<div class="batch-info">';
        // 依然输出标准的 YYYY-MM-DD 格式
        $html .=       '<span class="batch-date js-set-date" title="点击筛选此日期">' . ($b['odate'] !== '' ? highlight($b['odate'], $q) : '未填日期') . '</span>';
        $html .=       '<span class="batch-shop js-set-shop" data-shop="' . h($b['wholesaler']) . '" title="点击筛选此批发商">' . ($b['wholesaler'] !== '' ? highlight($b['wholesaler'], $q) : '<em>未填写批发商</em>') . '</span>';
        $html .=       ($flagOn ? '<span class="flag-badge">待复核</span>' : '');
        $html .=     '</div>';
        $html .=     '<div class="batch-right">';
        $html .=       '<button type="button" class="btn-mini js-print" data-id="' . (int)$b['id'] . '">打印</button>';
        $html .=       '<button type="button" class="btn-mini js-edit" data-id="' . (int)$b['id'] . '">编辑</button>';
        $html .=       '<button type="button" class="btn-mini danger js-del" data-id="' . (int)$b['id'] . '">删除</button>';
        $html .=     '</div>';
        $html .=   '</div>';

        $html .=   '<div class="batch-body"><table class="batch-items cols-fit">';
        $html .=     '<thead><tr>'
            . '<th style="width:34px;text-align:center" data-sort="index" title="点击恢复默认顺序">#</th>'
            . '<th data-sort="name">商品名称</th>'
            . '<th data-sort="unit">单位</th>'
            . '<th style="text-align:right" data-sort="qty">数量</th>'
            . '<th style="text-align:right" data-sort="price">单价</th>'
            . '<th style="text-align:right" data-sort="subtotal">小计</th>'
            . '</tr></thead><tbody>';
        
        foreach ($list as $idx => $it) {
            $sub = (float)$it['qty'] * (float)$it['price'];
            $html .= '<tr data-origin-idx="' . $idx . '">'
                . '<td class="idx-cell">' . ($idx + 1) . '</td>'
                . '<td class="name-cell" data-v="' . h($it['name']) . '">' . highlight($it['name'], $q)
                    . ((string)($it['spec'] ?? '') !== ''
                        ? '<span class="sp">' . highlight($it['spec'], $q) . '</span>'
                        : '')
                    . '</td>'
                . '<td>' . ($it['unit'] !== '' ? highlight($it['unit'], $q) : '—') . '</td>'
                . '<td class="num">' . fmtNum($it['qty']) . '</td>'
                . '<td class="num">￥' . fmtPrice($it['price']) . '</td>'
                . '<td class="sub">￥' . number_format($sub, 2) . '</td>'
                . '</tr>';
        }
        $html .=     '</tbody></table></div>';

        $html .=   '<div class="batch-foot">';
        $html .=     '<span class="batch-note">'
            . ($b['note'] !== '' ? '备注：' . highlight($b['note'], $q) : '')
            . ($flagNote !== '' ? '<span class="flag-note">标记：' . highlight($flagNote, $q) . '</span>' : '')
            . '</span>';
        $html .=     '<div class="batch-summary">'
            . '<span class="batch-qty">总数量 <strong>' . fmtNum($totalQty) . '</strong></span>'
            . '<span class="batch-divider">|</span>'
            . '<span class="batch-total">合计金额 <strong>￥' . number_format($totalMoney, 2) . '</strong></span>'
            . '</div>';
        $html .=   '</div>';

        $html .= '</div>';
    }

    $html .= buildPager($page, $totalPages, $totalRows, $perPage);
    return $html;
}

/* ============ 3. Ajax 接口 ============ */
$ajax = $_GET['ajax'] ?? '';

if ($ajax !== '') {
    if ($ajax === 'list') {
        /* 关键词截断到 60 字、每页上限 200 条：避免超长查询和 ?per=999999 把页面拖垮 */
        $q    = qParam('q', 60);
        $date = normalizeDate(trim($_GET['d'] ?? '')) ?? '';   /* 非法日期一律视为未筛选 */
        $shop = qParam('w', 60);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = max(1, min(200, (int)($_GET['per'] ?? 10)));
        $flagOnly = ($_GET['f'] ?? '') === '1';
        echo renderList($pdo, $q, $date, $shop, $page, $per, $flagOnly);
        exit;
    }

    /* 批发商供货商品 + 最新报价 */
    if ($ajax === 'whdetail') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, name, contact, phone, note FROM wholesalers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $w = $stmt->fetch();
        if (!$w) jsonOut(['ok' => false, 'msg' => '批发商不存在'], 404);

        /* 一次取回这家的全部明细，边累加边挑「最新一条」。
           注：试过把聚合下推成 SQL 窗口函数（聚合 + ROW_NUMBER 两条查询），
           实测 3 千行 3ms→4ms、3 万行 29ms→35ms，反而更慢，且依赖 SQLite ≥3.25，
           对本机这体量不划算，维持 PHP 里循环的做法 */
        $stmt = $pdo->prepare("SELECT i.id AS item_id, i.name, i.spec, i.unit, i.qty, i.price,
                                      b.id AS batch_id, b.odate
                               FROM items i JOIN batches b ON b.id = i.batch_id
                               WHERE b.wholesaler = :nm
                               ORDER BY b.odate ASC, b.id ASC, i.id ASC");
        $stmt->execute([':nm' => $w['name']]);
        $rows = $stmt->fetchAll();

        $agg = [];
        $totalQty = 0.0;
        $totalMoney = 0.0;
        foreach ($rows as $r) {
            $nm = trim((string)$r['name']);
            if ($nm === '') continue;
            $price = (float)$r['price'];
            $qty   = (float)$r['qty'];
            $totalQty   += $qty;
            $totalMoney += $qty * $price;

            if (!isset($agg[$nm])) {
                $agg[$nm] = [
                    'name'     => $nm,
                    'spec'     => trim((string)$r['spec']),
                    'unit'     => (string)$r['unit'],
                    'times'    => 0,
                    'qty'      => 0.0,
                    'min'      => $price,
                    'max'      => $price,
                    'price'    => $price,
                    'odate'    => (string)$r['odate'],
                    'batch_id' => (int)$r['batch_id'],
                    'item_id'  => (int)$r['item_id'],
                ];
            }
            $a = &$agg[$nm];
            $a['times']++;
            $a['qty'] += $qty;
            if ($price < $a['min']) $a['min'] = $price;
            if ($price > $a['max']) $a['max'] = $price;

            /* 最新报价：按 (日期, 进货单 id, 明细 id) 取最后一条 */
            $ckNew = [(string)$r['odate'], (int)$r['batch_id'], (int)$r['item_id']];
            $ckOld = [$a['odate'], $a['batch_id'], $a['item_id']];
            if ($ckNew > $ckOld) {
                $a['price']    = $price;
                $a['unit']     = (string)$r['unit'];
                $a['odate']    = (string)$r['odate'];
                $a['batch_id'] = (int)$r['batch_id'];
                $a['item_id']  = (int)$r['item_id'];
                /* 规格跟着最新一条走；最新那条没填规格，就沿用上一次填过的 */
                $sp = trim((string)$r['spec']);
                if ($sp !== '') $a['spec'] = $sp;
            }
            unset($a);
        }

        $products = [];
        foreach ($agg as $a) {
            $products[] = [
                'name'  => $a['name'],
                'spec'  => $a['spec'],
                'unit'  => $a['unit'],
                'times' => (int)$a['times'],
                'qty'   => fmtNum($a['qty']),
                'price' => fmtPrice($a['price']),
                'min'   => fmtPrice($a['min']),
                'max'   => fmtPrice($a['max']),
                'odate' => $a['odate'],
            ];
        }
        /* 最近报价过的商品排前面 */
        usort($products, function ($x, $y) {
            if ($x['odate'] === $y['odate']) return strcmp($x['name'], $y['name']);
            return strcmp($y['odate'], $x['odate']);
        });

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM batches WHERE wholesaler = :nm");
        $stmt->execute([':nm' => $w['name']]);
        $orderCount = (int)$stmt->fetch()['c'];

        jsonOut([
            'ok'         => true,
            'wholesaler' => [
                'id'      => (int)$w['id'],
                'name'    => (string)$w['name'],
                'contact' => (string)$w['contact'],
                'phone'   => (string)$w['phone'],
                'note'    => (string)$w['note'],
            ],
            'orders'     => $orderCount,
            'products'   => $products,
            'sku_count'  => count($products),
            'total_qty'  => fmtNum($totalQty),
            'total_amount' => number_format($totalMoney, 2),
        ]);
    }


    /* 某批发商的历史单价（录入进货单时给单价输入框做建议列表）
       返回 { 商品名: [{ price, odate, unit }, ...] }，按最新进货日期倒序，同价去重 */
    if ($ajax === 'prices') {
        $wname = qParam('w', 60);
        if ($wname === '') jsonOut(['ok' => true, 'wholesaler' => '', 'products' => []]);

        /* 每个商品最多 8 个「不同」的历史单价，按最新进货日期倒序。
           这里刻意保留了「取回后逐行去重」的写法：基准测过窗口函数版本，
           3 千行 3ms→4ms、3 万行 29ms→35ms，不划算 */
        $stmt = $pdo->prepare("SELECT i.name, i.unit, i.price, b.odate
                               FROM items i JOIN batches b ON b.id = i.batch_id
                               WHERE b.wholesaler = :n
                               ORDER BY b.odate DESC, b.id DESC, i.id DESC");
        $stmt->execute([':n' => $wname]);

        $out = [];
        $seen = [];
        $cap = 8;                       /* 每个商品最多给 8 个历史价，够点选又不刷屏 */
        foreach ($stmt->fetchAll() as $r) {
            $nm = trim((string)$r['name']);
            if ($nm === '') continue;

            $price = fmtPrice($r['price']);
            $key = $nm . "\x1f" . $price;
            if (isset($seen[$key])) continue;   /* 同价只留最近一次 */
            $seen[$key] = 1;

            if (!isset($out[$nm])) $out[$nm] = [];
            if (count($out[$nm]) >= $cap) continue;

            $out[$nm][] = [
                'price' => $price,
                'odate' => (string)$r['odate'],
                'unit'  => (string)$r['unit'],
            ];
        }

        jsonOut(['ok' => true, 'wholesaler' => $wname, 'products' => $out]);
    }

    /* 某批发商 + 某商品的历史规格（填写完商品名后给「规格」框做候选）
       返回 { specs: [{ spec, times, odate }] }，按最近进货日期倒序，最多 12 条 */
    if ($ajax === 'specs') {
        $wname = qParam('w', 60);
        $nm    = qParam('n', 120);
        if ($nm === '') jsonOut(['ok' => true, 'specs' => []]);

        $sql    = "SELECT i.spec AS spec, COUNT(*) AS times, MAX(b.odate) AS last_date
                     FROM items i JOIN batches b ON b.id = i.batch_id
                    WHERE i.name = :n AND TRIM(i.spec) <> ''";
        $params = [':n' => $nm];
        if ($wname !== '') {
            $sql .= ' AND b.wholesaler = :w';      /* 有批发商就只看这家的规格 */
            $params[':w'] = $wname;
        }
        $sql .= ' GROUP BY i.spec ORDER BY last_date DESC, times DESC LIMIT 12';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'spec'  => (string)$r['spec'],
                'times' => (int)$r['times'],
                'odate' => (string)$r['last_date'],
            ];
        }
        jsonOut(['ok' => true, 'specs' => $out]);
    }

    /* 商品名联想：输入 2 个字以上按历史记录模糊匹配（空格分词，多个词都要命中）
       scope=wh（默认，且必须有 w）：只找这个批发商进过的商品
       scope=all：找所有批发商，候选上带各自最近一次的批发商名
       q 为空 = 「清点模式」：只报这家有多少种商品；少于 15 种时把整份清单直接给出来（免打字）
       返回字段：count/list，条目 name/unit/price/odate/times/wh */
    if ($ajax === 'names') {
        $q = qParam('q', 40);

        $wname = qParam('w', 60);
        $scope = (string)($_GET['scope'] ?? '');
        if ($scope !== 'all') $scope = 'wh';
        if ($scope === 'wh' && $wname === '') $scope = 'all';   /* 没填批发商就只能全库找 */

        /* 先数这家到底有多少种商品：种类少就可以「点一下就全列出来」 */
        $cntSql = "SELECT COUNT(DISTINCT i.name) FROM items i JOIN batches b ON b.id = i.batch_id"
                  . ($scope === 'wh' ? ' WHERE b.wholesaler = :w' : '');
        $stmt0 = $pdo->prepare($cntSql);
        $stmt0->execute($scope === 'wh' ? [':w' => $wname] : []);
        $total = (int)$stmt0->fetchColumn();

        $WH_LIST_MAX = 15;          /* 少于 15 种 → 免打字，直接列清单 */

        /* 清点模式：种类够少就把整份清单给出去，否则只回数量 */
        if ($q === '') {
            if ($scope !== 'wh' || $total <= 0 || $total >= $WH_LIST_MAX) {
                jsonOut(['ok' => true, 'q' => '', 'scope' => $scope, 'count' => $total, 'list' => []]);
            }
        }

        /* 空格分词：每个词都要命中，等价于 AND，比单个 LIKE 更准也更快缩小范围 */
        $tokens = preg_split('/[\s　]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice($tokens, 0, 4);

        $esc = static fn(string $s): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
        $where = [];
        $params = [];
        foreach ($tokens as $k => $tk) {
            $where[] = "i.name LIKE :p$k ESCAPE '\\'";
            $params[":p$k"] = '%' . $esc($tk) . '%';
        }
        /* 关键词命中之外，再按批发商收口 */
        if ($scope === 'wh') {
            $where[] = 'b.wholesaler = :w';
            $params[':w'] = $wname;
        }
        $where[] = "TRIM(i.name) <> ''";     /* 空商品名不参与候选，也不占清点名额 */

        $sql = "SELECT i.name AS name,
                       COUNT(*) AS times,
                       MAX(b.odate) AS last_date
                FROM items i JOIN batches b ON b.id = i.batch_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY i.name
                ORDER BY times DESC, last_date DESC
                LIMIT 60";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            jsonOut(['ok' => true, 'q' => $q, 'scope' => $scope, 'wholesaler' => $wname,
                     'count' => $total, 'list' => []]);
        }

        /* 前缀命中的排前面（用户敲的开头更像他要找的），其余按次数与最近日期 */
        $head = $tokens ? mb_strtolower($tokens[0]) : '';
        usort($rows, static function ($a, $b) use ($head) {
            $pa = mb_stripos((string)$a['name'], $head) === 0 ? 0 : 1;
            $pb = mb_stripos((string)$b['name'], $head) === 0 ? 0 : 1;
            if ($pa !== $pb) return $pa <=> $pb;
            if ((int)$a['times'] !== (int)$b['times']) return (int)$b['times'] <=> (int)$a['times'];
            return strcmp((string)$b['last_date'], (string)$a['last_date']);
        });

        $rows = array_slice($rows, 0, $q === '' ? 40 : 10);
        $names = array_column($rows, 'name');

        /* 一次性取这些商品各自最近一条明细（拿单位、单价、批发商，少发 N 条查询） */
        $ph = [];
        foreach ($names as $k => $_) $ph[] = ":n$k";
        $detail = [];
        $sql2 = "SELECT i.name AS name, i.spec, i.unit, i.price, b.odate, b.wholesaler
                 FROM items i JOIN batches b ON b.id = i.batch_id
                 WHERE i.name IN (" . implode(',', $ph) . ")";
        $p = [];
        foreach ($names as $k => $n) $p[":n$k"] = $n;
        if ($scope === 'wh') {                       /* 单位/单价也必须是这个批发商的 */
            $sql2 .= ' AND b.wholesaler = :w';
            $p[':w'] = $wname;
        }
        $sql2 .= ' ORDER BY b.odate DESC, b.id DESC, i.id DESC';

        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($p);
        foreach ($stmt2->fetchAll() as $r) {
            $nm = (string)$r['name'];
            if (isset($detail[$nm])) continue;      /* 只要最新那条 */
            $detail[$nm] = [
                'spec'  => (string)$r['spec'],
                'unit'  => (string)$r['unit'],
                'price' => fmtPrice($r['price']),
                'odate' => (string)$r['odate'],
                'wh'    => (string)$r['wholesaler'],
            ];
        }

        $list = [];
        foreach ($rows as $r) {
            $nm = (string)$r['name'];
            $d  = $detail[$nm] ?? ['spec' => '', 'unit' => '', 'price' => '', 'odate' => '', 'wh' => ''];
            $list[] = [
                'name'    => $nm,
                'spec'    => $d['spec'],
                'unit'    => $d['unit'],
                'price'   => $d['price'],
                'odate'   => $d['odate'],
                'times'   => (int)$r['times'],
                'mine'    => ($scope === 'all' && $d['wh'] === $wname && $wname !== ''),
                'wh'      => $d['wh'],
            ];
        }
        jsonOut(['ok' => true, 'q' => $q, 'scope' => $scope, 'wholesaler' => $wname,
                 'count' => $total, 'mode' => $q === '' ? 'list' : 'search', 'list' => $list]);
    }

    if ($ajax === 'whlist') {
        syncWholesalers($pdo);
        $list = getWholesalers($pdo);
        $cnt = count($list);
        $orders = 0;
        $amount = 0.0;
        foreach ($list as $w) {
            $orders += (int)$w['orders'];
            $amount += (float)str_replace(',', '', $w['amount']);
        }
        jsonOut([
            'ok'           => true,
            'list'         => $list,
            'count'        => $cnt,
            'total_orders' => $orders,
            'total_amount' => number_format($amount, 2),
        ]);
    }

    if ($ajax === 'wsave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = bodyJson();
        if ($data === null) jsonOut(['ok' => false, 'msg' => '数据格式错误'], 400);

        $id      = (int)($data['id'] ?? 0);
        /* 名称截到 60 字，与进货单里存的批发商名保持同一上限；首尾空白也走同一套规范化，
           否则统计按名 JOIN 会对不上 */
        $name    = normalizeWholesalerName(mb_substr(normalizeWholesalerName((string)($data['name'] ?? '')), 0, 60));
        $contact = mb_substr(trim((string)($data['contact'] ?? '')), 0, 40);
        $phone   = mb_substr(trim((string)($data['phone'] ?? '')), 0, 30);
        $note    = mb_substr(trim((string)($data['note'] ?? '')), 0, 200);

        if ($name === '') jsonOut(['ok' => false, 'msg' => '请填写批发商名称'], 400);

        /* 同名去重 */
        $chk = $pdo->prepare("SELECT id FROM wholesalers WHERE name = :n AND id <> :id");
        $chk->execute([':n' => $name, ':id' => $id]);
        if ($chk->fetch()) jsonOut(['ok' => false, 'msg' => '已存在同名批发商'], 400);

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $old = $pdo->prepare("SELECT name FROM wholesalers WHERE id = :id");
                $old->execute([':id' => $id]);
                $oldRow = $old->fetch();
                if (!$oldRow) {          /* 档案已被别处删掉，不要假装保存成功 */
                    $pdo->rollBack();
                    jsonOut(['ok' => false, 'msg' => '该批发商已不存在，请刷新后重试'], 404);
                }
                $oldName = (string)$oldRow['name'];

                $stmt = $pdo->prepare("UPDATE wholesalers
                                       SET name = :n, contact = :c, phone = :p, note = :o
                                       WHERE id = :id");
                $stmt->execute([':n'=>$name, ':c'=>$contact, ':p'=>$phone, ':o'=>$note, ':id'=>$id]);

                /* 改名时同步历史进货单，保证统计口径一致 */
                if ($oldName !== '' && $oldName !== $name) {
                    $up = $pdo->prepare("UPDATE batches SET wholesaler = :new WHERE wholesaler = :old");
                    $up->execute([':new'=>$name, ':old'=>$oldName]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO wholesalers (name, contact, phone, note, created_at)
                                       VALUES (:n, :c, :p, :o, :t)");
                $stmt->execute([':n'=>$name, ':c'=>$contact, ':p'=>$phone, ':o'=>$note, ':t'=>date('Y-m-d H:i:s')]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['ok' => false, 'msg' => '保存失败：' . $e->getMessage()], 500);
        }

        jsonOut(['ok' => true, 'id' => $id]);
    }

    if ($ajax === 'wdelete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = bodyJson() ?? [];
        $id   = (int)($data['id'] ?? 0);
        if ($id <= 0) jsonOut(['ok' => false, 'msg' => '参数错误'], 400);
        $stmt = $pdo->prepare("DELETE FROM wholesalers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        /* 已经不存在就明确报错，前端才不会以为删成功而清空表单 */
        if ($stmt->rowCount() === 0) jsonOut(['ok' => false, 'msg' => '该批发商已不存在'], 404);
        jsonOut(['ok' => true]);
    }

    if ($ajax === 'stats') {
        jsonOut(getStats($pdo));
    }

    if ($ajax === 'batch') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM batches WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $b = $stmt->fetch();
        if (!$b) jsonOut(['ok' => false, 'msg' => '记录不存在'], 404);

        $stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id = :id ORDER BY id");
        $stmt->execute([':id' => $id]);
        $b['items'] = $stmt->fetchAll();
        jsonOut(['ok' => true, 'batch' => $b]);
    }

    if ($ajax === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = bodyJson();
        if ($data === null) jsonOut(['ok' => false, 'msg' => '数据格式错误'], 400);

        $id         = (int)($data['id'] ?? 0);
        $wholesaler = normalizeWholesalerName((string)($data['wholesaler'] ?? ''));
        $odate      = trim((string)($data['odate'] ?? ''));
        $note       = trim((string)($data['note'] ?? ''));
        /* 「待复核」标记：按钮点开才算；原因记在备注里，flag_note 仅作老数据兼容 */
        $flag       = ((int)($data['flag'] ?? 0)) === 1 ? 1 : 0;
        $items      = $data['items'] ?? [];

        if ($odate === '') jsonOut(['ok' => false, 'msg' => '请选择进货日期'], 400);
        /* 服务端再校验一次：杜绝 2 月 31 日、13 月、0 日这类不存在的日期 */
        $odateFix = normalizeDate($odate);
        if ($odateFix === null) {
            jsonOut(['ok' => false, 'msg' => '进货日期「' . $odate . '」不存在，请检查月份和天数（如 2 月最多 28/29 天）'], 400);
        }
        $odate = $odateFix;
        if (!is_array($items)) $items = [];

        $clean = [];
        $maxItems = 300;                 /* 一张单 300 行明细，足够用；防止恶意超大数组 */
        foreach ($items as $it) {
            if (count($clean) >= $maxItems) break;
            if (!is_array($it)) continue;

            $n = mb_substr(trim((string)($it['name'] ?? '')), 0, 120);
            if ($n === '') continue;

            /* 数量裁到 2 位小数，单价裁到 4 位（除不尽的单价，如 12.5÷6=2.0833），
               否则两位单价乘回去还原不了实付总额 */
            $qty   = round((float)($it['qty'] ?? 0), 2);
            $price = round((float)($it['price'] ?? 0), 4);
            if (!is_finite($qty) || $qty < 0) $qty = 0;
            if (!is_finite($price) || $price < 0) $price = 0;
            if ($qty > 1000000) $qty = 1000000;
            if ($price > 10000000) $price = 10000000;

            $clean[] = [
                'name'  => $n,
                'spec'  => mb_substr(trim((string)($it['spec'] ?? '')), 0, 30),
                'unit'  => mb_substr(trim((string)($it['unit'] ?? '')), 0, 20),
                'qty'   => $qty,
                'price' => $price,
            ];
        }
        if (!$clean) jsonOut(['ok' => false, 'msg' => '请至少填写一个商品名称'], 400);

        /* 截到 60 字后再收一次首尾空白，避免截断处正好落在空格上 */
        $wholesaler = normalizeWholesalerName(mb_substr($wholesaler, 0, 60));
        $note       = mb_substr($note, 0, 200);

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $chk = $pdo->prepare("SELECT id FROM batches WHERE id = :id");
                $chk->execute([':id' => $id]);
                if (!$chk->fetch()) {
                    $pdo->rollBack();
                    jsonOut(['ok' => false, 'msg' => '这张进货单已不存在，可能已被删除'], 404);
                }

                $stmt = $pdo->prepare("UPDATE batches SET wholesaler = :w, odate = :d, note = :n, flag = :f,
                                       flag_note = CASE WHEN :f = 1 THEN flag_note ELSE '' END WHERE id = :id");
                $stmt->execute([':w'=>$wholesaler, ':d'=>$odate, ':n'=>$note, ':f'=>$flag, ':id'=>$id]);
                $pdo->prepare("DELETE FROM items WHERE batch_id = :id")->execute([':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO batches (wholesaler, odate, note, flag, flag_note, created_at)
                                       VALUES (:w, :d, :n, :f, '', :c)");
                $stmt->execute([':w'=>$wholesaler, ':d'=>$odate, ':n'=>$note, ':f'=>$flag, ':c'=>date('Y-m-d H:i:s')]);
                $id = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare("INSERT INTO items (batch_id, name, spec, unit, qty, price) VALUES (:b, :n, :s, :u, :q, :p)");
            foreach ($clean as $it) {
                $ins->execute([':b'=>$id, ':n'=>$it['name'], ':s'=>$it['spec'], ':u'=>$it['unit'], ':q'=>$it['qty'], ':p'=>$it['price']]);
            }

            /* 新出现的批发商名自动纳入批发商管理，保持两处口径一致 */
            if ($wholesaler !== '') {
                $sync = $pdo->prepare("INSERT OR IGNORE INTO wholesalers (name, created_at) VALUES (:n, :t)");
                $sync->execute([':n'=>$wholesaler, ':t'=>date('Y-m-d H:i:s')]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['ok' => false, 'msg' => '保存失败：' . $e->getMessage()], 500);
        }

        jsonOut(['ok' => true, 'id' => $id]);
    }

    if ($ajax === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = bodyJson() ?? [];
        $id   = (int)($data['id'] ?? 0);
        if ($id <= 0) jsonOut(['ok' => false, 'msg' => '参数错误'], 400);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM items WHERE batch_id = :id")->execute([':id' => $id]);
            $stmt = $pdo->prepare("DELETE FROM batches WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonOut(['ok' => false, 'msg' => '删除失败：' . $e->getMessage()], 500);
        }

        if ($stmt->rowCount() === 0) jsonOut(['ok' => false, 'msg' => '这张进货单已不存在'], 404);
        jsonOut(['ok' => true]);
    }

    /* 一键取消「待复核」：核对无误后点一下徽章就清掉，不用进去逐项改 */
    if ($ajax === 'unflag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = bodyJson() ?? [];
        $id   = (int)($data['id'] ?? 0);
        if ($id <= 0) jsonOut(['ok' => false, 'msg' => '参数错误'], 400);
        $stmt = $pdo->prepare("UPDATE batches SET flag = 0, flag_note = '' WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) jsonOut(['ok' => false, 'msg' => '这张进货单已不存在'], 404);
        jsonOut(['ok' => true]);
    }

    jsonOut(['ok' => false, 'msg' => '未知请求'], 400);
}

/* ============ 4. 页面数据 ============ */
$stats    = getStats($pdo);
syncWholesalers($pdo);
/* 首屏只要下拉框用的名字，不再为此算每家的单数与金额（真正需要时由 ?ajax=whlist 拉） */
$wholesalers = getWholesalerNames($pdo);
$q        = qParam('q', 60);
$date     = normalizeDate(trim($_GET['d'] ?? '')) ?? '';   /* 非法日期一律视为未筛选 */
$shop     = qParam('w', 60);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(1, min(200, (int)($_GET['per'] ?? 10)));
$listHtml = renderList($pdo, $q, $date, $shop, $page, $perPage, isset($_GET['f']) && $_GET['f'] === '1');
$today    = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>进货簿 · 批量进货记录</title>
<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAABPtSURBVHhe3VtJVBtZls1lL3vZy172spa9rF33qT59KrPTA8bYxtjM8yjmSSAkNDCZ2RgDBoSZQUJiEGBAjAZjMw82GGMzagDblZll4dv9f0SIUEiQuJo8p7LinHsi9PX149/77ns/Qgp99903bhbLL3+wWL780XJo87SYTlL/bkDmQ+Zl+eUPwjn/vzZs4J/I4Efmk3qr2faT1XyC3wlarSabn8WCfxZyuvBGBrCabTsuBv/9wGSzWA5tYSSQQn5nbkQ1q/lkzGmw3zVsSxYL/lXI1Wkjnawm24bzAP8AIG6wfPmjkLN9o5H/RyXPwWSzHB/g34TcabFzZXuL6QQWsmePrRbe628AN5ZwfCG4PsK+dB78uZwBMj8CM9tfOD6FybbhVBxJoXDqaD6BmQwsJM0Tgj85VxPngyMgbKN73tj8MRzOKTifEMJznDcXq/kkRxB919Xe1aSE7/OPj4gwgr6kjb8/D1wfoVB8CAPCb6N9BKK4hu2nT3v4FyoAs9wJO7ADsy7gjl29zz8WCsC1CT93HugY53xO6Ab7XnBe/mtXODKdZFEBjswnOuGb/IHsSrP5RdW9SKTIBLj+PKvTcQ4+4mBrG4fbuzi22GC18j7jYiz+uYQC29u4dvb4vLkxsC2xa/7ZV3iU/DmTOgsOkeHaLCc4tnzB3tY29hYH8bq/GBvDNbC+XYF575hMiBHnV4R1gGBurs57Hr47Ovzrvwsb+bArzAO//bx+/MgTmHc/4uO7VawOVuHtUAk2+vOwOVCAd8YKvJ/VY29j0z6O3f4u0sA+D9ZhtI1PnHPABUT4znLw5XthIx98BwhJC8F/39GmX7Gztozd2W6YZhrxpi8PixoplrrkWNRmY6FTgndDpdg01sK0Oo3DHROO2ZTgO0IoMkfUaR4XJE9w5vJnH0wA4fu0D1d1he9ZTnC0b8H23Ci2xhuw2V+A+fYMLBLiXQoHLGhkWNJKcTD1BNszWmzMjmFzfgn7O5/tY/HndN787HO5gAjfkVtJYSMfrk4ghF0A7oTWrzg6/At2Vhfwl80JvDdWYFErw4I224k4hyWdEqs9OVjozMJIdSIGK6V49qQU/Q112HnzAR+PvlJXOCx3Ls7Pn8eRi7kKcTEBzrCaEHT5OvgZlp19fHz7Cm+HK7GoycJi19nECVb0Kix1KTDTLEFvaSwqk7yhCnSDwvcaiiM8Md5ch9WZBbzfPLCvPg4Q5jzrgEsVQOgEuzCkH6nuR6TIHWN7aQYfnjfTaC7r5FjUZGNRS3LdGYQ0wVyHDMaaFKglgcgP9YAqwB2qgBsUuUEeyA+5BXVWLIZaGrD3zowjq+AagUfcfkzev0DQLiSAw2syKMlt3gTMpi843jvA1pQW6/0lWOtWYaFTSguckDRDXM6Io5VjpjkTnXmRKBN5ISfQHUp/hjg5ZoQgbddRIbqL/opMLA13w/TeTAW31wU+UVcOOQcXFsCp0JETWU9w+OEAh2vPYXrZgXfDZbSQMeSdiXNY0Sux2CnDwMN41Kb5oiD8FiWu9HOjxDnyCl83FEd6ol0ZDmNVEmZbJNgYfIg3I0349OEdzAd/pW7g5knmR2xPrX+ZDuCszpEn0TcdfIZl+w1ej7Vib6IG8+1iLGikWNDKXYIQX9YrsaRTYLwuDa2KMDyM9YLS3w1yHnHGBW5Q+LnhcZIPesvi8LI1C6vdKizrFFjUSrFhyMfRghYfXg5jf+s9jixfGUfyHXGZAtA9UZe13f7mFt7ODuBovhMrOpLDEixonElzbaTCE/JMkROhOsUXOUHulCSJ9CnxG1D4XkdBqAfUmYG0LizpGOEcx83GqzYxNgaK8GFai/31JRzuWJjLaY74ZQlAwS49pr1jfFiZw+4LQlyF+Y4shuQZIAVuWafEXHs2jE9S0SIPxYPwW5D7XncgTnKe2D0nwB3lMXep5YlYTMSdxz2FjKbb1tAj7C+OwfJ+F9aDn0/nfWkCsHj9YhoLHQVY1Cix0JlNI2GPNA9chadFrkmC7iIRtTsTZSa/uTwnUSfHBcEeqE31w1BlIuY72GXTxdiOYIrsSpcSm4Zi7M3ocLD5nqkLl+UAruqT4+XxUTwrTUF/SSIm6zLo8iUUYFGroG3znXIMVyejXhyAB2G3aJSZCi8kfwPlsfegK4zGdFMmJcWIR64OhYQF0JJriDysanLwqj4d74fqsbf+2l4YL0UAPpbHRmEoTIFGEQ9dbjwGypMx3SShk10kltcqsNylxFRDBtpVESiP9UJe0E0215lIc7lOCl1R+G20ZodipCoFc+0y+lkyxkKn/Fex0p2HVV0+5pqyMV2VgqlHiXjT+xj7v4kArANWxsfQX5SKTnk8WqUidMhj0VuUiPEnYrxqlVL0lcahThyAghAPNtdPiZOoy32uU1Fq0/1hKI3HiyYJJU4gJOkKK/ocrOsLsNimwmxdJiYfJWGkOArj5XHY6H2MvfU3v4EALKgAxanUAe3ZsWiTxaJNKoIuNwGG0iS0yMORG+gOmfc1J+KcCx6KvNCuCsd4XTqW6ZUgQ5ykjJAs055NU21Rq8SqPh/LGhWWWpWYqU7HaGksRstEjABlIrzpqfxtBVgdH8NASRo0SkYADsQRanEUlIFeSPG4BvGdHyH3ISK408qu9LuBoojbNOqDjxIw30kIKSi58zDH9lvqUmG5KxcrnXmYrc3AZEUixsvjMVZGBIjBSFEExkqi8Lq7AnuvLzkF+Fd/y2wKcA7goFHEQS2ORm5kELKCApFy+wZSPb5HltdVSr4s+i60+VGYacq0V3YhWUfI6J5WeF0e1nQFWGiRY+pxCkP+YRzGymMxWhoDY0kUhh+EYbQ4Aq/15dhbX79cAQi4K0GSAgPFzg4gAjRkRFEBpKHBkAQFQnz/DhLdfkCOvzsM9GqOXB4zlZ0sc+eik9xEKbHeU4iFFiWma9LxvCoVExUJlPxouQhGQr44CiOF4RjKD4axKBTr+rLLF8B+x8crgg4OkJEUiIVaHIHciABIQ4gIodQJEt97eBjnD2NVqn2JdCLLA1kJSK4T4kudeZhrlGO6RozJiiRMViRgoiKeIU9sX0LIR2D4QSie5QVipDAY67pS7K2vXa4AfKxOsA4gAshETBGUidCRLYI6PQKq8ABIgoOQFRKMrJAQqMKDUZUUgi5lDAbLkvC8IZNeQJEl04m4Ro41fT5j91YVZtUyTFWlYbIyCZOPEjFeEU9tbyyNpuSHi8Ip+aGCYAzm+mG4IBBrXcVUAPp1GiH/mwjAOYAKEIM2aTTapdFoyohARTwhHQhJcCAyg4KhCAtERVwAmtJC0CoOgz43BsbHKZhtybKLQCr8ii4XK115WO7Mx2KzClNV6ZisTKU5P0HIPySRZ3O+OBIjLPnhgmA8yw/EgMoHQ3n+WNEUYe/1OnOr7GL+rvBNAixPjKGfXAjJ49AmjUFbVjTasqIYSCLRmhGOqoQgFEYHQh4WBEVYAB7GBqA5PQTNaUFoTAlCW2YoDMVxmFZnYL5dgSVNDtb0hVhsy8P0kyw8rxLjOY18MkOejfxoWTSMJZEYKQzD8IMQDBUE4Vl+AAbz/NCvvIdnuT5Y0TxgHMAKcCnfCPHBrAIp6JSLKPlWSRRaCXGCzAi0ZISjRRyGpvRQPIoPQGGUPx7HBaA5LZgRIDkQTckBaEz2h04VhdmnCqxqC/GqUYHnNZmYrs6g5O2R58jTas9Fnkc+1w8DOT4wyO9iUHUfK5pC7K6tn36jfBkp4HAvMDaGvoIkdMhiHElzEIehRRyKFnEImlKD8TQliKIpJZCikZBP8oc6zget6cEYKUvGi3oZZmolTOQf8yJPlzpS7aMZ29sjH4hn+f4YIORVPjT6fbLbGFDcxXJ7PnZXV/GRSwGTzYmPEL8qAB9Lo6MwEAGk0WjhRZygmSA9FM1pIWiiEQ9GC9mnnhIneJroB3W8D1rSAjH4IA5TVWJM12TQgjdVyY+8iNp+xCny/oztcwj5+zAo7qJX6oEB+R2stOdjb22NFYB8SfLViYMQFxPA7oBR9OUnoj0ryk6ekmaJE9JNqacR50f9aSJDviHRF/Xx3mhJDcDgg1hK/Hk1G3l6kcOv9pEYLnLM+YFcX0reoLxHrd+XfQc9EncYZLew1JaL3bUVWgMukv8EvyoA/2swIkBvfgLaJZF2u9MCR4sciXbQqQDJAUy+J3ECMOQbEn1OBSgQYbIyBVOPWfLc5a1DtRfkvMqb2p6QN8juoE96C90ZbujLuonFVhV211bZIvgVlsNLSAH+l6BLo0b05MWjPTP8lDQLPnGh5e3kE3zQkOCN+rh7aE7xw0BeDMYrkjDxKAFjJOcpebbas5F/ls9Ve18M5BDyXjAoPGnkCfmeLHfoxFfRI7mBxVYlrwZ8vaQi6CDAKLpz49CWEXZKmhf1M4mz5NXx3lDH3UedyAtNyb7oz43GWDl7eVsmwmiJIPL5QRjM46o9F3lC/jZ6CXnJTegz3dCV9iO6M65jvlmO3dUVfDymv/3DeujMR4hvE8BoRE9OLNrEoc5W5+V6A8/uJOKUePx9Gvn62HuojbmLpkQfGHIi6S3tWJkIxpJoGIsiMVwYhiEa+UAM5pFq78va3oshL+PIu0Of4Qad+Bq0KT9An34F840y7KwsUwccEQdQOHP6mwVYNI6gWxmDVhJ9p4hzee6HhgQu4vdRH+sFNUucHNeLvFAb7YnGBG/0qSJgLIlhbE8i/yAcQwWM7Tny/Vzkqe0JeQ9q++5MhnxX+hVokv4MXeoPmHuahV1OgEt3AFkFRkcwWpqGpmSGqKPVT/Ocs3qLJA7t8nTG9tG3UBfjiXrRXTyJuo3G+HvoU4ZhuJBYPoKu86fkuWrvDQMXeZZ8t+QG9JnXKXld2hUa/fbY/4Qm/j+w2fMQB5tb7CpAasBlOsB8gu3Vt5jtasRIuRStyf40l9UJvk5FjkReHXsP2gf5eNbUja7CB2hKj0RtlAeeRNxATeQtNMZ7oVdBbmZIvrNLHSl4XM7bqz1new/W9oT8VWjTrqAj4b+hTfwTxnI9Md+owPuXk7Duf2J+O7xMAfi3xIe7n7A02Ifn6od4VpiGxiRv1BIhaJ6zuU6sHncfutJSTPYvYEw3jb7qBrTJUmgqVIffwNM4T/TIgzGYT5a5YGapo+Qdq30vF/msG+imkb8Kber/oCPhTzBkXqUiLWgeMt8GW5if0O32v6wU4P8KzPwEZcPBthmvB7XoUkZDmxWB5iRfmt/EFcTm5FjPCjDZ9woTPS9g7BiCvrgY6nh/NMTeQXc2sTqzzFHyOYLIZ99GDxf5zOvoSv8R2uQ/o0d8BSM53ph/KsWb8T5YDr/g2MoVPRYk+pflAP56Sl4zP09/hfXwJ2y+nMWCpgZt4iA8jb+Huug7qI2+g7poT+iKizBpmMNU3xymDHNUiOd9r6ArKWMEkPmhX+VPLT+Y44sBpTf6FUzO98oY8jTnqe2vQJ/+I/TiHzGc54OdyT5Yts2wHPxCydsF4L4H+E0cwGvj3HBkscH8/hAro8MYKktDW6ovnkR44En4TbRkxmFQ3YEpQrx/gYowa3yD3sdqNMR6Qi/1hUFJLO/DRF7BRf4OS57k/DVqeV3a95gqDcKSphzv5l7gk+VnfPzIIy6MPMHhBRxgNdtihY1CskIB+O8xIhBX2LA19wrz3W2YqVbgabQ7qkJvoCktiqbCaMcwpgeXMGvcQG/lE6hFntBJvNEnJ5a/z0Q+2xO90tvoyWIiT/K9M+m/MJZ/F5PVKXj7fASH73bw8fjr6T0/nRsv8t/qAKvJ5iZsFJLkiNLXrBicMNxrkhZkUpaDz9iencRQZQ6G8uJRH34VNeEeaM/OwEBtM6YHl2GoaYRadAddGffRKyNRZ8j3EfISD+jE19GV8j2GFDex2pyNjcFG7CzP0ys8AnqnR6MsIP23CECenxc28sF3gMOeA9uP/2ACqcR7G9t4M6xDT34q2pPuoTrwB9SJfNFTXonOvDyoo29BK76HbqkXemXMUted6Y5u8TUMKW5jvDgE03VZMK/O4dhkY67vSd2hhe0cfGsKkAeGhY0O4J7KEpClx0LVWdCUMJ/g0/EJdtY3MFqjgjYzFE2xnngSch2V/ldQH+kOrdgLesld9EjYOzqJO63809UpWO1vxuG7Q/p0GFnTadF1ca4zcVEH0IelXfxPgAN1AH8gzvZ88AXh9uyDU8QNh9sWvJ4YxUSVAnXh11Dp+z1qw65Bm3YX+ozb6JXchEHqgYmSEMx3lsO0tQ/rwWda4I6ERU4IzhHckmeP/kUEsO0w/xU4byVgH3Z2iLpw7wpcqlhO2Gvzv9Lf7mc1DRguSKC1oTP5NrrSbmIk9z62DFXYfjmJw3f7vFxnxmIKnYtznIcLCXBSSQVg0uCcB6a5gXg/k9ldwH8qiz9RfhshQYqklTxKd4QPc6+w0d+B2coUzFSlYKW/BUcfyIMNX3HMEbdHmH9n5wKsA4hIpDDSvoenr4VcHHjx/2NI/kEh7EA78R9AZFOCa3cgypHl77ljFkRI8pzR589fcbz/EVvjA9iYHIb14BfGJeRhJ3vE2dxnRXAi7lIAljQrwK8UwVY7eZ4LnP41ws/3i37Pdi7YiR6zqwV98Ipd1uzEuWMhiBP5e/aY6+/wGdrnLAFsP7n84xTz6LxjKnAOoHXA1XM33An5xxcB/e/A6fJpdxn5KltY0PjLGn/POyYiHHNuoeCc4wImm5uQu31jLozOrge/d5CCL+TstNGLI5PNIvzw7xu2n86NvHAjNYH5w7RwoN8lWl3m/EU2slSQ9dJVgfy7hslmIQE896+y/7f9L6ljYS9+z+uMAAAAAElFTkSuQmCC">
<style>
:root{
    --card:#ffffff;
    --text:#1f2b3e;
    --muted:#7b8aa1;
    --line:#e6ecf5;
    --primary:#4f7cf7;
    --danger:#e2495b;
    --radius:16px;
    --primary-strong:#2f66e8;
    --muted-light:#a9b5c8;
    --line-card:#edf1f8;
}
*{box-sizing:border-box;}
html{height:100%;}
body{
    margin:0; padding:34px 16px 34px;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
    color:var(--text); font-size:14px; line-height:1.6;
    background:radial-gradient(1100px 560px at 6% -12%, #dfeaff 0%, rgba(223,234,255,0) 62%), radial-gradient(900px 520px at 100% -6%, #ffe8f1 0%, rgba(255,232,241,0) 58%), linear-gradient(180deg,#f8fafe 0%, #eef3fb 100%);
    background-attachment:fixed; min-height:100vh; -webkit-font-smoothing:antialiased;
}
.wrap{max-width:1180px;margin:0 auto;}

/* ---------- 页头 ---------- */
.page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;flex-wrap:wrap;}
.brand{display:flex;align-items:center;gap:14px;}
.logo{
    width:52px;height:52px;flex:none;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px;
    background:linear-gradient(135deg,#eaf1ff,#f7eaff); box-shadow:0 8px 22px rgba(79,124,247,.16), inset 0 0 0 1px #fff;
}
h1{
    margin:0;font-size:25px;letter-spacing:.3px;font-weight:700;
    background:linear-gradient(90deg,var(--primary-strong),#7b5cf0); -webkit-background-clip:text;background-clip:text;color:transparent;
}

/* ---------- Tab ---------- */
.tabs{
    display:inline-flex;gap:6px;margin-bottom:16px;background:#fff;padding:6px;border-radius:14px;
    border:1px solid var(--line-card);box-shadow:0 8px 26px rgba(35,60,110,.055);
}
.tab-btn{
    padding:9px 26px;border:none;background:transparent;color:#63748e;
    font-size:14px;font-weight:600;font-family:inherit;border-radius:10px;cursor:pointer;white-space:nowrap;
    /* 只过渡背景与阴影：文字颜色同时参与过渡会让切走的 Tab 短暂变成“白字白底” */
    transition:background-color .18s, box-shadow .18s;
    will-change:background-color;
}
.tab-btn:hover{color:var(--primary);}
.tab-btn.active{background:linear-gradient(135deg,#5b8cff,#4f6ef7);color:#fff;box-shadow:0 6px 14px rgba(79,110,247,.30);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* ---------- 统计卡 ---------- */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.stat{
    background: var(--card); border: 1px solid #eef2f8; border-radius: var(--radius);
    padding: 16px 20px; box-shadow: 0 4px 18px rgba(35,60,110,0.03);
    display: flex; align-items: center; gap: 16px; transition: transform .2s ease, box-shadow .2s ease;
}
.stat:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(35,60,110,0.06); border-color: #dbe4f3; }
.stat-icon { width: 44px; height: 44px; flex:none; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.stat-icon svg { width: 22px; height: 22px; }
.icon-blue { background: #eff4ff; color: var(--primary); }
.icon-purple { background: #f5f0ff; color: #8a6cf5; }
.icon-green { background: #eefbf4; color: #1f8a4c; }
.icon-orange { background: #fff8eb; color: #d48806; }
.stat-content { display: flex; flex-direction: column; justify-content: center; }
.stat-label { font-size: 13px; color: var(--muted); font-weight: 500; margin-bottom: 2px; letter-spacing: 0.5px; }
.stat-value { font-size: 22px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; line-height: 1.2; }
.stat-value.money { color: var(--primary-strong); }
.stat .unit { font-size: 13px; color: var(--muted); margin-left: 4px; font-weight: 500; }

/* ---------- 卡片 ---------- */
.card{
    background:var(--card);border:1px solid var(--line-card);border-radius:var(--radius);
    padding:20px 24px 24px; margin:0;box-shadow:0 8px 28px rgba(35,60,110,.06);
}
/* 卡片之间的间距用「相邻卡」控制，最后一张卡不叠 margin，页面底部留白只由 body padding 决定 */
.tab-panel .card + .card{margin-top:16px;}
/* 详情卡是「顶替」列表卡出现的，前面两张卡都 display:none，相邻卡间距不该再叠一次 */
#shopDetailCard{margin-top:0;}
.card h2{
    margin:0 0 16px;font-size:16px;font-weight:700;letter-spacing:.3px;display:flex;align-items:center;gap:8px;
}
.card h2::before{
    content:"";width:4px;height:16px;border-radius:4px;background:linear-gradient(180deg,#5b8cff,#8a6cf5);
}

/* ---------- 表单 ---------- */
.batch-meta{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px;margin-bottom:20px;}
.field{display:flex;flex-direction:column;gap:6px;min-width:0;}
.field-note-full{grid-column:1/-1;}
label{font-size:13px;color:#54637a;font-weight:600;}
.req{color:#ef5b6b;margin-left:2px;}
input, select{
    width:100%;padding:10px 13px;font-size:14px;font-family:inherit;color:var(--text);
    background:#f8fafd;border:1px solid #e3eaf5;border-radius:11px;outline:none;
    transition:border-color .18s, box-shadow .18s, background .18s;
}
input::placeholder{color:var(--muted-light);}
input:hover, select:hover{border-color:#d3deef;}
input:focus, select:focus{background:#fff;border-color:#8fb0ff;box-shadow:0 0 0 4px rgba(79,124,247,.12);}
/* 非法输入（如 2 月 31 日）：红框提示 */
input.invalid, select.invalid{background:#fff6f7;border-color:#ffc9cf;box-shadow:0 0 0 4px rgba(226,73,91,.10);}
input.invalid:hover, select.invalid:hover{border-color:#f2a6b0;}
input.invalid:focus, select.invalid:focus{background:#fff;border-color:#f08c99;box-shadow:0 0 0 4px rgba(226,73,91,.16);}

/* 日期框容器 (全局通用) */
.date-wrap { position: relative; display: flex; align-items: center; }
.date-wrap input { padding-right: 36px; }
.date-icon {
    position: absolute; right: 12px; color: var(--muted-light); cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: color .18s;
}
.date-icon:hover { color: var(--primary); }

/* ---------- 明细录入 ---------- */
.items-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;flex-wrap:wrap;}
.items-head h3{margin:0;font-size:14px;font-weight:700;color:#3c4a60;letter-spacing:.3px;}
.items-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:13px;background:#fbfcfe;}
.items-table{width:100%;border-collapse:collapse;min-width:800px;font-size:14.5px;}

.items-table th{
    background:#f7f9fd;color:var(--muted);font-size:12.5px;font-weight:600;text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);white-space:nowrap;
}
.items-table th:nth-child(4),
.items-table th:nth-child(5),
.items-table th:nth-child(6) { text-align: right; }

.items-table td{padding:9px 10px;border-bottom:1px solid #f1f5fb;}
.items-table tbody tr:last-child td{border-bottom:none;}
.items-table input{padding:8px 11px;border-radius:9px;}

.items-table .i-qty,
.items-table .i-price { text-align: right; }
/* 小计框：既能看，也能手填总价，填了就反算单价 */
.items-table .i-sub{
    text-align:right;font-weight:600;color:var(--primary-strong);
    font-variant-numeric:tabular-nums;
}
.th-small{font-size:11px;font-weight:400;color:#9aa7ba;margin-left:3px;}
/* 单价框不要上下箭头，也不要滚轮/方向键改数：纯手输或点历史价 */
.items-table .i-price{-moz-appearance:textfield;appearance:textfield;}
.items-table .i-price::-webkit-outer-spin-button,
.items-table .i-price::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.items-table .subtotal{font-weight:600;color:var(--primary-strong);font-variant-numeric:tabular-nums;white-space:nowrap;padding-left:12px;text-align: right;}

/* 单价历史价浮层：原生 datalist 在数字框上不一定弹，这个一定看得见 */
.price-tip{
    position:fixed; z-index:2000; display:none; width:auto; max-width:280px; max-height:266px; overflow:auto;
    overscroll-behavior:contain;
    background:#fff; border:1px solid #e2e9f4; border-radius:12px; box-shadow:0 14px 34px rgba(31,43,62,.16);
    padding:5px; font-size:13px; line-height:1.5; box-sizing:border-box;
}
.price-tip.show{display:block;}
.price-tip-head{padding:3px 9px 6px;font-size:12px;color:#8a97ab;user-select:none;}
.price-tip-item{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:7px 9px;border-radius:8px;cursor:pointer;transition:background .12s;}
.price-tip-item:hover{background:#eef4ff;}
.price-tip-item.active{background:#eef4ff;box-shadow:inset 0 0 0 1px #dbe6ff;}
.pty-price{font-weight:700;color:#2f66e8;font-variant-numeric:tabular-nums;}
.pty-date{color:#8a97ab;font-size:12px;white-space:nowrap;}
.pty-new{margin-left:5px;font-style:normal;font-size:11px;color:#1f8a4c;background:#eefaf2;border-radius:6px;padding:1px 5px;}

/* 商品名联想浮层：左名称右「批发商 ￥价 · 次数」，比单价浮层宽一点 */
.name-tip{max-width:380px;}
.pty-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text);font-weight:600;}
.pty-meta{flex:none;color:#8a97ab;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap;}
.pty-tag{margin-left:6px;font-style:normal;font-size:11px;color:#2f66e8;background:#eef4ff;border-radius:6px;padding:1px 5px;font-weight:500;}

.row-del{
    width:30px;height:30px;border-radius:9px;border:1px solid var(--line);
    background:#fff;color:#9aa8bd;font-size:16px;line-height:1;cursor:pointer;transition:.16s;
    display:inline-flex;align-items:center;justify-content:center;
}
.row-del:hover{border-color:#ffc9cf;color:var(--danger);background:#fff5f6;}

.form-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px;flex-wrap:wrap;}
.total-box{font-size:14px;color:#54637a;display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;}
.total-box strong{font-size:20px;color:var(--primary-strong);font-variant-numeric:tabular-nums;margin-right:2px;}
.total-box #grandQty{color:#22314a;}
.total-divider{color:#dde5f1;margin:0 8px;font-weight:400;}
.actions{display:flex;gap:12px;}

/* ---------- 按钮 ---------- */
.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    border:none;border-radius:11px;padding:10px 22px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;transition:.18s;white-space:nowrap;text-decoration:none;
}
.btn:disabled{opacity:.65;cursor:not-allowed;transform:none !important;}
.btn-primary{background:linear-gradient(135deg,#5b8cff,#4f6ef7);color:#fff;box-shadow:0 8px 18px rgba(79,110,247,.28);}
.btn-primary:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 10px 22px rgba(79,110,247,.36);}
.btn-ghost{background:#fff;color:#63748e;border:1px solid var(--line);}
.btn-ghost:hover{color:var(--primary);border-color:#c3d4ff;background:#f8faff;}
.btn-soft{background:#eef3ff;color:#3f68e8;padding:8px 16px;border-radius:10px;font-size:13px;}
.btn-soft:hover{background:#e3ecff;}

/* ---------- 列表 & 搜索 ---------- */
.table-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px;flex-wrap:wrap;}
.table-head h2{margin:0;}
/* 详情页标题：批发商名字放大加粗当主角，功能说明降成灰色小字，别糊成一整句 */
.table-head h2 .sd-name{font-size:18px;font-weight:700;color:var(--primary-strong);letter-spacing:.2px;}
.table-head h2 .sd-sub{font-size:12.5px;font-weight:500;color:var(--muted-light);letter-spacing:0;}
.search{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}

.search-input-wrap { position: relative; display: flex; align-items: center; }
.search-input-wrap input { width: 240px; padding: 9px 32px 9px 13px; }

/* 复用通用的 date-wrap 样式，仅调整宽度 */
.search-date-wrap { position: relative; width: 140px; }
.search-date-wrap input { width: 140px; padding: 9px 30px 9px 13px; cursor: pointer; }

/* ---------- 筛选栏（与搜索分开） ---------- */
.filter-bar{
    display:flex;flex-direction:column;gap:10px;
    padding:12px 14px;margin:0 0 16px;
    background:linear-gradient(180deg,#f9fbff,#f6f9fe);
    border:1px solid var(--line-card);border-radius:12px;
}
.filter-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.filter-tag{
    font-size:12px;font-weight:700;color:var(--primary);background:#eaf1ff;
    padding:3px 10px;border-radius:20px;letter-spacing:.5px;flex:none;
}
.filter-select{
    width:180px;padding:8px 10px;cursor:pointer;font-size:13.5px;
    background:#fff;border:1px solid #e3eaf5;border-radius:10px;color:var(--text);
}
.filter-select:hover{border-color:#d3deef;}
.filter-select:focus{background:#fff;border-color:#8fb0ff;box-shadow:0 0 0 4px rgba(79,124,247,.12);outline:none;}
.filter-bar .search-date-wrap input{background:#fff;}
.filter-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.filter-chips:empty{display:none;}
.chip{
    display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#3f68e8;
    background:#eef3ff;border:1px solid #d8e4ff;border-radius:20px;padding:3px 4px 3px 10px;white-space:nowrap;
}
.chip-x{
    border:none;background:transparent;color:#8ea6e6;cursor:pointer;font-size:14px;line-height:1;
    padding:2px 4px;border-radius:50%;font-family:inherit;
}
.chip-x:hover{color:var(--primary-strong);background:#dde8ff;}

/* 列宽策略：除「#」「名称」列外，其余列一律按内容自适应，名称列吃掉剩余宽度。
   进货记录明细表、批发商供货详情表都套这个类，不再各自写一份相同的规则 */
.cols-fit{table-layout:auto;}
.cols-fit th,
.cols-fit td{white-space:nowrap;}
.cols-fit th:nth-child(n+3),
.cols-fit td:nth-child(n+3){width:1px;}
.cols-fit .empty-row td{white-space:normal;}

/* ---------- 通用数据表（批发商列表） ---------- */
.data-table{width:100%;border-collapse:collapse;font-size:14.5px;min-width:760px;}
.data-table th{
    background:#f7f9fd;color:var(--muted);font-size:12.5px;font-weight:600;text-align:left;
    padding:11px 14px;border-bottom:1px solid var(--line);white-space:nowrap;
}
.data-table td{padding:11px 14px;border-bottom:1px solid #f1f5fb;color:#4a5a72;vertical-align:middle;}
.data-table tbody tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover{background:#f7f9fc;}
.data-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.data-table .wh-name{font-weight:600;color:var(--primary-strong);white-space:nowrap;min-width:120px;}
/* 批发商列表只放三样东西：名称 / 联系人 / 联系电话（+ 操作），4 列 */
#shopListCard .data-table{min-width:440px;}
#shopListCard .data-table .ct-cell{white-space:nowrap;}
#shopListCard .data-table .ph-cell .ph-line{display:block;white-space:nowrap;}
/* 联系人 / 联系电话按内容宽度站住，剩下的宽度全给名称列 */
#shopListCard .data-table th:nth-child(3),
#shopListCard .data-table th:nth-child(4){width:1px;}
#shopListCard .data-table tbody tr:last-child td{border-bottom:none;}
.data-table .muted{color:var(--muted-light);}
.data-table .idx-cell{color:var(--muted-light);font-size:12.5px;text-align:center;font-variant-numeric:tabular-nums;}
.data-table .ops{text-align:center;white-space:nowrap;}
.data-table .ops .btn-mini{margin:0 3px;}
.data-table th.sortable{cursor:pointer;user-select:none;transition:background-color .15s, color .15s;}
.data-table th.sortable:hover{background:#f0f5ff;color:#3f68e8;}
.data-table th.sortable.sorted{color:#3f68e8;}
.data-table .empty-row td{text-align:center;color:var(--muted-light);padding:36px 10px;}
.data-table tbody tr.row-click{cursor:pointer;}
.data-table tbody tr.row-click:hover{background:#f4f8ff;}
.list-tip{
    display:flex;align-items:center;gap:6px;
    font-size:12.5px;color:#93a1b5;line-height:1.5;
    margin:-4px 0 12px;padding-left:2px;
}
.list-tip svg{color:#b9c6da;flex:none;}

/* 批发商详情头部：两列网格，标签在左、值在右，三行竖排；
   标签列宽由最长标签决定，所有值自然对齐；统计单独一行，虚线隔开 */
.detail-head{
    display:grid;grid-template-columns:max-content minmax(0,1fr);
    align-items:baseline;gap:10px 16px;padding:15px 18px;margin:0 0 14px;font-size:13px;
    background:linear-gradient(180deg,#fafcff,#f4f8fe);border:1px solid var(--line-card);border-radius:12px;
}
.detail-head em{font-style:normal;font-size:12px;color:#93a1b5;white-space:nowrap;}
.detail-head b{min-width:0;font-weight:600;color:#22314a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums;}
.detail-head .dh-note{font-weight:500;color:#3d4f68;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
.detail-head .dh-stats{grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 24px;padding-top:12px;border-top:1px dashed #e4ecf8;}
.detail-head .dh-stats span{display:inline-flex;align-items:baseline;gap:6px;}
.detail-head .dh-money b{color:var(--primary-strong);}
.detail-head .dh-muted{color:var(--muted-light);font-weight:400;}
.data-table .detail-new-price{font-weight:700;color:var(--primary-strong);font-variant-numeric:tabular-nums;}
.data-table .pd-name{font-weight:600;color:#22314a;white-space:nowrap;min-width:160px;}

.data-table .pd-name .sp{
    display:block;margin-top:2px;white-space:nowrap;
    font-size:10.5px;font-weight:400;line-height:0.8;color:#9aa7ba;letter-spacing:.2px;
}

/* 批发商详情：商品搜索条（计数缩进对齐标题文字，标题前有 4px 竖条 + 8px 间距；
   缩进只给计数，搜索框仍贴齐卡片内边距，手机端才不会左右不等宽） */
.detail-bar{
    display:flex;align-items:center;justify-content:space-between;gap:10px 14px;
    flex-wrap:wrap;margin:0 0 12px;
}
.detail-bar .search-input-wrap input{width:260px;padding:9px 32px 9px 13px;}
.detail-count{font-size:12.5px;color:var(--muted);white-space:nowrap;margin-left:12px;}
.detail-count b{color:var(--primary-strong);font-variant-numeric:tabular-nums;font-weight:600;}

.clear-btn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    width: 20px; height: 20px; border-radius: 50%; background: #c3ccda;
    color: #fff; border: none; font-size: 14px; line-height: 1;
    cursor: pointer; display: none; align-items: center; justify-content: center;
    padding: 0; transition: background .15s;
}
.clear-btn:hover { background: var(--muted-light); }

.batch{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:14px;overflow:hidden;transition:.18s;}
.batch:last-child{margin-bottom:0;}
.batch:hover{border-color:#d8e3f5;box-shadow:0 6px 18px rgba(35,60,110,.06);}
.batch-head{
    display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 16px;
    background:linear-gradient(180deg,#f9fbff,#f5f8fe);border-bottom:1px solid var(--line);flex-wrap:wrap;
}
.batch-info{display:flex;align-items:center;gap:12px;flex-wrap:wrap;min-width:0;}

.batch-date{
    font-weight:700;color:#22314a;font-variant-numeric:tabular-nums;
    background:#fff;border:1px solid var(--line);padding:3px 10px;border-radius:8px;font-size:13px;
    cursor: pointer; transition: .15s;
}
.batch-date:hover {
    background: #eef3ff; border-color: #c3d4ff; color: var(--primary-strong);
}

/* 批发商名可点（点了就按这家筛选），鼠标做成链接那种手型 */
.batch-shop{font-weight:600;color:#3b63e0;font-size:14px;cursor:pointer;}
.batch-shop em{font-style:normal;color:var(--muted-light);font-weight:400;}
.batch-right{display:flex;align-items:center;gap:10px;}
.batch-body{overflow-x:auto;}
.batch-items{width:100%;border-collapse:collapse;font-size:14.5px;min-width:600px;}
/* # 号列固定窄一点，不跟着内容撑开 */
.batch-items th:first-child,
.batch-items td.idx-cell{width:34px;}

/* 列表表格表头：加粗、手形、去文字选择 */
.batch-items th{
    background:#fcfdff;color:#8a97ab;font-size:12px;font-weight:600;
    text-align:left;padding:9px 16px;border-bottom:1px solid #f1f5fb;
    cursor: pointer; user-select: none; transition: background-color 0.15s, color 0.15s;
}
.batch-items th:hover { background: #f4f7fc; color: #3f68e8; }
/* 只让「数量」「单价」表头右对齐（小计自带 inline 右对齐）；单位仍是左对齐，和内容一致 */
.batch-items th:nth-child(4),
.batch-items th:nth-child(5) { text-align: right; }

.batch-items th:first-child,
.batch-items td.idx-cell {
    padding-left: 4px;
    padding-right: 4px;
}

.batch-items td{padding:10px 16px;border-bottom:1px solid #f5f8fc;color:#4a5a72;vertical-align:middle;}
.batch-items tbody tr:last-child td{border-bottom:none;}
.batch-items .name-cell{font-weight:600;color:#22314a;white-space:nowrap;}
/* 规格挂在名称下面一行：小一号、颜色淡，没填就不出现 */
.batch-items .name-cell .sp{
    display:block;margin-top:2px;white-space:nowrap;
    font-size:10.5px;font-weight:400;line-height:0.8;color:#9aa7ba;letter-spacing:.2px;
}

.batch-items tbody tr { transition: background-color 0.15s ease; }
.batch-items tbody tr:hover { background-color: #f7f9fc; }

.batch-items .num{font-variant-numeric:tabular-nums;text-align: right;white-space:nowrap;}
.batch-items .sub{font-weight:600;color:var(--primary-strong);font-variant-numeric:tabular-nums;text-align: right;}
.batch-items .idx-cell{color:var(--muted-light);font-size:12px;text-align:center;font-variant-numeric:tabular-nums;}

.batch-foot{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 16px;background:#fcfdff;border-top:1px solid #f1f5fb;flex-wrap:wrap;}
.batch-note{color:#8a97ab;font-size:13px;min-width:0;}
.batch-summary{display:flex;align-items:center;gap:12px;font-size:13px;color:var(--muted);white-space:nowrap;}
.batch-summary strong{font-size:18px;font-variant-numeric:tabular-nums;margin-left:4px;}
.batch-qty strong{color:#4a5a72;}
.batch-total strong{color:var(--primary-strong);}
.batch-divider{color:var(--line);}

.btn-mini{padding:6px 13px;border-radius:9px;font-size:13px;font-family:inherit;font-weight:500;border:1px solid var(--line);background:#fff;color:#5a6b85;cursor:pointer;transition:.16s;}
.btn-mini:hover{border-color:#c3d4ff;color:#3f68e8;background:#f6f9ff;}
.btn-mini.danger:hover{border-color:#ffc9cf;color:var(--danger);background:#fff5f6;}
.btn-mini.print:hover{border-color:#b7e6c8;color:#1f8a4c;background:#f4fdf7;}
.empty{text-align:center;padding:54px 10px;color:var(--muted-light);font-size:14px;}

mark.hl { background-color: #fff3bf; color: #d48806; padding: 0 2px; border-radius: 4px; font-weight: 600; }

/* ---------- 分页 ---------- */
.pager{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-top:20px;margin-bottom:4px;padding-top:16px;border-top:1px solid #f1f5fb;flex-wrap:wrap;
}
.pager-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.page-btn{
    min-width:38px;padding:7px 13px;border:1px solid var(--line);background:#fff;border-radius:9px;font-size:13px;font-family:inherit;color:#5a6b85;cursor:pointer;transition:.16s;font-weight:500;font-variant-numeric:tabular-nums;
}
.page-btn:hover:not(:disabled):not(.active){border-color:#c3d4ff;color:#3f68e8;background:#f6f9ff;}
.page-btn.active{background:linear-gradient(135deg,#5b8cff,#4f6ef7);border-color:transparent;color:#fff;box-shadow:0 6px 14px rgba(79,110,247,.25);cursor:default;}
.page-btn:disabled{opacity:.4;cursor:not-allowed;}
.page-ellipsis{color:var(--muted-light);padding:0 2px;user-select:none;}
.pager-meta{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted);margin-left:auto;flex-wrap:wrap;}
.page-info{font-size:13px;color:var(--muted);}
.pager-per{display:inline-flex;align-items:center;gap:6px;color:var(--muted);}
.per-select{
    width:auto;padding:6px 10px;font-size:13px;font-family:inherit;color:#5a6b85;
    background:#fff;border:1px solid var(--line);border-radius:9px;cursor:pointer;
}
.per-select:hover{border-color:#c3d4ff;color:#3f68e8;}
.per-select:focus{outline:none;border-color:#8fb0ff;box-shadow:0 0 0 3px rgba(79,124,247,.12);}

/* 小号按钮 */
.btn-sm{padding:7px 14px;font-size:13px;border-radius:9px;}

/* ---------- 提示 ---------- */
.toast{
    position:fixed;left:50%;top:26px;transform:translate(-50%,-16px);opacity:0;pointer-events:none;
    background:#fff;border:1px solid var(--line);border-radius:12px;padding:11px 22px;font-size:14px;
    box-shadow:0 12px 30px rgba(35,60,110,.14);transition:.26s;z-index:999;
}
.toast.show{opacity:1;transform:translate(-50%,0);}
.toast.ok{border-color:#b7e6c8;color:#1f8a4c;background:#f4fdf7;}
.toast.error{border-color:#ffc9cf;color:#d63a4d;background:#fff6f7;}

/* ---------- 打印样式 (A4) ---------- */
/* 打印排版样式只维护这一份：#printArea 平时隐藏，屏幕上看不见；
   页面内打印（@media print）和 iframe 打印窗口共用同一份规则，
   JS 里不再重复写一套，改样式只改这里 */
#printArea { display: none; }
#printArea {
    width: 100%;
    max-width: 100%;
    color: #000;
    font-family: "PingFang SC", "Microsoft YaHei", sans-serif;
}
#printArea .print-header { text-align: center; margin-bottom: 18px; }
#printArea .print-header h2 { font-size: 24px; margin: 0; letter-spacing: 4px; }
#printArea .print-meta { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; border-bottom: 1px solid #000; padding-bottom: 6px; }
#printArea .print-meta div { line-height: 1.8; }
#printArea .print-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 13px; table-layout: auto; }
#printArea .print-table th,
#printArea .print-table td { border: 1px solid #000; padding: 7px 10px; text-align: left; white-space: nowrap; }
/* 行不要跨页断开，避免最后一行被硬拆到第二页 */
#printArea .print-table tr { page-break-inside: avoid; break-inside: avoid; }
/* 其余列按内容自适应，商品名称列吃掉剩余宽度（不参与自适应，避免把数字列撑宽/挤窄） */
#printArea .print-table th:nth-child(2),
#printArea .print-table td:nth-child(2) { width: 100%; white-space: normal; }
/* 单位列内容短，居中更整齐 */
#printArea .print-table th:nth-child(3),
#printArea .print-table td:nth-child(3) { text-align: center; }
#printArea .print-table th { background: #f5f5f5 !important; font-weight: 600; }
#printArea .print-table .num { text-align: right; }
#printArea .print-table .sp { font-size: 11px; font-weight: 400; color: #444; margin-left: 2px; }
#printArea .print-footer { display: flex; justify-content: space-between; font-weight: bold; font-size: 15px; padding: 8px 0; border-top: 1px solid #000; border-bottom: 1px solid #000; margin-bottom: 0; }
#printArea .print-note { font-size: 14px; line-height: 1.6; margin-top: 12px; }
/* 抬头、元信息、合计、备注都不许被拆到下一页 */
#printArea .print-header,
#printArea .print-meta,
#printArea .print-footer,
#printArea .print-note { page-break-inside: avoid; break-inside: avoid; }

@media print {
    /* 手机打印常因 body 撑满一屏 + 上下内边距而多出一页空白，这里全部压平 */
    @page { size: A4 portrait; margin: 12mm; }
    html, body {
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        overflow: visible !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .wrap, .toast, .price-tip { display: none !important; }
    #printArea {
        display: block !important;
        page-break-after: avoid;
        break-after: avoid;
    }
}
/* ---------- 响应式 ---------- */
@media (max-width:960px){
    .stats{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:600px){
    body{padding:22px 12px 34px;}
    .card{padding:16px 16px 20px;}
    .stats{grid-template-columns:1fr;gap:12px;}
    .stat{padding:16px 20px;}
    .batch-meta{grid-template-columns:1fr;}
    .field-note-full{grid-column:auto;}
    .search-input-wrap{width:100%;}
    .search-input-wrap input{width:100%;}
    .search-date-wrap{width:100%;}
    .search-date-wrap input{width:100%;}
    .search{width:100%;}
    .detail-bar{width:100%;}
    .detail-bar .search-input-wrap input{width:100%;}
    .detail-head{padding:12px 14px;gap:9px 12px;}
    .detail-head .dh-stats{gap:6px 16px;}
    .filter-line{width:100%;}
    .filter-select{width:100%;}
    .filter-item{flex:1 1 100%;}
    .filter-bar .btn-sm{width:100%;justify-content:center;}
    .pager{flex-direction:column;align-items:stretch;}
    .pager-nav{justify-content:center;}
    .pager-meta{margin-left:0;justify-content:center;}
    h1{font-size:21px;}
    .batch-head{padding:12px 14px;}
    .batch-foot{padding:10px 14px; flex-direction:column; align-items:flex-start;}
    .batch-summary{width:100%; justify-content:space-between; margin-top:6px;}
    .form-footer{flex-direction:column;align-items:stretch;}
    .actions{justify-content:flex-end;}
    .tabs{width:100%;}
    .tab-btn{flex:1;padding:9px 10px;}
    .page-info{margin-left:0;text-align:center;}
}

/* ---------- 批发商列表：窄屏（手机 / 平板 / 窄窗口）收紧列宽与字号 ----------
   列表只有 4 列（名称 / 联系人 / 联系电话 / 操作），窄屏压字号和内边距即可，
   名称与联系人允许折行、电话号码保持一行一个，表格不用左右拖也能一屏看完。 */
@media (max-width:820px){
    #shopListCard .data-table{min-width:0;width:100%;table-layout:auto;}
    #shopListCard .data-table th,
    #shopListCard .data-table td{width:auto !important;padding:9px 6px;font-size:12.5px;}
    #shopListCard .data-table thead th{white-space:nowrap;font-size:11.5px;font-weight:600;}
    #shopListCard .data-table td:nth-child(2),
    #shopListCard .data-table td:nth-child(3){white-space:normal;word-break:break-word;}
    #shopListCard .data-table .idx-cell{padding-left:8px;}
    #shopListCard .data-table .ph-cell .ph-line{font-size:12px;}
    #shopListCard .data-table .ops .btn-mini{padding:4px 6px;margin:0 2px;font-size:11px;}
}

/* 手机竖屏：列再多也得塞进去 —— 名称 / 联系人 / 电话都允许断行，操作按钮再缩一号 */
@media (max-width:560px){
    #shopListCard .data-table th,
    #shopListCard .data-table td{padding:8px 4px;}
    #shopListCard .data-table .wh-name{min-width:0;white-space:normal;word-break:break-all;}
    #shopListCard .data-table .ct-cell{white-space:normal;word-break:break-all;}
    #shopListCard .data-table .ph-cell .ph-line{white-space:normal;word-break:break-all;font-size:12px;}
    #shopListCard .data-table .ops .btn-mini{padding:3px 5px;margin:0 1px;font-size:11px;}
}

/* ---------- 批发商详情：窄窗口收紧内边距与字号 ----------
   8 列（名称下还带规格）在 900px 以下容易被挤到换行、最后一列贴边；
   收紧 padding 后名称与数值都能放下，仍放不下就横向滚动，不会把列挤变形。 */
@media (max-width:900px){
    #shopDetailCard .data-table{font-size:13.5px;min-width:0;}
    #shopDetailCard .data-table th,
    #shopDetailCard .data-table td{padding:9px 8px;}
    #shopDetailCard .data-table .pd-name{min-width:0;}

    /* 录入明细：窄窗口里 6 个输入框按原宽度放不下，会裁掉「小计」和删除按钮；按比例收紧列宽 */
    .items-table{min-width:0;font-size:13.5px;}
    .items-table th,
    .items-table td{padding:8px 6px;}
    .items-table th:nth-child(2){width:104px !important;}
    .items-table th:nth-child(3){width:76px  !important;}
    .items-table th:nth-child(4){width:96px  !important;}
    .items-table th:nth-child(5){width:104px !important;}
    .items-table th:nth-child(6){width:90px  !important;}
    .items-table th:nth-child(7){width:44px  !important;}
}

/* ---- 待复核标记 ---- */
.flag-badge{display:inline-flex;align-items:center;gap:5px;margin-left:8px;padding:2px 9px;border-radius:999px;
    font-size:12px;line-height:18px;color:#8a5300;background:#fff4e0;border:1px solid #ffd79a;white-space:nowrap;vertical-align:middle;
    font-family:inherit;cursor:default;user-select:none;}
.flag-badge::before{content:"";width:6px;height:6px;border-radius:50%;background:#f5a524;}
.flag-note{display:inline-block;margin-left:10px;padding:1px 7px;border-radius:6px;font-size:12px;color:#8a5300;background:#fff8ec;border:1px solid #ffe7bf;}
.flag-check{display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#4a5563;user-select:none;font-style:normal;font-weight:500;}
.flag-check input{width:16px;height:16px;margin:0;cursor:pointer;accent-color:#2f6fed;}
.flag-check .flag-hint{font-style:normal;font-weight:400;color:#8b97a6;font-size:12px;}
.flag-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.flag-toggle{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #d8e0ea;border-radius:8px;
  background:#fff;color:#5a6879;font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;transition:all .16s;}
.flag-toggle svg{flex:none;transition:transform .16s;}
.flag-toggle:hover{border-color:#b9c8da;background:#f7fafd;color:#2f6fed;}
.flag-toggle.is-on{border-color:#f5c678;background:#fff8ec;color:#8a5300;}
.flag-toggle.is-on svg{transform:rotate(-8deg) scale(1.05);}
.flag-toggle:focus-visible{outline:2px solid #2f6fed;outline-offset:2px;}
.flag-toggle-text{white-space:nowrap;}
.flag-hint{font-style:normal;font-weight:400;color:#8b97a6;font-size:12px;}
.flag-num{min-width:20px;padding:0 6px;border-radius:999px;background:#fff4e0;color:#8a5300;font-size:12px;text-align:center;}
.filter-flag{margin-left:2px;}
</style>
</head>
<body>
<div class="wrap">

    <header class="page-head">
        <div class="brand">
            <div class="logo">📦</div>
            <div>
                <h1>进货簿</h1>
            </div>
        </div>
    </header>

    <!-- Tab 切换 -->
    <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="list">进货记录</button>
        <button type="button" class="tab-btn" data-tab="add">录入进货单</button>
        <button type="button" class="tab-btn" data-tab="shop">批发商管理</button>
    </div>

    <!-- ============ 列表面板 ============ -->
    <div class="tab-panel active" id="panel-list">
        
        <div class="stats">
            <div class="stat">
                <div class="stat-icon icon-blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <div class="stat-content">
                    <span class="stat-label">进货单数</span>
                    <strong class="stat-value"><span id="stat-count"><?= $stats['total_count'] ?></span><span class="unit">单</span></strong>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon icon-purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3.5l5 7 5-7"></path><line x1="12" y1="10.5" x2="12" y2="20.5"></line><line x1="8.5" y1="13.5" x2="15.5" y2="13.5"></line><line x1="8.5" y1="17" x2="15.5" y2="17"></line></svg>
                </div>
                <div class="stat-content">
                    <span class="stat-label">累计金额</span>
                    <strong class="stat-value money">￥<span id="stat-amount"><?= $stats['total_amount'] ?></span></strong>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon icon-green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </div>
                <div class="stat-content">
                    <span class="stat-label">本月金额</span>
                    <strong class="stat-value money">￥<span id="stat-month-amount"><?= $stats['month_amount'] ?></span></strong>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon icon-orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                </div>
                <div class="stat-content">
                    <span class="stat-label">本月单数</span>
                    <strong class="stat-value"><span id="stat-month-count"><?= $stats['month_count'] ?></span><span class="unit">单</span></strong>
                </div>
            </div>
        </div>

        <section class="card">
            <!-- 搜索区（只负责关键词搜索） -->
            <div class="table-head">
                <h2>进货记录</h2>
                <form class="search" id="searchForm" autocomplete="off">
                    <div class="search-input-wrap">
                        <input id="searchInput" value="<?= h($q) ?>" placeholder="搜索商品 / 备注 / 批发商" autocomplete="off">
                        <button type="button" id="clearSearchBtn" class="clear-btn" title="清空" style="<?= $q !== '' ? 'display:flex' : 'display:none' ?>">×</button>
                    </div>
                    <button class="btn btn-soft" type="submit">搜索</button>
                </form>
            </div>

            <!-- 筛选区（只负责批发商 / 日期） -->
            <div class="filter-bar">
                <div class="filter-line">
                    <span class="filter-tag">筛选</span>

                    <div class="filter-item">
                        <select id="shopFilter" class="filter-select">
                            <option value="">全部批发商</option>
                            <?php foreach ($wholesalers as $wd): ?>
                            <option value="<?= h($wd['name']) ?>"<?= $wd['name'] === $shop ? ' selected' : '' ?>><?= h($wd['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item date-wrap search-date-wrap">
                        <input type="text" id="dateFilterText" value="<?= h($date) ?>" placeholder="按日期筛选" maxlength="10" autocomplete="off">
                        <span class="date-icon" id="dateFilterTrigger" title="点击选择日期">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </span>
                        <input type="date" id="dateFilterNative" value="<?= h($date) ?>" tabindex="-1" style="position: absolute; left: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none;">
                    </div>

                    <div class="filter-item">
                        <label class="flag-check filter-flag">
                            <input type="checkbox" id="flagFilter"<?= isset($_GET['f']) && $_GET['f'] === '1' ? ' checked' : '' ?>>
                            <span>只看待复核</span>
                            <span class="flag-num" id="flagCount"><?= (int)$stats['flagged_count'] ?></span>
                        </label>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" id="clearFilterBtn">清除筛选</button>
                </div>

                <div class="filter-chips" id="filterChips"></div>
            </div>

            <div id="listBox">
                <?= $listHtml ?>
            </div>
        </section>
    </div>

    <!-- ============ 批发商管理面板 ============ -->
    <div class="tab-panel" id="panel-shop">
        <section class="card" id="shopFormCard">
            <h2 id="shopFormTitle">新增批发商</h2>
            <form id="shopForm" autocomplete="off">
                <input type="hidden" id="shopId" value="">

                <div class="batch-meta">
                    <div class="field">
                        <label for="s-name">批发商名称<span class="req">*</span></label>
                        <input id="s-name" maxlength="100" placeholder="例如：晨光文具批发">
                    </div>
                    <div class="field">
                        <label for="s-contact">联系人</label>
                        <input id="s-contact" maxlength="50" placeholder="选填">
                    </div>
                    <div class="field">
                        <label for="s-phone">联系电话</label>
                        <input id="s-phone" maxlength="30" placeholder="选填">
                    </div>
                    <div class="field">
                        <label for="s-note">备注</label>
                        <input id="s-note" maxlength="200" placeholder="地址、结款方式等">
                    </div>
                </div>

                <div class="form-footer" style="justify-content:flex-end">
                    <div class="actions">
                        <button type="button" class="btn btn-ghost" id="shopCancelBtn" style="display:none">取消编辑</button>
                        <button type="submit" class="btn btn-primary" id="shopSaveBtn">保存批发商</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="card" id="shopListCard">
            <div class="table-head">
                <h2>批发商列表<span class="sd-sub" id="shopCount"></span></h2>
                <div class="search">
                    <div class="search-input-wrap">
                        <input id="shopKeyword" placeholder="搜索名称 / 联系人 / 电话" autocomplete="off">
                        <button type="button" id="clearShopKeyword" class="clear-btn" title="清空">×</button>
                    </div>
                </div>
            </div>

            <div class="list-tip">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                点整行看供货商品与最新报价；联系人 / 联系电话 直接列在表里
            </div>

            <div class="items-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:44px;text-align:center">#</th>
                            <th>批发商</th>
                            <th data-label="联系人">联系人</th>
                            <th data-label="联系电话">联系电话</th>
                            <th style="width:104px;text-align:center">操作</th>
                        </tr>
                    </thead>
                    <tbody id="shopBox"></tbody>
                </table>
            </div>
        </section>

        <!-- 批发商详情：供货商品 + 最新报价 -->
        <section class="card" id="shopDetailCard" style="display:none">
            <div class="table-head">
                <h2 id="shopDetailTitle"><span class="sd-name">批发商</span><span class="sd-sub">供货商品与最新报价</span></h2>
                <button type="button" class="btn btn-ghost btn-sm" id="shopDetailBack">← 返回批发商列表</button>
            </div>

            <div class="detail-head" id="shopDetailMeta"></div>

            <div class="detail-bar">
                <span class="detail-count" id="detailCount"></span>
                <div class="search-input-wrap">
                    <input id="detailKeyword" placeholder="搜索该批发商的商品名称" autocomplete="off">
                    <button type="button" id="clearDetailKeyword" class="clear-btn" title="清空">×</button>
                </div>
            </div>

            <div class="items-wrap">
                <table class="data-table cols-fit">
                    <thead>
                        <tr>
                            <th class="sortable" style="width:44px;text-align:center" data-sort="index" data-label="#" title="点击恢复默认顺序">#</th>
                            <th class="sortable" data-sort="name" data-label="商品名称" title="点击排序">商品名称</th>
                            <th class="sortable" data-sort="unit" data-label="单位" title="点击排序">单位</th>
                            <th class="sortable" style="text-align:right" data-sort="times" data-label="进货次数" title="点击排序">进货次数</th>
                            <th class="sortable" style="text-align:right" data-sort="qty" data-label="累计数量" title="点击排序">累计数量</th>
                            <th class="sortable" style="text-align:right" data-sort="price" data-label="最新单价" title="点击排序">最新单价</th>
                            <th class="sortable" style="text-align:right" data-sort="odate" data-label="最新报价日期" title="点击排序">最新报价日期</th>
                            <th class="sortable" style="text-align:right" data-sort="range" data-label="历史价格区间" title="点击排序">历史价格区间</th>
                        </tr>
                    </thead>
                    <tbody id="shopDetailBox"></tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- ============ 录入面板 ============ -->
    <div class="tab-panel" id="panel-add">
        <section class="card">
            <h2 id="formTitle">新增进货单</h2>
            <form id="batchForm" autocomplete="off">
                <input type="hidden" id="batchId" value="">

                <div class="batch-meta">
                    <div class="field">
                        <label for="f-wholesaler">批发商</label>
                        <input id="f-wholesaler" list="wholesalerOptions" maxlength="60" placeholder="输入或从已管理的批发商中选择">
                        <datalist id="wholesalerOptions">
                            <?php foreach ($wholesalers as $wd): ?>
                            <option value="<?= h($wd['name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="field">
                        <label for="f-odate-text">进货日期<span class="req">*</span></label>
                        <div class="date-wrap">
                            <input type="text" id="f-odate-text" value="<?= $today ?>" placeholder="例如：2026-11-23" maxlength="10" required autocomplete="off">
                            <span class="date-icon" id="dateTrigger" title="点击选择日期">
                                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </span>
                            <input type="date" id="f-odate-native" value="<?= $today ?>" tabindex="-1" style="position: absolute; left: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none;">
                        </div>
                    </div>
                    <div class="field field-note-full">
                        <label for="f-note">备注</label>
                        <input id="f-note" maxlength="200" placeholder="选填，如联系人、结款方式等">
                    </div>
                    <div class="field field-note-full">
                        <label>复核标记</label>
                        <div class="flag-row">
                            <button type="button" class="flag-toggle" id="f-flag" data-on="0" aria-pressed="false" title="点击切换是否需要复核">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>
                                <span class="flag-toggle-text">标记复核</span>
                            </button>
                            <em class="flag-hint">看不清、名称或价格存疑时点一下，原因写备注</em>
                        </div>
                    </div>
                </div>

                <div class="items-head">
                    <h3>商品明细</h3>
                    <button type="button" class="btn btn-soft" id="addRow">＋ 添加商品</button>
                </div>

                <div class="items-wrap">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>商品名称<span class="req">*</span></th>
                                <th style="width:150px">规格</th>
                                <th style="width:110px">单位</th>
                                <th style="width:130px;text-align:right">数量</th>
                                <th style="width:150px;text-align:right">单价（元）</th>
                                <th style="width:140px;text-align:right">小计<span class="th-small">可填</span></th>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>

                <div class="form-footer">
                    <div class="total-box">
                        <span class="total-qty-label">总数量</span>
                        <strong id="grandQty">0</strong>
                        <span class="total-divider">|</span>
                        <span>合计金额</span>
                        <strong id="grandTotal">￥0.00</strong>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-ghost" id="cancelEditBtn" style="display:none">取消编辑</button>
                        <button type="submit" class="btn btn-primary" id="saveBtn">保存进货单</button>
                    </div>
                </div>
            </form>
        </section>
    </div>

</div>

<!-- 打印专用容器 -->
<div id="printArea"></div>

<script>
(function () {
    'use strict';

    /* ============================================================
       1. 基础常量与工具
       ============================================================ */
    const TODAY = '<?= $today ?>';

    let currentPage = <?= (int)$page ?>;
    let currentPer  = <?= (int)$perPage ?>;
    let appliedDate = '<?= h($date) ?>';

    /* 统一取元素：元素不存在返回 null，后续一律走 on() 判空 */
    const $  = (id) => document.getElementById(id);
    const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

    /* 带判空的事件绑定 */
    const on = (target, type, handler, opts) => {
        if (target) target.addEventListener(type, handler, opts);
        return target;
    };

    function esc(s) {
        return String(s === undefined || s === null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* 关键词高亮：先转义再包 <mark class="hl">，与后端 highlight() 同一套样式 */
    function hl(text, kw) {
        const html = esc(text);
        if (!kw) return html;
        const pat = esc(kw).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return html.replace(new RegExp('(' + pat + ')', 'gi'), '<mark class="hl">$1</mark>');
    }

    /* 空值占位、空数据行：表格渲染里这两处重复最多，统一成一行调用 */
    const DASH = '<span class="muted">—</span>';
    const orDash = (v) => v ? esc(v) : DASH;
    /* 电话可能填了好几个号码，按分隔符拆开，列表里一个号码占一行 */
    const splitPhone = (v) => String(v || '').split(/[,，、;；\/]+/).map((p) => p.trim()).filter(Boolean);
    const emptyRow = (cols, msg) => '<tr class="empty-row"><td colspan="' + cols + '">' + msg + '</td></tr>';

    /* ===== 日期校验：拒绝 2 月 31 日、13 月、0 日这类不存在的日期 ===== */
    const DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    const isLeapYear = (y) => (y % 4 === 0 && y % 100 !== 0) || (y % 400 === 0);

    /* 返回 { ok:true, iso:'2026-11-24' } 或 { ok:false, msg:'...', empty?:true } */
    function checkDate(v) {
        const s = String(v === undefined || v === null ? '' : v).trim();
        if (s === '') return { ok: false, empty: true, msg: '请填写日期' };

        const norm = s.replace(/[/.\s]/g, '-');
        const m = norm.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!m) return { ok: false, msg: '日期格式应为 2026-11-24' };

        const y = Number(m[1]);
        const mo = Number(m[2]);
        const d = Number(m[3]);

        if (y < 1900 || y > 2100) return { ok: false, msg: '年份 ' + y + ' 超出合理范围（1900–2100）' };
        if (mo < 1 || mo > 12) return { ok: false, msg: mo + ' 月不存在' };

        const maxDay = (mo === 2 && isLeapYear(y)) ? 29 : DAYS_IN_MONTH[mo - 1];
        if (d < 1 || d > maxDay) {
            return { ok: false, msg: y + ' 年 ' + mo + ' 月只有 ' + maxDay + ' 天，没有 ' + d + ' 日' };
        }

        return { ok: true, iso: y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0') };
    }

    /* 兼容旧调用：合法返回标准格式，非法或空返回 '' */
    function normDate(v) {
        const r = checkDate(v);
        return r.ok ? r.iso : '';
    }

    /* 输入框红框提示（非法日期） */
    function markInvalid(el, bad) {
        if (el) el.classList.toggle('invalid', !!bad);
    }

    /* 唤起原生日期面板（不支持 showPicker 的浏览器回退到 click） */
    function openDatePicker(native) {
        if (!native) return;
        if (typeof native.showPicker === 'function') {
            try { native.showPicker(); return; } catch (e) { /* 回退 */ }
        }
        try { native.click(); } catch (e) { /* 忽略 */ }
    }

    function scrollToPanel(name) {
        const panel = $('panel-' + name);
        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ============================================================
       2. 网络层：所有请求走这里，错误只在这一处抛
       ============================================================ */
    async function getJSON(url) {
        const res = await fetch(url, { cache: 'no-store', headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('请求失败（HTTP ' + res.status + '）');
        try {
            return await res.json();
        } catch (e) {
            throw new Error('服务器返回的数据格式异常');
        }
    }

    /* POST + 统一校验 {ok:true}；失败抛出带后端 msg 的错误 */
    async function callAPI(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.ok && res.status >= 500) throw new Error('服务器错误（HTTP ' + res.status + '）');

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error('服务器返回的数据格式异常');
        }
        if (!data || !data.ok) throw new Error((data && data.msg) || '操作失败');
        return data;
    }

    /* ============================================================
       3. Toast 提示（元素懒创建，只建一次）
       ============================================================ */
    let toastEl = null;
    let toastTimer = 0;

    function toast(msg, type) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.id = 'toast';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.className = 'toast show ' + (type || '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.className = 'toast'; }, 2200);
    }

    /* ============================================================
       4. 元素引用（一次性取好，避免热点路径反复查 DOM）
       ============================================================ */
    const searchForm      = $('searchForm');
    const searchInput     = $('searchInput');
    const clearSearchBtn  = $('clearSearchBtn');

    const shopFilter       = $('shopFilter');
    const flagFilter       = $('flagFilter');
    const flagCount        = $('flagCount');
    const dateFilterText   = $('dateFilterText');
    const dateFilterNative = $('dateFilterNative');
    const dateFilterTrigger= $('dateFilterTrigger');
    const clearFilterBtn   = $('clearFilterBtn');
    const filterChips      = $('filterChips');

    const listBox = $('listBox');
    const printArea = $('printArea');

    const statCount       = $('stat-count');
    const statAmount      = $('stat-amount');
    const statMonthCount  = $('stat-month-count');
    const statMonthAmount = $('stat-month-amount');

    /* ============================================================
       5. 筛选条件：日期 / 批发商 / 条件标签
       ============================================================ */
    on(dateFilterTrigger, 'click', (e) => {
        e.preventDefault();
        openDatePicker(dateFilterNative);
    });

    /* 点筛选的日期输入框就直接弹日历，不用专门去点右边的小图标；
       pointerdown 先开、focus 兜底（键盘 Tab 过来也弹），300ms 去重防止弹两次；
       已聚焦后再点不重复弹，方便手打「2026-2-3」这类简写 */
    let filterPopAt = 0;
    function popFilterDatePicker() {
        const now = Date.now();
        if (now - filterPopAt < 300) return;
        filterPopAt = now;
        openDatePicker(dateFilterNative);
    }
    on(dateFilterText, 'pointerdown', () => {
        if (document.activeElement !== dateFilterText) popFilterDatePicker();
    });
    on(dateFilterText, 'focus', popFilterDatePicker);

    /* 日历选中 → 只影响筛选，不动搜索关键词 */
    on(dateFilterNative, 'change', () => {
        if (dateFilterText) dateFilterText.value = dateFilterNative.value || '';
        refreshList(1);
    });

    on(dateFilterText, 'input', () => {
        markInvalid(dateFilterText, false);
        if (!dateFilterText.value.trim() && dateFilterNative) dateFilterNative.value = '';
    });

    on(dateFilterText, 'blur', () => {
        const raw = dateFilterText.value.trim();

        /* 清空 = 取消日期筛选 */
        if (raw === '') {
            markInvalid(dateFilterText, false);
            dateFilterText.value = '';
            if (dateFilterNative) dateFilterNative.value = '';
            if (appliedDate !== '') refreshList(1);
            return;
        }

        const r = checkDate(raw);
        if (!r.ok) {
            /* 非法日期不进入筛选：提示原因，并回填上一次生效的日期，列表保持不动 */
            markInvalid(dateFilterText, true);
            toast(r.msg, 'error');
            dateFilterText.value = appliedDate || '';
            if (dateFilterNative) dateFilterNative.value = appliedDate || '';
            setTimeout(() => markInvalid(dateFilterText, false), 1600);
            return;
        }

        markInvalid(dateFilterText, false);
        dateFilterText.value = r.iso;
        if (dateFilterNative) dateFilterNative.value = r.iso;
        if (r.iso === appliedDate) return;
        refreshList(1);
    });

    on(shopFilter, 'change', () => refreshList(1));
    on(flagFilter, 'change', () => refreshList(1));

    /* 已生效的筛选条件 → 可单独移除的小标签 */
    function clearDateFilter() {
        if (dateFilterText) dateFilterText.value = '';
        if (dateFilterNative) dateFilterNative.value = '';
    }

    function renderChips() {
        if (!filterChips) return;
        const shop = shopFilter ? shopFilter.value : '';
        const date = dateFilterText ? dateFilterText.value.trim() : '';
        const chips = [];
        if (shop) chips.push({ k: 'shop', label: '批发商：' + shop });
        if (date) chips.push({ k: 'date', label: '日期：' + date });

        if (!chips.length) {
            filterChips.innerHTML = '';
            return;
        }
        filterChips.innerHTML = chips.map((c) =>
            `<span class="chip">${esc(c.label)}<button type="button" class="chip-x" data-chip="${c.k}" title="移除该筛选">×</button></span>`
        ).join('');
    }

    on(filterChips, 'click', (e) => {
        const x = e.target.closest('.chip-x');
        if (!x) return;
        if (x.dataset.chip === 'shop' && shopFilter) shopFilter.value = '';
        if (x.dataset.chip === 'date') clearDateFilter();
        refreshList(1);
    });

    on(clearFilterBtn, 'click', () => {
        if (shopFilter) shopFilter.value = '';
        if (flagFilter) flagFilter.checked = false;
        clearDateFilter();
        refreshList(1);
    });

    /* ============================================================
       6. Tab 切换
       ============================================================ */
    const tabBtns   = $$('.tab-btn');
    const tabPanels = $$('.tab-panel');

    function switchTab(name) {
        hidePriceTip();
        hideNameTip();
        tabBtns.forEach((b) => b.classList.toggle('active', b.dataset.tab === name));
        tabPanels.forEach((p) => p.classList.toggle('active', p.id === 'panel-' + name));
    }

    tabBtns.forEach((b) => on(b, 'click', () => switchTab(b.dataset.tab)));

    /* ============================================================
       7. 搜索框清空按钮
       ============================================================ */
    on(searchInput, 'input', () => {
        if (clearSearchBtn) clearSearchBtn.style.display = searchInput.value.length > 0 ? 'flex' : 'none';
    });

    on(clearSearchBtn, 'click', () => {
        searchInput.value = '';
        clearSearchBtn.style.display = 'none';
        refreshList(1);
        searchInput.focus();
    });

    /* ============================================================
       8. 录入面板：日期 + 明细行
       ============================================================ */
    const form         = $('batchForm');
    const formTitle    = $('formTitle');
    const saveBtn      = $('saveBtn');
    const cancelBtn    = $('cancelEditBtn');
    const batchIdEl    = $('batchId');
    const fWholesaler  = $('f-wholesaler');
    const fOdateText   = $('f-odate-text');
    const fOdateNative = $('f-odate-native');
    const fNote        = $('f-note');
    const fFlag        = $('f-flag');

    /* 复核标记：一个图标按钮，点一下切换开/关 */
    function setFlagOn(onState) {
        if (!fFlag) return;
        const isOn = !!onState;
        fFlag.dataset.on = isOn ? '1' : '0';
        fFlag.classList.toggle('is-on', isOn);
        fFlag.setAttribute('aria-pressed', isOn ? 'true' : 'false');
        const txt = fFlag.querySelector('.flag-toggle-text');
        if (txt) txt.textContent = isOn ? '取消复核' : '标记复核';
        fFlag.title = isOn ? '已标记待复核，点击取消' : '点击切换是否需要复核';
    }
    if (fFlag) fFlag.addEventListener('click', async () => {
        setFlagOn(fFlag.dataset.on !== '1');
        await submitBatch();          /* 点标记即整单保存，标记随单一起落库 */
    });
    const itemsBody    = $('itemsBody');
    const addRowBtn    = $('addRow');
    const grandTotalEl = $('grandTotal');
    const grandQtyEl   = $('grandQty');
    const dateTrigger  = $('dateTrigger');

    on(dateTrigger, 'click', (e) => {
        e.preventDefault();
        openDatePicker(fOdateNative);
    });

    /* 点一下日期输入框就直接弹日历，不用再专门去点右边的小图标。
       pointerdown 先开、focus 兜底（键盘 Tab 过来也弹），300ms 去重防止连着弹两次；
       已经聚焦后再点不重复弹，方便直接手打「2026-2-3」这类简写 */
    let datePopAt = 0;
    function popOrderDatePicker() {
        const now = Date.now();
        if (now - datePopAt < 300) return;
        datePopAt = now;
        openDatePicker(fOdateNative);
    }
    on(fOdateText, 'pointerdown', () => {
        if (document.activeElement !== fOdateText) popOrderDatePicker();
    });
    on(fOdateText, 'focus', popOrderDatePicker);

    on(fOdateNative, 'change', () => {
        if (fOdateNative.value && fOdateText) fOdateText.value = fOdateNative.value;
    });

    on(fOdateText, 'input', () => markInvalid(fOdateText, false));

    on(fOdateText, 'blur', () => {
        const raw = fOdateText.value.trim();
        if (!raw) {
            fOdateText.value = TODAY;
            fOdateNative.value = TODAY;
            markInvalid(fOdateText, false);
            return;
        }
        const r = checkDate(raw);
        if (!r.ok) {
            /* 非法日期就红框 + 提示，保留用户输入让他改 */
            markInvalid(fOdateText, true);
            toast(r.msg, 'error');
            return;
        }
        markInvalid(fOdateText, false);
        fOdateText.value = r.iso;
        fOdateNative.value = r.iso;
    });

    /* ---- 数量：最多两位小数；步长 1 / 0.1 / 0.01，且“粘”在行上不随显示格式退化 ---- */
    function decCount(s) {
        s = String(s === undefined || s === null ? '' : s).trim();
        const i = s.indexOf('.');
        if (i < 0) return 0;
        return s.slice(i + 1).replace(/0+$/, '').length;
    }

    /* 超过两位小数直接截断（不四舍五入，避免输入时数字来回跳） */
    function clampQtyDecimals(input) {
        const raw = input.value;
        if (raw === '') return;
        const m = /^([0-9]*)(?:\.([0-9]*))?$/.exec(raw);
        if (!m) return;
        const intPart = m[1] === '' ? '0' : m[1];
        let frac = m[2] || '';
        if (frac.length > 2) {
            frac = frac.slice(0, 2);
            input.value = intPart + (frac === '' ? '' : '.' + frac);
        }
    }

    function syncQtyStep(input) {
        if (!input) return;
        clampQtyDecimals(input);

        /* 手打进来的负数直接归零，别让它把小计和合计算成负的 */
        if (parseFloat(input.value) < 0) input.value = '0';

        const cur      = input.value;
        const explicit = decCount(cur);                                   /* 当前值自带的小数位 */
        let   prec     = Math.min(parseInt(input.dataset.prec || '0', 10) || 0, 2); /* 该行记住的精度 */
        const last     = (input.dataset.last === undefined || input.dataset.last === '') ? null : parseFloat(input.dataset.last);
        const now      = cur === '' ? null : parseFloat(cur);

        if (cur === '') {
            prec = 0;                                                     /* 清空 → 回到整数步进 */
        } else if (explicit > prec) {
            prec = Math.min(explicit, 2);                                 /* 手输了更多小数位 → 提升精度 */
        } else if (explicit === 0) {
            /* 显示成整数时：若这一步正好是“点箭头走了一格”，保留原精度（1.9 → 2 之后仍是 0.1）；
               否则视为重新输入，回到默认步进 1 */
            const curStep = prec === 0 ? 1 : (prec === 1 ? 0.1 : 0.01);
            const isStep  = (last !== null && now !== null) && Math.abs(Math.abs(now - last) - curStep) < 1e-8;
            if (!isStep) prec = 0;
        }

        const step = prec === 0 ? '1' : (prec === 1 ? '0.1' : '0.01');
        if (input.getAttribute('step') !== step) input.setAttribute('step', step);
        input.dataset.prec = String(prec);
        input.dataset.last = (now === null ? '' : String(now));
    }

    /* 单价最多 4 位小数、最少 2 位：45.60 这种整数价显示两位，
       12.5÷6=2.0833 这类除不尽的价保留四位，小计才能对上实付总额 */
    function formatPrice(input) {
        if (!input) return;
        const v = parseFloat(input.value);
        let fixed = '0.00';
        if (isFinite(v) && v >= 0) {
            /* 四舍五入到 4 位，再把第三、四位上的多余零去掉（2.0800 → 2.08，2.0830 → 2.083） */
            fixed = (Math.round((v + 1e-9) * 10000) / 10000).toFixed(4).replace(/(\.\d{2})0+$/, '$1');
        }
        if (input.value !== fixed) input.value = fixed;
    }

    /* 单价框只接受「数字 + 一个小数点」，小数最多 4 位，其余字符边打边清掉 */
    function sanitizePrice(input) {
        if (!input) return;
        const raw = input.value;
        let s = raw.replace(/[^\d.]/g, '');
        const dot = s.indexOf('.');
        if (dot >= 0) s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '').slice(0, 4);
        if (s !== raw) input.value = s;
    }

    /* ==== 单价历史建议：按「批发商 + 商品名」把历史单价挂到单价框的 datalist ==== */
    const priceHistory = new Map();      /* 批发商名 -> { 商品名: [{ price, odate, unit }] }，已按最新排序 */
    const pricePending = new Set();      /* 正在请求中的批发商，避免重复拉 */
    let   priceTimer   = 0;

    const currentWholesaler = () => (fWholesaler ? fWholesaler.value.trim() : '');

    /* 首次用到某个批发商时才去取一次，之后走内存缓存 */
    async function ensurePriceHistory(name) {
        if (!name || priceHistory.has(name) || pricePending.has(name)) return;
        pricePending.add(name);
        try {
            const d = await getJSON('?ajax=prices&w=' + encodeURIComponent(name));
            if (d && d.ok) {
                priceHistory.set(name, d.products || {});
                /* 请求回来时用户可能还没填商品名，这里再补刷一轮 */
                if (currentWholesaler() === name) {
                    refreshPriceTip();
                    /* 如果单价框还停着，把浮层也补上 */
                    const ae = document.activeElement;
                    if (ae && ae.classList && ae.classList.contains('i-price')) showPriceTip(ae.closest('tr'));
                }
            }
        } catch (e) {
            /* 建议列表拿不到，不影响正常录入 */
        } finally {
            pricePending.delete(name);
        }
    }

    /* 一行该用哪些历史价：当前批发商 + 该行商品名，找不到就返回 null */
    function priceListFor(tr) {
        if (!tr) return null;
        const nameEl = tr.querySelector('.i-name');
        if (!nameEl) return null;

        const wn   = currentWholesaler();
        const hist = wn ? priceHistory.get(wn) : null;
        const nm   = nameEl.value.trim();
        const list = (hist && nm) ? hist[nm] : null;
        return (list && list.length) ? list : null;
    }

    /* 历史价只走自绘浮层，不再挂 datalist（两套下拉会互相遮挡） */
    function refreshPriceTip() {
        if (priceTipRow) showPriceTip(priceTipRow);   /* 浮层开着就跟着刷新 */
    }

    /* ---------- 规格建议：填完商品名后，把这个货以前用过的规格挂到 datalist ---------- */
    const specCache   = new Map();   /* 「批发商|商品名」-> [{ spec, times, odate }] */
    const specPending = new Set();
    let   specSeq     = 0;

    /* 每行一个独立 datalist：多行共用一个会把彼此的候选互相冲掉 */
    function specDatalist(specEl) {
        if (!specEl.dataset.dl) {
            specEl.dataset.dl = 'specdl-' + (++specSeq);
            specEl.setAttribute('list', specEl.dataset.dl);
        }
        const id = specEl.dataset.dl;
        let dl = document.getElementById(id);
        if (!dl) {
            dl = document.createElement('datalist');
            dl.id = id;
            document.body.appendChild(dl);
        }
        return dl;
    }

    function fillSpecOptions(dl, list) {
        if (!dl) return;
        dl.innerHTML = list.map((s) =>
            '<option value="' + esc(s.spec) + '"'
            + (s.times > 1 ? ' label="进过 ' + s.times + ' 次"' : '')
            + '></option>'
        ).join('');
    }

    /* 只按「当前批发商 + 该行商品名」取规格；商品名空着就直接清空候选 */
    async function ensureSpecOptions(tr) {
        if (!tr) return;
        const nameEl = tr.querySelector('.i-name');
        const specEl = tr.querySelector('.i-spec');
        if (!nameEl || !specEl) return;

        const dl = specDatalist(specEl);
        const nm = nameEl.value.trim();
        const wn = currentWholesaler();
        if (!nm) { dl.innerHTML = ''; return; }

        const key = wn + '|' + nm;
        if (specCache.has(key)) { fillSpecOptions(dl, specCache.get(key)); return; }
        if (specPending.has(key)) return;

        specPending.add(key);
        try {
            const d = await getJSON('?ajax=specs&w=' + encodeURIComponent(wn) + '&n=' + encodeURIComponent(nm));
            const list = (d && d.ok && Array.isArray(d.specs)) ? d.specs : [];
            specCache.set(key, list);
            /* 请求回来时这行可能已经改了名，只有还是同一家同一个货才回填 */
            if (nameEl.isConnected && nameEl.value.trim() === nm && currentWholesaler() === wn) {
                fillSpecOptions(dl, list);
            }
        } catch (e) {
            /* 规格候选拿不到不影响录入 */
        } finally {
            specPending.delete(key);
        }
    }

    /* 商品名打完字再拉规格候选：边打边发请求没必要，260ms 静默后再取 */
    let specTimer = 0;
    let specTimerRow = null;
    function scheduleSpecOptions(tr) {
        specTimerRow = tr;
        clearTimeout(specTimer);
        specTimer = setTimeout(() => {
            if (specTimerRow && specTimerRow.isConnected) ensureSpecOptions(specTimerRow);
        }, 260);
    }

    /* 商品名正好命中历史记录、而单位还空着 → 顺手把单位补上，少敲一次 */
    function maybeFillUnit(tr) {
        if (!tr) return;
        const unitEl = tr.querySelector('.i-unit');
        if (!unitEl || unitEl.value.trim() !== '') return;

        const list = priceListFor(tr);
        if (list && list[0].unit) unitEl.value = list[0].unit;
    }

    /* ---------- 历史价浮层：原生 datalist 在数字框上不一定弹，这个一定看得见 ---------- */
    let priceTip    = null;      /* 复用的浮层节点，全局只建一个 */
    let priceTipRow = null;      /* 当前浮层对应的行 */

    function ensurePriceTip() {
        if (priceTip) return priceTip;
        priceTip = document.createElement('div');
        priceTip.className = 'price-tip';
        document.body.appendChild(priceTip);

        /* 按下时别抢走输入框焦点，否则浮层会先被 blur 收起，点不到 */
        priceTip.addEventListener('mousedown', (e) => e.preventDefault());
        guardTipWheel(priceTip);
        priceTip.addEventListener('click', (e) => {
            const item = e.target.closest('.price-tip-item');
            if (!item || !priceTipRow) return;

            const priceEl = priceTipRow.querySelector('.i-price');
            if (!priceEl) return;
            priceEl.value = item.dataset.price || '0.00';
            formatPrice(priceEl);
            recalc();
            hidePriceTip();
            priceEl.focus();
        });
        return priceTip;
    }

    function hidePriceTip() {
        if (priceTip) priceTip.classList.remove('show');
        priceTipRow = null;
    }

    function guardTipWheel(tip) {
        /* 在浮层里滚轮只翻自己的列表，别把页面滚走（页面滚动会把浮层收起） */
        tip.addEventListener('wheel', (e) => {
            const scrollable = tip.scrollHeight > tip.clientHeight + 1;
            if (!scrollable) { e.preventDefault(); return; }

            const atTop = tip.scrollTop <= 0 && e.deltaY < 0;
            const atBot = tip.scrollTop + tip.clientHeight >= tip.scrollHeight - 1 && e.deltaY > 0;
            if (atTop || atBot) e.preventDefault();
        }, { passive: false });
    }

    /* 浮层定位：贴在输入框下面，下面放不下就翻到上面，左右不越界 */
    function placeTip(tip, el, maxW) {
        const cap = maxW || 280;
        const r = el.getBoundingClientRect();
        const w = Math.min(Math.max(r.width, 168), cap);
        const h = tip.offsetHeight;
        let top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);

        tip.style.width = w + 'px';
        tip.style.left  = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
        tip.style.top   = top + 'px';
    }

    function showPriceTip(tr) {
        const list = priceListFor(tr);
        const priceEl = tr ? tr.querySelector('.i-price') : null;
        if (!list || !priceEl) { hidePriceTip(); return; }

        const tip = ensurePriceTip();
        priceTipRow = tr;
        tip.innerHTML = '<div class="price-tip-head">历史单价 · 点一下填入</div>'
            + list.map((p, i) =>
                '<div class="price-tip-item" data-price="' + esc(p.price) + '">'
                + '<span class="pty-price">￥' + esc(p.price) + '</span>'
                + '<span class="pty-date">' + esc((p.odate || '').slice(5) || '—')
                + (i === 0 ? '<em class="pty-new">最新</em>' : '') + '</span>'
                + '</div>'
            ).join('');
        tip.classList.add('show');
        placeTip(tip, priceEl);
    }

    /* ---------- 商品名联想浮层：输入 ≥2 个字按历史记录模糊匹配 ---------- */
    let nameTip    = null;       /* 全局只建一个浮层节点 */
    let nameTipRow = null;       /* 当前对应的行 */
    let nameTipData = [];        /* 当前候选 */
    let nameTipIdx = -1;         /* 键盘高亮下标 */
    let nameTimer  = 0;
    let pickerSeq  = 0;          /* 打开选择器的序号：异步回来时用它判断是否已过期 */
    const nameCache = new Map(); /* 「批发商|关键词」-> 候选数组 */

    function ensureNameTip() {
        if (nameTip) return nameTip;
        nameTip = document.createElement('div');
        nameTip.className = 'price-tip name-tip';
        document.body.appendChild(nameTip);

        nameTip.addEventListener('mousedown', (e) => e.preventDefault());
        guardTipWheel(nameTip);
        nameTip.addEventListener('click', (e) => {
            const item = e.target.closest('.price-tip-item');
            if (!item || !nameTipRow) return;
            pickName(nameTipRow, nameTipData[parseInt(item.dataset.idx, 10)]);
        });
        return nameTip;
    }

    function hideNameTip() {
        if (nameTip) nameTip.classList.remove('show');
        nameTipRow  = null;
        nameTipIdx  = -1;
        nameTipData = [];
    }

    /* 选中候选：填名称 → 补单位 → 单价还是 0 就填最近价 → 光标跳到单价确认 */
    function pickName(tr, item) {
        hideNameTip();
        if (!tr || !item) return;

        const wn = currentWholesaler();
        /* 这条记录不是本批发商进过的，提醒一句，别记错来源 */
        if (wn && item.wh && item.wh !== wn) {
            toast('注意：「' + item.name + '」的历史来自「' + item.wh + '」', '');
        }

        tr.querySelector('.i-name').value = item.name;

        /* 规格空着就用这个货最近一次的规格填上，接着刷新候选 */
        const specEl = tr.querySelector('.i-spec');
        if (specEl) {
            if (specEl.value.trim() === '' && item.spec) specEl.value = item.spec;
            ensureSpecOptions(tr);
        }

        const unitEl = tr.querySelector('.i-unit');
        if (unitEl && unitEl.value.trim() === '' && item.unit) unitEl.value = item.unit;

        const priceEl = tr.querySelector('.i-price');
        if (priceEl && (parseFloat(priceEl.value) || 0) <= 0 && item.price) {
            priceEl.value = item.price;
            formatPrice(priceEl);
        }
        recalc();
        refreshPriceTip();

        if (priceEl) {
            priceEl.focus();          /* 顺手带出该批发商的历史单价 */
            showPriceTip(tr);
        }
    }

    function renderNameTip(tr, list, q, scope) {
        const nameEl = tr.querySelector('.i-name');
        if (!nameEl) { hideNameTip(); return; }
        if (!list.length) {
            hideNameTip();
            return;
        }

        const tip = ensureNameTip();
        nameTipRow  = tr;
        nameTipData = list;
        nameTipIdx  = 0;              /* 默认高亮第一条，回车即可选 */

        const wn = currentWholesaler();
        let head;
        if (scope === 'all') {
            head = wn ? '「' + esc(wn) + '」没有类似商品 · 以下来自其他批发商'
                      : '「' + esc(q) + '」的历史商品 · 点一下填入';
        } else if (scope === 'wh-all') {
            head = '「' + esc(wn) + '」共 ' + list.length + ' 种商品 · 直接点选';
        } else {
            head = '「' + esc(wn || q) + '」进过的商品 · 点一下填入';
        }

        tip.innerHTML = '<div class="price-tip-head">' + head + '</div>'
            + list.map((p, i) =>
                '<div class="price-tip-item' + (i === 0 ? ' active' : '') + '" data-idx="' + i + '">'
                + '<span class="pty-name">' + esc(p.name) + '</span>'
                + '<span class="pty-meta">'
                + (scope === 'all' && p.wh ? esc(p.wh) + ' ' : '')
                + (p.price ? '￥' + esc(p.price) : '')
                + (p.times > 1 ? ' ·' + p.times + '次' : '') + '</span>'
                + '</div>'
            ).join('');
        tip.classList.add('show');
        placeTip(tip, nameEl, 380);
    }

    /* 键盘上下移动高亮 */
    function moveNameIdx(step) {
        if (!nameTip || !nameTipData.length) return;
        nameTipIdx = (nameTipIdx + step + nameTipData.length) % nameTipData.length;
        const nodes = nameTip.querySelectorAll('.price-tip-item');
        nodes.forEach((n, i) => n.classList.toggle('active', i === nameTipIdx));
        const cur = nodes[nameTipIdx];
        if (cur) cur.scrollIntoView({ block: 'nearest' });
    }

    /* ---------- 「种类少就免打字」：先摸清这家有多少种商品 ---------- */
    const whIndex      = new Map();   /* 批发商 -> { count, list }，种类 <15 时 list 就是全清单 */
    const whIndexFetch = new Map();   /* 批发商 -> 进行中的 Promise，避免重复请求 */

    function ensureWhIndex(wn) {
        if (!wn) return Promise.resolve(null);
        if (whIndex.has(wn)) return Promise.resolve(whIndex.get(wn));
        if (whIndexFetch.has(wn)) return whIndexFetch.get(wn);

        const p = (async () => {
            let v = { count: 0, list: [] };
            try {
                const d = await getJSON('?ajax=names&scope=wh&q=&w=' + encodeURIComponent(wn));
                if (d && d.ok) {
                    v = {
                        count: Number(d.count) || 0,
                        list: Array.isArray(d.list) ? d.list : [],
                    };
                }
            } catch (e) {
                /* 摸不清就当种类多，走打字搜索的老路 */
            }
            whIndex.set(wn, v);
            whIndexFetch.delete(wn);
            return v;
        })();
        whIndexFetch.set(wn, p);
        return p;
    }

    /* 本地过滤：空格分词，每个词都要出现（纯内存，零延迟） */
    function filterNameList(list, q) {
        const toks = q.toLowerCase().split(/[\s　]+/u).filter(Boolean);
        if (!toks.length) return list;
        return list.filter((p) => {
            const nm = String(p.name).toLowerCase();
            return toks.every((t) => nm.indexOf(t) >= 0);
        });
    }

    /* 点名称框/敲字都走这里：这家种类少于 15 → 立刻全列出来（连 1 个字都不用打）
       种类多 → 输入 2 个字以上再做服务端模糊搜索 */
    async function openNamePicker(tr) {
        if (!tr || !tr.isConnected) return;
        const nameEl = tr.querySelector('.i-name');
        if (!nameEl) return;

        const seq = ++pickerSeq;
        const wn = currentWholesaler();
        if (!wn) {
            const q0 = nameEl.value.trim();
            if (q0.length >= 2) loadNameSuggest(tr, q0);
            else hideNameTip();
            return;
        }

        const idx = await ensureWhIndex(wn);
        if (seq !== pickerSeq) return;                                       /* 又开了一次，这次作废 */
        if (!tr.isConnected || document.activeElement !== nameEl) return;   /* 期间用户走了 */

        const q = nameEl.value.trim();

        if (idx.count > 0 && idx.count < 15) {
            const list = filterNameList(idx.list, q);
            if (list.length) { renderNameTip(tr, list, q, 'wh-all'); return; }
            if (q.length >= 2) { loadNameSuggest(tr, q); return; }   /* 本家确实没有 → 全库兜底 */
            hideNameTip();
            return;
        }

        if (q.length >= 2) loadNameSuggest(tr, q);
        else hideNameTip();
    }

    /* 取候选：先只找本批发商；本批发商没进过才退到全库（候选会标出各自批发商）
       同一「批发商 + 关键词」命中过就直接用缓存，不再发请求 */
    async function loadNameSuggest(tr, q) {
        if (!itemsBody || !tr.isConnected) return;
        const wn  = currentWholesaler();
        const key = wn + '|' + q;
        if (nameCache.has(key)) {
            const c = nameCache.get(key);
            renderNameTip(tr, c.list, q, c.scope);
            return;
        }
        try {
            let res = null;
            if (wn) {
                const scoped = await getJSON('?ajax=names&scope=wh&q=' + encodeURIComponent(q)
                    + '&w=' + encodeURIComponent(wn));
                if (scoped && scoped.ok && Array.isArray(scoped.list) && scoped.list.length) {
                    res = { list: scoped.list, scope: 'wh' };
                }
            }
            if (!res) {
                const all = await getJSON('?ajax=names&scope=all&q=' + encodeURIComponent(q)
                    + '&w=' + encodeURIComponent(wn));
                if (all && all.ok && Array.isArray(all.list) && all.list.length) {
                    res = { list: all.list, scope: 'all' };
                }
            }
            nameCache.set(key, res || { list: [], scope: 'wh' });

            /* 请求回来时用户可能已经继续打字/换行，只有还在同一行同一词才弹 */
            const nameEl = tr.querySelector('.i-name');
            if (res && nameEl && nameEl.isConnected && document.activeElement === nameEl
                && nameEl.value.trim() === q) {
                renderNameTip(tr, res.list, q, res.scope);
            }
        } catch (e) {
            /* 联想失败不影响录入 */
        }
    }

    function scheduleNameSuggest(tr, q) {
        clearTimeout(nameTimer);

        /* 种类少的批发商：本地清单直接过滤，连防抖都不用等 */
        const idx = whIndex.get(currentWholesaler());
        if (idx && idx.count > 0 && idx.count < 15) {
            const list = filterNameList(idx.list, q);
            if (list.length) renderNameTip(tr, list, q, 'wh-all');
            else if (q.length >= 2) loadNameSuggest(tr, q);   /* 本家没有 → 全库兜底 */
            else hideNameTip();
            return;
        }

        if (q.length < 2) { hideNameTip(); return; }

        const key = currentWholesaler() + '|' + q;
        if (nameCache.has(key)) {          /* 打过同样的字，直接秒出 */
            const c = nameCache.get(key);
            renderNameTip(tr, c.list, q, c.scope);
            return;
        }
        nameTimer = setTimeout(() => loadNameSuggest(tr, q), 180);
    }

    function rowHTML() {
        return '<tr class="item-row">'
            + '<td><input class="i-name" placeholder="商品名称" autocomplete="off"></td>'
            + '<td><input class="i-spec" placeholder="选填规格" autocomplete="off"></td>'
            + '<td><input class="i-unit" placeholder="箱 / 个"></td>'
            + '<td><input class="i-qty" type="number" step="1" min="0" data-prec="0" data-last="1" value="1"></td>'
            + '<td><input class="i-price" type="text" inputmode="decimal" value="0.00" autocomplete="off" placeholder="0.00"></td>'
            + '<td><input class="i-sub" type="text" inputmode="decimal" value="0.00" autocomplete="off" placeholder="或填总价"></td>'
            + '<td><button type="button" class="row-del" title="删除此行">×</button></td>'
            + '</tr>';
    }

    /* 单个模板节点：用 template 比 tbody 更轻，且不会触发额外的 DOM 解析副作用 */
    const rowTemplate = document.createElement('template');
    rowTemplate.innerHTML = rowHTML();

    function addRow(data, skipRecalc) {
        if (!itemsBody) return null;
        const tr = rowTemplate.content.firstElementChild.cloneNode(true);
        const pr = tr.querySelector('.i-price');

        if (data) {
            tr.querySelector('.i-name').value  = data.name  || '';
            tr.querySelector('.i-spec').value  = data.spec  || '';
            tr.querySelector('.i-unit').value  = data.unit  || '';
            tr.querySelector('.i-qty').value   = (data.qty   !== undefined && data.qty   !== null) ? data.qty   : 1;
            tr.querySelector('.i-price').value = (data.price !== undefined && data.price !== null) ? data.price : 0;
        }
        syncQtyStep(tr.querySelector('.i-qty'));
        formatPrice(pr);             /* 单价一律显示两位小数 */
        itemsBody.appendChild(tr);
        if (!skipRecalc) recalc();
        return tr;
    }

    /* 行内取值：表格结构由 rowHTML 固定，直接按单元格下标取，比每行两次 querySelector 快；
       万一结构被动过，退回按类名查找，不会因为拿不到节点就把金额算成 0 */
    const CELL_QTY = 3;
    const CELL_PRICE = 4;
    const CELL_SUB = 5;

    function cellInput(tr, idx) {
        const cell = tr.cells && tr.cells[idx];
        const el = cell ? cell.firstElementChild : null;
        if (el && el.tagName === 'INPUT') return el;
        return tr.querySelector(idx === CELL_QTY ? '.i-qty' : '.i-price');
    }

    function rowCalc(tr) {
        const qEl = cellInput(tr, CELL_QTY);
        const pEl = cellInput(tr, CELL_PRICE);
        const q = qEl ? (parseFloat(qEl.value) || 0) : 0;
        const p = pEl ? (parseFloat(pEl.value) || 0) : 0;
        let sub = q * p;
        /* 小计被手填成总价、还没失焦反算单价时，先用这个金额参与合计，
           否则底部合计会滞后一拍 */
        const sEl = tr.querySelector('.i-sub');
        if (tr.dataset.amt === '1' && sEl) {
            const a = parseFloat(sEl.value);
            if (isFinite(a) && a >= 0) sub = a;
        }
        return { q: q, p: p, sub: sub };
    }

    /* 只重算这一行的小计（敲字时立刻看到变化）；正在编辑小计框时不回写，免得打字被打断 */
    function recalcRow(tr) {
        if (!tr) return 0;
        const v = rowCalc(tr);
        const sEl = tr.querySelector('.i-sub');
        if (sEl && document.activeElement !== sEl) sEl.value = v.sub.toFixed(2);
        return v.sub;
    }

    /* 把小计框里手填的总价折算回单价（4 位小数），之后仍走「数量 × 单价」的常规口径 */
    function settleAmountRow(tr) {
        if (!tr) return;
        const sEl = tr.querySelector('.i-sub');
        if (!sEl) return;
        const qEl = cellInput(tr, CELL_QTY);
        const pEl = cellInput(tr, CELL_PRICE);
        const q  = qEl ? (parseFloat(qEl.value) || 0) : 0;
        const amount = parseFloat(sEl.value);

        if (!isFinite(amount) || amount < 0) {
            delete tr.dataset.amt;
            recalcRow(tr);
            return;
        }
        if (q <= 0) {
            /* 没数量就没法反算单价，退回自动值，并提示先填数量 */
            delete tr.dataset.amt;
            recalcRow(tr);
            toast('先填数量，才能按总价反算单价', 'error');
            return;
        }
        const price = Math.round((amount / q) * 10000) / 10000;
        if (pEl) {
            pEl.value = String(price);
            formatPrice(pEl);
        }
        delete tr.dataset.amt;
        hidePriceTip();
        recalcRow(tr);
    }

    /* 合计：数值都从内存里算，不再为了求和去查一遍 DOM */
    function recalcTotals() {
        if (!itemsBody) return;
        let total = 0;
        let qtyTotal = 0;
        for (const tr of itemsBody.rows) {
            const v = rowCalc(tr);
            total    += v.sub;
            qtyTotal += v.q;
        }
        if (grandQtyEl) {
            /* 去掉多余小数位：12.50 → 12.5，12.00 → 12 */
            grandQtyEl.textContent = String(parseFloat(qtyTotal.toFixed(2)) || 0);
        }
        if (grandTotalEl) grandTotalEl.textContent = '￥' + total.toFixed(2);
    }

    function recalc() {
        if (!itemsBody) return;
        for (const tr of itemsBody.rows) recalcRow(tr);
        recalcTotals();
    }

    on(itemsBody, 'input', (e) => {
        const t = e.target;

        /* 商品名变化 → 联想历史商品名；命中历史且单位为空时补单位 */
        if (t.classList.contains('i-name')) {
            const tr = t.closest('tr');
            hidePriceTip();                             /* 名称变了，旧价格提示作废 */
            maybeFillUnit(tr);
            /* 名称还在改，旧的规格候选先清掉，静默后再按新名称拉一遍 */
            const specEl = tr.querySelector('.i-spec');
            if (specEl) specDatalist(specEl).innerHTML = '';
            scheduleNameSuggest(tr, t.value.trim());
            scheduleSpecOptions(tr);
            return;
        }

        /* 小计框：直接填这一行的实付总价，失焦时反算单价；敲字期间合计先按手填金额走 */
        if (t.classList.contains('i-sub')) {
            const tr = t.closest('tr');
            let s = t.value.replace(/[^\d.]/g, '');
            const dot = s.indexOf('.');
            if (dot >= 0) s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '').slice(0, 2);
            if (s !== t.value) t.value = s;
            if (tr) tr.dataset.amt = '1';
            recalcRow(tr);                 /* 焦点在这一格，内部不会回写它的值 */
            recalcTotals();
            return;
        }

        /* 名称/单位不影响金额，敲字时不必重算整张表 */
        if (!t.classList.contains('i-qty') && !t.classList.contains('i-price')) return;
        const tr = t.closest('tr');
        /* 动了数量或单价，之前手填的总价就作废，回到「数量 × 单价」口径 */
        if (tr) delete tr.dataset.amt;
        if (t.classList.contains('i-qty')) {
            syncQtyStep(t);
        } else {
            sanitizePrice(t);      /* 单价只能是数字和一个小数点 */
            hidePriceTip();        /* 手打了单价就不用再提示了 */
        }
        /* 只刷这一行的小计 + 底部合计：行数多时也不卡 */
        recalcRow(tr);
        recalcTotals();
    });

    /* 批发商名变化 → 取该批发商的历史单价，并刷新所有行的建议列表 */
    on(fWholesaler, 'input', () => {
        clearTimeout(priceTimer);
        const name = currentWholesaler();
        if (!name) {
            refreshPriceTip();
            return;
        }
        priceTimer = setTimeout(() => {
            ensurePriceHistory(name);
            refreshPriceTip();
        }, 300);
    });

    on(fWholesaler, 'change', () => {
        const name = currentWholesaler();
        if (name) ensurePriceHistory(name);
        refreshPriceTip();
    });

    on(itemsBody, 'change', (e) => {
        const t = e.target;
        /* 商品名定格（Tab / 回车 / 失焦）→ 立刻把规格候选准备好 */
        if (t.classList.contains('i-name')) ensureSpecOptions(t.closest('tr'));
        if (t.classList.contains('i-qty')) syncQtyStep(t);
        /* 小计填完总价 → 按数量反算单价，之后仍按「数量 × 单价」口径落库 */
        if (t.classList.contains('i-sub')) {
            settleAmountRow(t.closest('tr'));
            recalcTotals();
            return;
        }
        /* 单价：失焦规整成 2~4 位小数；负数归零并刷新小计 */
        if (t.classList.contains('i-price')) {
            if (parseFloat(t.value) < 0) {
                t.value = '0';
                recalc();
            }
            formatPrice(t);
        }
    });

    /* 单价框一进来就弹历史价浮层；批发商是自动填充等没触发 input 的情况，这里补一次拉取 */
    on(itemsBody, 'focusin', (e) => {
        const t = e.target;

        if (t.classList.contains('i-price')) {
            const wn = currentWholesaler();
            if (wn && !priceHistory.has(wn)) {
                ensurePriceHistory(wn);
            } else {
                showPriceTip(t.closest('tr'));
            }
            return;
        }

        if (t.classList.contains('i-name')) {
            maybeFillUnit(t.closest('tr'));
            /* 点进名称框就试一把：这家种类少于 15，直接列清单，一个字都不用打 */
            openNamePicker(t.closest('tr'));
        }

        /* 点进规格框：按当前商品名把历史规格补一遍（名称是自动填的也会有候选） */
        if (t.classList.contains('i-spec')) {
            hideNameTip();
            ensureSpecOptions(t.closest('tr'));
        }
    });

    /* 失焦收起用的定时器：连续切行时只保留最后一次，避免旧定时器把新浮层收掉 */
    let nameBlurTimer = 0;
    let priceBlurTimer = 0;

    on(itemsBody, 'focusout', (e) => {
        if (e.target.classList.contains('i-name')) {
            clearTimeout(nameBlurTimer);
            nameBlurTimer = setTimeout(() => {
                const ae = document.activeElement;
                if (ae && nameTip && nameTip.contains(ae)) return;
                if (ae && ae.classList && ae.classList.contains('i-name')) return;
                hideNameTip();
            }, 120);
            return;
        }
        if (!e.target.classList.contains('i-price')) return;
        formatPrice(e.target);          /* 离开就定格两位小数 */
        /* 留一点时间给「点到浮层」：浮层按下时已 preventDefault，这里只是兜底 */
        clearTimeout(priceBlurTimer);
        priceBlurTimer = setTimeout(() => {
            const ae = document.activeElement;
            if (ae && priceTip && priceTip.contains(ae)) return;
            hidePriceTip();
        }, 120);
    });

    on(itemsBody, 'keydown', (e) => {
        const t = e.target;

        /* 商品名：↑↓ 选候选、回车填入、Esc 收起、Tab 直接收起 */
        if (t.classList.contains('i-name') && nameTip && nameTip.classList.contains('show')) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                moveNameIdx(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                const pick = nameTipData[nameTipIdx >= 0 ? nameTipIdx : 0];
                if (pick) pickName(t.closest('tr'), pick);
                else hideNameTip();
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                hideNameTip();
                return;
            }
            if (e.key === 'Tab') {
                hideNameTip();
            }
        }

        if (e.key === 'Escape' && priceTipRow) {
            e.preventDefault();
            hidePriceTip();
        }
        /* 单价：方向键不改数、回车即定格两位小数 */
        if (t.classList.contains('i-price')) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                formatPrice(t);
                hidePriceTip();
                recalc();
            }
        }
    });

    /* 单价框不吃滚轮：鼠标滚过表格不会顺手把数字改了 */
    on(itemsBody, 'wheel', (e) => {
        if (e.target.classList.contains('i-price') && document.activeElement === e.target) {
            e.preventDefault();
        }
    }, { passive: false });

    /* 页面滚动/缩放/点别处 → 收起，免得浮层飘在原地。
       注意：滚动发生在浮层内部（翻候选列表）时不能收起，否则一滚就没了 */
    function isInsideTip(target) {
        if (!target || !(target instanceof Node)) return false;
        return (priceTip && (target === priceTip || priceTip.contains(target))) ||
               (nameTip  && (target === nameTip  || nameTip.contains(target)));
    }

    on(window, 'scroll', (e) => {
        if (isInsideTip(e.target)) return;      /* 翻自己的候选列表，不算页面滚动 */
        hidePriceTip();
        hideNameTip();
    }, true);
    on(window, 'resize', () => { hidePriceTip(); hideNameTip(); });

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;      /* 极端情况下 target 不是元素，别在这里崩 */
        if (nameTipRow && !(nameTip && nameTip.contains(e.target)) && !e.target.closest('.i-name')) {
            hideNameTip();
        }
        if (!priceTipRow) return;
        if (priceTip && priceTip.contains(e.target)) return;
        if (e.target.closest('.i-price')) return;
        hidePriceTip();
    });

    on(itemsBody, 'click', (e) => {
        /* 已经是聚焦状态时再点输入框不会触发 focusin，这里补一次弹出 */
        if (e.target.classList.contains('i-price')) {
            hideNameTip();
            ensurePriceHistory(currentWholesaler());
            showPriceTip(e.target.closest('tr'));
            return;
        }
        if (e.target.classList.contains('i-name')) {
            openNamePicker(e.target.closest('tr'));
            return;
        }

        const del = e.target.closest('.row-del');
        if (!del) return;
        hidePriceTip();
        hideNameTip();
        const tr = del.closest('tr');

        /* 只剩一行时不清空表格结构，只把内容复位 */
        if (itemsBody.rows.length <= 1) {
            tr.querySelectorAll('input').forEach((i) => { i.value = ''; });
            tr.querySelector('.i-qty').value   = '1';
            tr.querySelector('.i-price').value = '0.00';
            syncQtyStep(tr.querySelector('.i-qty'));
            recalc();
            return;
        }
        tr.remove();
        recalc();
    });

    on(addRowBtn, 'click', () => {
        const tr = addRow();
        const nameEl = tr && tr.querySelector('.i-name');
        if (nameEl) nameEl.focus();
    });

    function resetForm() {
        hidePriceTip();
        hideNameTip();
        batchIdEl.value    = '';
        fWholesaler.value  = '';
        fOdateText.value   = TODAY;
        fOdateNative.value = TODAY;
        markInvalid(fOdateText, false);
        fNote.value        = '';
        setFlagOn(false);
        itemsBody.innerHTML = '';
        addRow();
        formTitle.textContent = '新增进货单';
        saveBtn.textContent   = '保存进货单';
        cancelBtn.style.display = 'none';
    }

    on(cancelBtn, 'click', () => {
        resetForm();
        switchTab('list');
    });

    /* ============================================================
       9. 保存进货单
       ============================================================ */
    async function submitBatch() {
        /* 填了总价但还没失焦就点保存：先把这些行折算成单价，避免提交旧的单价 */
        for (const tr of (itemsBody ? itemsBody.rows : [])) {
            if (tr.dataset.amt === '1') settleAmountRow(tr);
        }

        const items = [];
        for (const tr of (itemsBody ? itemsBody.rows : [])) {
            const nameEl = tr.querySelector('.i-name');
            if (!nameEl) continue;
            const name = nameEl.value.trim();
            if (!name) continue;
            const unitEl  = tr.querySelector('.i-unit');
            const specEl  = tr.querySelector('.i-spec');
            const qtyEl   = tr.querySelector('.i-qty');
            const priceEl = tr.querySelector('.i-price');
            items.push({
                name:  name,
                spec:  specEl  ? specEl.value.trim() : '',
                unit:  unitEl  ? unitEl.value.trim() : '',
                qty:   qtyEl   ? (parseFloat(qtyEl.value)   || 0) : 0,
                price: priceEl ? (parseFloat(priceEl.value) || 0) : 0
            });
        }

        /* 日期必须真实存在：2 月 31 日、13 月、0 日这些都拦下来 */
        const dateRes = checkDate(fOdateText.value);
        if (!dateRes.ok) {
            markInvalid(fOdateText, true);
            toast(dateRes.empty ? '请选择或输入进货日期' : dateRes.msg, 'error');
            fOdateText.focus();
            return;
        }
        markInvalid(fOdateText, false);
        fOdateText.value = dateRes.iso;
        fOdateNative.value = dateRes.iso;

        if (!items.length) {
            toast('请至少填写一个商品名称', 'error');
            return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = '保存中…';

        try {
            const editing = !!batchIdEl.value;
            const saveWholesaler = fWholesaler.value.trim();
            await callAPI('?ajax=save', {
                id:         editing ? parseInt(batchIdEl.value, 10) : 0,
                wholesaler: saveWholesaler,
                odate:      dateRes.iso,
                note:       fNote.value.trim(),
                flag:       (fFlag && fFlag.dataset.on === '1') ? 1 : 0,
                items:      items
            });

            toast(editing ? '修改已保存' : '保存成功', 'ok');
            priceHistory.clear();      /* 单价历史变了，下次重新拉 */
            nameCache.clear();         /* 商品名联想同理 */
            whIndex.clear();           /* 种类数变了，重新摸一遍 */
            resetForm();

            if (!editing) currentPage = 1;
            /* 三个请求互不依赖，并发发出，省掉一次串行往返；新出现的批发商也在里面同步。
               单据一变，若「供货商品与最新报价」正开着同一家，也顺手把它刷成最新 */
            await Promise.all([
                refreshList(currentPage),
                refreshStats(),
                loadWholesalers(),
                detailShopName === saveWholesaler ? refreshDetailIfOpen() : Promise.resolve()
            ]);
            switchTab('list');
        } catch (err) {
            toast(err.message || '网络错误，请重试', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = batchIdEl.value ? '保存修改' : '保存进货单';
        }
    }

    on(form, 'submit', (e) => {
        e.preventDefault();
        submitBatch();
    });

    /* ============================================================
       10. 列表刷新
       ============================================================ */
    /* 列表请求序号：连点翻页/搜索时，只让最后一次请求的结果落到页面上；
       同时在飞的上一次请求直接 abort，不再白跑一趟网络 */
    let listSeq = 0;
    let listAbort = null;

    async function refreshList(page) {
        const seq = ++listSeq;
        const p = page || 1;
        const q = searchInput ? searchInput.value.trim() : '';
        const d = dateFilterText ? normDate(dateFilterText.value) : '';
        const w = shopFilter ? shopFilter.value : '';
        const f = (flagFilter && flagFilter.checked) ? '1' : '';

        const params = new URLSearchParams({
            ajax: 'list', page: String(p), q: q, d: d, w: w, per: String(currentPer || 10), f: f
        });

        if (listAbort) listAbort.abort();          /* 取消上一次还没回来的请求 */
        const ctrl = new AbortController();
        listAbort = ctrl;

        try {
            const res = await fetch('?' + params.toString(), { cache: 'no-store', signal: ctrl.signal });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            if (seq !== listSeq) return;              /* 有更新的请求在路上，丢弃这次结果 */
            listBox.innerHTML = html;
        } catch (err) {
            if (err && err.name === 'AbortError') return;   /* 正常取消，不是错误 */
            if (seq === listSeq) toast('列表加载失败，请重试', 'error');
            return;
        } finally {
            if (listAbort === ctrl) listAbort = null;
        }

        /* 服务端会把越界页码收敛到最后一页，以页面上的高亮页码为准；
           筛到 0 条时没有分页条，页码回到第 1 页 */
        const activeBtn = listBox.querySelector('.page-btn.active');
        if (activeBtn) {
            currentPage = parseInt(activeBtn.dataset.page, 10) || p;
        } else if (!listBox.querySelector('.pager')) {
            currentPage = 1;
        } else {
            currentPage = p;
        }
        appliedDate = d;

        if (dateFilterText) dateFilterText.value = d;
        if (clearSearchBtn) clearSearchBtn.style.display = q.length > 0 ? 'flex' : 'none';
        renderChips();
    }

    async function refreshStats() {
        try {
            const d = await getJSON('?ajax=stats');
            if (statCount)       statCount.textContent       = d.total_count;
            if (statAmount)      statAmount.textContent      = d.total_amount;
            if (statMonthCount)  statMonthCount.textContent  = d.month_count;
            if (statMonthAmount) statMonthAmount.textContent = d.month_amount;
            if (flagCount)       flagCount.textContent       = d.flagged_count;
        } catch (err) { /* 统计失败不影响主流程 */ }
    }

    /* 每页条数下拉在分页区 → 事件委托 */
    on(listBox, 'change', (e) => {
        const sel = e.target.closest('.per-select');
        if (!sel) return;
        currentPer = parseInt(sel.value, 10) || 10;
        refreshList(1);
    });

    on(searchForm, 'submit', (e) => {
        e.preventDefault();
        refreshList(1);
    });

    /* ============================================================
       11. 列表内操作（事件委托：翻页 / 点条件 / 排序 / 打印 / 编辑 / 删除）
       ============================================================ */
    const NUMERIC_SORT_KEYS = ['qty', 'price', 'subtotal'];
    const SORT_COLUMN = { name: 2, unit: 3, qty: 4, price: 5, subtotal: 6 };

    /* 表头文字去掉排序箭头后的原标签 */
    const headerLabel = (th) => th.textContent.replace(/[↑↓]/g, '').trim();

    function sortBatchTable(th) {
        const table  = th.closest('table');
        const tbody  = table.querySelector('tbody');
        const rows   = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        if (!rows.length) return;

        const key = th.dataset.sort;

        /* 先把其它表头的箭头清掉 */
        $$('th', table).forEach((el) => {
            if (el === th) return;
            el.removeAttribute('data-dir');
            el.textContent = headerLabel(el);
        });

        const label = headerLabel(th);

        /* 点 # → 恢复默认顺序（按入库时的原始序号） */
        if (key === 'index') {
            rows.sort((a, b) => (parseInt(a.dataset.originIdx, 10) || 0) - (parseInt(b.dataset.originIdx, 10) || 0));
            th.removeAttribute('data-dir');
            th.textContent = label;
        } else {
            const isAsc = th.dataset.dir !== 'asc';
            th.dataset.dir = isAsc ? 'asc' : 'desc';
            th.textContent = label + (isAsc ? ' ↑' : ' ↓');

            const colIdx = SORT_COLUMN[key];
            const isNum  = NUMERIC_SORT_KEYS.indexOf(key) >= 0;

            /* 先取值再排序：比较函数里不再重复读 DOM 和解析数字 */
            const decorated = rows.map((row) => {
                const cell = row.cells[colIdx - 1];
                /* 名称格里还挂着规格小字，排序要取 data-v 上的纯名称 */
                const raw  = cell ? (cell.dataset && cell.dataset.v ? cell.dataset.v : cell.textContent.trim()) : '';
                return { row: row, v: isNum ? (parseFloat(raw.replace(/[^\d.-]/g, '')) || 0) : raw };
            });

            decorated.sort((a, b) => {
                if (isNum) return isAsc ? a.v - b.v : b.v - a.v;
                return isAsc ? a.v.localeCompare(b.v) : b.v.localeCompare(a.v);
            });

            rows.length = 0;
            decorated.forEach((d) => rows.push(d.row));
        }

        /* 用 DocumentFragment 一次性插入，避免逐行 append 触发多次重排 */
        const frag = document.createDocumentFragment();
        rows.forEach((row, i) => {
            const idxCell = row.querySelector('.idx-cell');
            if (idxCell) idxCell.textContent = i + 1;
            frag.appendChild(row);
        });
        tbody.appendChild(frag);
    }

    function buildPrintHTML(card) {
        const dateEl   = card.querySelector('.batch-date');
        const shopEl   = card.querySelector('.batch-shop');
        const noteEl   = card.querySelector('.batch-note');
        const flagEl   = card.querySelector('.flag-badge');
        const flagNoteEl = card.querySelector('.flag-note');
        const qtyEl    = card.querySelector('.batch-qty strong');
        const moneyEl  = card.querySelector('.batch-total strong');

        const date       = dateEl  ? dateEl.textContent.trim()  : '';
        const wholesaler = shopEl  ? shopEl.textContent.trim()  : '';
        const noteTxt    = noteEl  ? noteEl.textContent.replace('备注：', '')
                                            .replace(flagNoteEl ? flagNoteEl.textContent : '', '').trim() : '';
        const totalQty   = qtyEl   ? qtyEl.textContent.trim()   : '';
        const totalMoney = moneyEl ? moneyEl.textContent.trim() : '';

        let html = '<div class="print-header"><h2>进 货 单</h2></div>';
        /* 表格里的文字来自用户输入，拼进 innerHTML 前必须转义，否则商品名里带标签会把打印区搞乱 */
        html += '<div class="print-meta">';
        html += '<div><strong>批发商：</strong>' + (wholesaler === '未填写批发商' ? '—' : esc(wholesaler)) + '</div>';
        html += '<div><strong>日期：</strong>' + (date === '未填日期' ? '—' : esc(date)) + '</div>';
        /* 打了「待复核」的单，打印样张上也留一行，对着纸核对时一眼能看到 */
        if (flagEl) {
            const fNote = flagNoteEl ? flagNoteEl.textContent.replace('标记：', '').trim() : '';
            html += '<div><strong>标记：</strong>待复核' + (fNote ? '（' + esc(fNote) + '）' : '') + '</div>';
        }
        html += '</div>';
        html += '<table class="print-table">';
        html += '<thead><tr><th>#</th><th>商品名称</th><th>单位</th><th class="num">数量</th><th class="num">单价</th><th class="num">小计</th></tr></thead><tbody>';

        $$('.batch-items tbody tr', card).forEach((tr) => {
            const tds = tr.cells;
            if (tds.length < 6) return;             /* 结构异常的行直接跳过，别让打印崩掉 */
            const nmCell = tds[1];
            const spEl   = nmCell.querySelector('.sp');
            const nmTxt  = nmCell.dataset.v || (spEl ? nmCell.textContent.replace(spEl.textContent, '') : nmCell.textContent);
            html += '<tr>'
                + '<td>' + esc(tds[0].textContent) + '</td>'
                + '<td>' + esc(nmTxt)
                    + (spEl ? '<span class="sp">（' + esc(spEl.textContent) + '）</span>' : '')
                    + '</td>'
                + '<td>' + esc(tds[2].textContent) + '</td>'
                + '<td class="num">' + esc(tds[3].textContent) + '</td>'
                + '<td class="num">' + esc(tds[4].textContent) + '</td>'
                + '<td class="num">' + esc(tds[5].textContent) + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        html += '<div class="print-footer">';
        html += '<div>总数量：' + esc(totalQty) + '</div>';
        html += '<div>合计金额：' + esc(totalMoney) + '</div>';
        html += '</div>';
        if (noteTxt) html += '<div class="print-note"><strong>备注：</strong>' + esc(noteTxt) + '</div>';

        return html;
    }

    /* 打印排版样式直接从页面样式表里取（选择器以 #printArea 开头的那些规则），
       打印窗口和页面内打印共用同一份，不再在 JS 里复制一遍。
       只补几条打印窗口自己需要的页面级设置 */
    function collectPrintCss() {
        const out = [];
        Array.from(document.styleSheets || []).forEach((sheet) => {
            let rules = null;
            try { rules = sheet.cssRules; } catch (e) { rules = null; }   /* 跨域样式表读不了，跳过 */
            if (!rules) return;
            Array.from(rules).forEach((rule) => {
                const txt = (rule && rule.cssText) ? rule.cssText : '';
                if (txt.indexOf('#printArea') === 0) out.push(txt);
            });
        });
        return out.join('\n');
    }
    const PRINT_CSS  = collectPrintCss();
    const PRINT_PAGE = '@page{size:A4 portrait;margin:12mm;}'
        + 'html,body{margin:0;padding:0;height:auto;min-height:0;background:#fff;color:#000;'
        + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '#printArea{display:block !important;}';
    /* 打印一张进货单：用隐藏 iframe 排版打印，打印完立刻拆掉。
       不再开新标签页，取消打印后不会残留一个 about:blank / 打印样式的窗口。
       iOS 的 iframe 不支持 print()，退回页面内打印区。

       连点保护：同一时刻只允许一次打印。
       正在打印时再点，直接忽略（不会又弹一个对话框）；
       取消/打印结束后立刻解锁，可以马上再点。 */
    let printBusy = false;
    function printBatch(card) {
        if (printBusy) { toast('正在打印，等这个对话框关掉再点'); return; }

        const html = buildPrintHTML(card);
        const doc  = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width,initial-scale=1">'
            + '<title>进货单</title><style>' + PRINT_CSS + PRINT_PAGE + '</style></head>'
            + '<body><div id="printArea">' + html + '</div></body></html>';

        const ua = navigator.userAgent || '';
        const iOS = /iPad|iPhone|iPod/.test(ua)
            || (navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1);
        if (iOS) {
            printBusy = true;
            printArea.innerHTML = html;
            const unlock = () => { printBusy = false; };
            window.addEventListener('afterprint', unlock, { once: true });
            const t = setTimeout(unlock, 15000);      /* 兜底解锁 */
            try {
                const t0 = performance.now();
                window.print();                        /* Safari 上是异步返回，对话框还开着 */
                if (performance.now() - t0 > 150) {    /* 同步阻塞型：返回即已关闭 */
                    clearTimeout(t);
                    window.removeEventListener('afterprint', unlock);
                    unlock();
                }
            } catch (e) {
                clearTimeout(t);
                unlock();
            }
            return;
        }

        printBusy = true;

        let frame = null;
        try { frame = document.createElement('iframe'); } catch (e) { frame = null; }

        /* 兜底：iframe 用不了就退回页面内打印 */
        const fallback = () => {
            if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
            printArea.innerHTML = html;
            window.print();
            printBusy = false;
        };

        if (!frame) { fallback(); return; }

        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none;';
        document.body.appendChild(frame);

        /* 统一清理：打印对话框关掉（打印或取消）后拆掉 iframe，同时解锁。
           再挂一个 15 秒兜底，避免某个浏览器不触发 afterprint 时 iframe 一直挂着、按钮一直锁着。 */
        let removed = false;
        let unlockTimer = null;
        const cleanup = () => {
            if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null; }
            printBusy = false;
            if (removed) return;
            removed = true;
            setTimeout(() => {
                try { if (frame.parentNode) frame.parentNode.removeChild(frame); } catch (e) {}
            }, 400);
        };
        unlockTimer = setTimeout(cleanup, 15000);

        /* 同一时间只留一个打印壳子 */
        document.querySelectorAll('iframe[data-print-frame]').forEach((f) => {
            try { if (f.parentNode) f.parentNode.removeChild(f); } catch (e) {}
        });
        frame.setAttribute('data-print-frame', '1');

        try {
            const fd = frame.contentWindow.document;
            fd.open();
            fd.write(doc);
            fd.close();
            frame.contentWindow.onafterprint = cleanup;

            setTimeout(() => {
                /* 期间被新的一次打印顶掉、或已拆掉，就静默退出，不再弹第二个对话框 */
                if (!frame.parentNode) { printBusy = false; return; }
                try {
                    frame.contentWindow.focus();
                    const t0 = performance.now();
                    frame.contentWindow.print();
                    const cost = performance.now() - t0;
                    /* 同步阻塞型（桌面）：返回时对话框已关闭，可立刻拆 */
                    if (cost > 150) cleanup();
                } catch (e) {
                    fallback();
                }
            }, 250);
        } catch (e) {
            fallback();
        }
    }

    on(listBox, 'click', async (e) => {
        /* ---- 翻页 ---- */
        const pageBtn = e.target.closest('.page-btn');
        if (pageBtn) {
            if (pageBtn.disabled || pageBtn.classList.contains('active')) return;
            await refreshList(parseInt(pageBtn.dataset.page, 10) || 1);
            scrollToPanel('list');
            return;
        }

        /* ---- 点日期 → 加入日期筛选 ---- */
        const dateEl = e.target.closest('.js-set-date');
        if (dateEl) {
            const dateStr = dateEl.textContent.trim();
            if (dateStr !== '未填日期' && dateFilterText) {
                dateFilterText.value = dateStr;
                if (dateFilterNative) dateFilterNative.value = dateStr;
                refreshList(1);
                scrollToPanel('list');
            }
            return;
        }

        /* ---- 点批发商 → 加入批发商筛选 ---- */
        const shopEl = e.target.closest('.js-set-shop');
        if (shopEl) {
            const shopName = shopEl.dataset.shop || '';
            if (shopName && shopFilter) {
                shopFilter.value = shopName;
                refreshList(1);
                scrollToPanel('list');
            }
            return;
        }

        /* ---- 明细表头排序 ---- */
        const th = e.target.closest('th[data-sort]');
        if (th) {
            sortBatchTable(th);
            return;
        }

        /* ---- 点「待复核」徽章不再有任何动作，纯标记展示 ---- */

        /* ---- 打印 ---- */
        const printBtn = e.target.closest('.js-print');
        if (printBtn) {
            printBatch(printBtn.closest('.batch'));
            return;
        }

        /* ---- 编辑 ---- */
        const editBtn = e.target.closest('.js-edit');
        if (editBtn) {
            try {
                const data = await getJSON('?ajax=batch&id=' + encodeURIComponent(editBtn.dataset.id));
                if (!data.ok) throw new Error(data.msg || '加载失败');

                const b = data.batch;
                batchIdEl.value    = b.id;
                fWholesaler.value  = b.wholesaler || '';
                fOdateText.value   = b.odate || TODAY;
                fOdateNative.value = b.odate || TODAY;
                fNote.value        = b.note || '';
                if (fFlag) setFlagOn(!!b.flag);
                markInvalid(fOdateText, false);      /* 清掉上一次残留的红框 */

                itemsBody.innerHTML = '';
                hidePriceTip();
                hideNameTip();
                const list = b.items || [];
                list.forEach((it) => addRow(it, true));   /* 批量插入时不逐行重算 */
                if (!list.length) addRow(true);
                recalc();
                ensurePriceHistory(currentWholesaler());  /* 顺带把历史单价建议挂好 */
                refreshPriceTip();

                formTitle.textContent = '编辑进货单';
                saveBtn.textContent   = '保存修改';
                cancelBtn.style.display = 'inline-flex';

                switchTab('add');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (err) {
                toast(err.message || '加载失败', 'error');
            }
            return;
        }

        /* ---- 删除 ---- */
        const delBtn = e.target.closest('.js-del');
        if (!delBtn) return;
        if (!confirm('确定要删除这张进货单吗？明细会一并删除。')) return;

        try {
            await callAPI('?ajax=delete', { id: parseInt(delBtn.dataset.id, 10) });
            toast('已删除', 'ok');
            priceHistory.clear();      /* 历史单价可能因此变化，清缓存 */
            nameCache.clear();         /* 商品名联想同理 */
            whIndex.clear();           /* 种类数变了，重新摸一遍 */
            /* 批发商那栏的单数/累计金额也跟着变了，必须一起刷新，否则一直显示旧数字；
               删掉的可能正是详情里那家的单据，详情也一并重拉 */
            await Promise.all([refreshList(currentPage), refreshStats(), loadWholesalers(), refreshDetailIfOpen()]);
        } catch (err) {
            toast(err.message || '删除失败', 'error');
        }
    });

    /* ============================================================
       12. 批发商管理
       ============================================================ */
    const shopForm      = $('shopForm');
    const shopBox       = $('shopBox');
    const shopIdEl      = $('shopId');
    const sName         = $('s-name');
    const sContact      = $('s-contact');
    const sPhone        = $('s-phone');
    const sNote         = $('s-note');
    const shopSaveBtn   = $('shopSaveBtn');
    const shopCancelBtn = $('shopCancelBtn');
    const shopFormTitle = $('shopFormTitle');
    const shopCount     = $('shopCount');
    const shopKeyword   = $('shopKeyword');
    const clearShopKw   = $('clearShopKeyword');
    const shopFormCard  = $('shopFormCard');
    const shopListCard  = $('shopListCard');
    const shopDetailCard= $('shopDetailCard');
    const shopDetailTitle = $('shopDetailTitle');
    const shopDetailMeta  = $('shopDetailMeta');
    const shopDetailBox   = $('shopDetailBox');
    const shopDetailBack  = $('shopDetailBack');
    const detailKeyword   = $('detailKeyword');
    const clearDetailKw   = $('clearDetailKeyword');
    const detailCount     = $('detailCount');
    const panelDetailThead = document.querySelector('#shopDetailCard thead');

    let shopData    = [];
    let detailProducts = [];
    let detailSortKey = '';
    let detailSortDir = 'desc';
    /* 当前详情页对应的是哪家批发商：录单 / 改单 / 改档 / 删单之后，
       要据此把「供货商品与最新报价」就地刷成最新，而不是等用户退出去再点一次 */
    let detailShopId   = 0;
    let detailShopName = '';

    /* 把搜索用的小写索引预先算一次，输入时不再逐行拼字符串；
       列表只展示名称 / 联系人 / 联系电话，所以备注不参与匹配——否则搜只命中备注的词，
       会冒出一家看起来毫不相干的批发商 */
    function setShopData(list) {
        shopData = (list || []).map((w) => {
            const blob = [w.name, w.contact, w.phone].join(' ').toLowerCase();
            return Object.assign({}, w, { _blob: blob });
        });
    }


    function renderShopTable() {
        if (!shopBox) return;

        const kw = shopKeyword ? shopKeyword.value.trim().toLowerCase() : '';

        let rows = kw
            ? shopData.filter((w) => w._blob.indexOf(kw) >= 0)
            : shopData.slice();

        if (shopCount) {
            shopCount.textContent = (kw ? '匹配 ' : '共 ') + rows.length + ' 家';
        }

        if (!rows.length) {
            shopBox.innerHTML = emptyRow(5, kw ? '没有匹配的批发商' : '暂无批发商，先在上方新增一个吧');
            return;
        }

        shopBox.innerHTML = rows.map((w, idx) => {
            const phones = splitPhone(w.phone);
            return '<tr class="row-click js-shop-open" data-id="' + w.id + '" data-name="' + esc(w.name) + '" title="查看商品与最新报价">'
            + '<td class="idx-cell">' + (idx + 1) + '</td>'
            + '<td class="wh-name">' + hl(w.name, kw) + '</td>'
            + '<td class="ct-cell">' + (w.contact ? hl(w.contact, kw) : DASH) + '</td>'
            + '<td class="ph-cell">' + (phones.length
                ? phones.map((p) => '<span class="ph-line">' + hl(p, kw) + '</span>').join('')
                : DASH) + '</td>'
            + '<td class="ops">'
            +   '<button type="button" class="btn-mini js-shop-edit" data-id="' + w.id + '">编辑</button>'
            +   '<button type="button" class="btn-mini danger js-shop-del" data-id="' + w.id + '">删除</button>'
            + '</td>'
            + '</tr>';
        }).join('');
    }


    /* 同步：筛选下拉 + 录入页联想列表 */
    function renderShopOptions() {
        if (shopFilter) {
            const cur = shopFilter.value;
            const opts = ['<option value="">全部批发商</option>'].concat(
                shopData.map((w) => '<option value="' + esc(w.name) + '">' + esc(w.name) + '</option>')
            );
            shopFilter.innerHTML = opts.join('');
            shopFilter.value = cur;
        }
        const dl = $('wholesalerOptions');
        if (dl) {
            dl.innerHTML = shopData.map((w) => '<option value="' + esc(w.name) + '"></option>').join('');
        }
    }

    async function loadWholesalers() {
        try {
            const d = await getJSON('?ajax=whlist');
            if (!d.ok) throw new Error(d.msg || '加载失败');

            setShopData(d.list);
            renderShopTable();
            renderShopOptions();
            renderChips();      /* 改名/删档后，筛选标签上的名字也要跟着更新 */
        } catch (err) {
            if (shopCount) shopCount.textContent = '加载失败';
        }
    }

    const findShop = (id) => shopData.find((x) => x.id === id) || null;

    function resetShopForm() {
        if (!shopForm) return;
        shopIdEl.value     = '';
        sName.value        = '';
        sContact.value     = '';
        sPhone.value       = '';
        sNote.value        = '';
        shopFormTitle.textContent = '新增批发商';
        shopSaveBtn.textContent   = '保存批发商';
        shopCancelBtn.style.display = 'none';
    }

    on(shopForm, 'submit', async (e) => {
        e.preventDefault();

        const name = sName.value.trim();
        if (!name) {
            toast('请填写批发商名称', 'error');
            sName.focus();
            return;
        }

        shopSaveBtn.disabled = true;
        shopSaveBtn.textContent = '保存中…';

        try {
            const editing = !!shopIdEl.value;
            await callAPI('?ajax=wsave', {
                id:      editing ? parseInt(shopIdEl.value, 10) : 0,
                name:    name,
                contact: sContact.value.trim(),
                phone:   sPhone.value.trim(),
                note:    sNote.value.trim()
            });

            const editedId = editing ? parseInt(shopIdEl.value, 10) : 0;
            toast(editing ? '修改已保存' : '新增成功', 'ok');
            resetShopForm();
            /* 改名会同步历史进货单，所以列表也跟着刷；两个请求并发 */
            await Promise.all([loadWholesalers(), refreshList(currentPage)]);
            /* 改的正好是当前打开的详情：标题里的名字、联系人、电话、备注立刻跟着变 */
            if (editedId && detailShopId === editedId) {
                detailShopName = name;
                refreshDetailIfOpen();
            }
        } catch (err) {
            toast(err.message || '网络错误，请重试', 'error');
        } finally {
            shopSaveBtn.disabled = false;
            shopSaveBtn.textContent = shopIdEl.value ? '保存修改' : '保存批发商';
        }
    });

    on(shopCancelBtn, 'click', resetShopForm);

    on(shopBox, 'click', async (e) => {
        /* ---- 编辑 ---- */
        const editBtn = e.target.closest('.js-shop-edit');
        if (editBtn) {
            const w = findShop(parseInt(editBtn.dataset.id, 10));
            if (!w) return;
            shopIdEl.value = w.id;
            sName.value    = w.name;
            sContact.value = w.contact || '';
            sPhone.value   = w.phone   || '';
            sNote.value    = w.note    || '';
            shopFormTitle.textContent = '编辑批发商';
            shopSaveBtn.textContent   = '保存修改';
            shopCancelBtn.style.display = 'inline-flex';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            sName.focus();
            return;
        }

        /* ---- 删除档案（不动进货单） ---- */
        const delBtn = e.target.closest('.js-shop-del');
        if (delBtn) {
            const w = findShop(parseInt(delBtn.dataset.id, 10));
            const tip = w
                ? ('确定删除批发商「' + w.name + '」吗？\n\n只删除批发商档案，' + w.orders + ' 张进货单记录不会被删除。')
                : '确定删除该批发商吗？';
            if (!confirm(tip)) return;

            try {
                const deletedId = parseInt(delBtn.dataset.id, 10);
                await callAPI('?ajax=wdelete', { id: deletedId });
                toast('已删除', 'ok');
                if (shopIdEl.value === delBtn.dataset.id) resetShopForm();
                /* 删掉的正是当前打开的详情：没有这家了，退回列表，别留一张查不到的旧表 */
                if (detailShopId === deletedId) {
                    detailShopId = 0;
                    detailShopName = '';
                    showShopDetail(false);
                }
                /* 下拉框少了一项，若正好是当前筛选值，列表要跟着变，标签也要同步 */
                const gone = w ? w.name : '';
                if (gone && shopFilter && shopFilter.value === gone) shopFilter.value = '';
                await Promise.all([loadWholesalers(), refreshList(currentPage)]);
            } catch (err) {
                toast(err.message || '删除失败', 'error');
            }
            return;
        }

        /* ---- 点整行 → 查看供货商品与最新报价 ---- */
        const row = e.target.closest('.js-shop-open');
        if (row) openShopDetail(row.dataset.id, row.dataset.name);
    });



    /* ============================================================
       13. 批发商详情：供货商品 + 最新报价
       ============================================================ */
    function showShopDetail(show) {
        if (!shopDetailCard || !shopListCard) return;
        shopDetailCard.style.display = show ? 'block' : 'none';
        shopListCard.style.display   = show ? 'none'  : 'block';
        if (shopFormCard) shopFormCard.style.display = show ? 'none' : 'block';
    }

    /* 详情表排序：取值规则与明细表一致，数值列按数字比、文字列按字典序、日期按字符串比 */
    const toNum = (v) => parseFloat(String(v === undefined || v === null ? '' : v).replace(/[^\d.-]/g, '')) || 0;

    function detailSortValue(p, key) {
        switch (key) {
            case 'name':  return (p.name || '') + ' ' + (p.spec || '');   /* 同名不同规格也能排开 */
            case 'unit':  return p.unit || '';
            case 'times': return toNum(p.times);
            case 'qty':   return toNum(p.qty);
            case 'price': return toNum(p.price);
            case 'odate': return p.odate || '';                          /* YYYY-MM-DD，直接字符串比较 */
            case 'range': return toNum(p.min);                           /* 区间按下限排 */
            default:      return '';
        }
    }

    function updateDetailSortIndicators() {
        $$('#shopDetailCard th[data-sort]').forEach((th) => {
            const label = th.dataset.label || headerLabel(th);
            if (th.dataset.sort === detailSortKey) {
                th.classList.add('sorted');
                th.textContent = label + (detailSortDir === 'asc' ? ' ↑' : ' ↓');
            } else {
                th.classList.remove('sorted');
                th.textContent = label;
            }
        });
    }

    /* 按关键词渲染详情商品表（前端本地过滤 + 高亮 + 排序） */
    function renderDetailTable() {
        if (!shopDetailBox) return;

        const kw  = detailKeyword ? detailKeyword.value.trim() : '';
        const low = kw.toLowerCase();
        const all = detailProducts;
        let rows  = low === '' ? all.slice() : all.filter((p) => p._blob.indexOf(low) !== -1);

        if (detailSortKey) {
            const dir = detailSortDir === 'asc' ? 1 : -1;
            const key = detailSortKey;
            rows.sort((a, b) => {
                const va = detailSortValue(a, key);
                const vb = detailSortValue(b, key);
                if (typeof va === 'number') return va === vb ? 0 : (va - vb) * dir;
                return va === vb ? 0 : va.localeCompare(vb) * dir;
            });
        }

        if (detailCount) {
            detailCount.innerHTML = all.length === 0 ? ''
                : (low === ''
                    ? '共 <b>' + all.length + '</b> 种商品'
                    : '匹配 <b>' + rows.length + '</b> / ' + all.length + ' 种商品');
        }

        if (!rows.length) {
            shopDetailBox.innerHTML = emptyRow(8, all.length === 0
                ? '该批发商还没有进货记录，先在「录入进货单」里记一笔吧'
                : '没有匹配「' + esc(kw) + '」的商品');
            return;
        }

        shopDetailBox.innerHTML = rows.map((p, i) => {
            const range = (p.min === p.max) ? ('￥' + esc(p.min)) : ('￥' + esc(p.min) + ' ~ ￥' + esc(p.max));
            return '<tr>'
                + '<td class="idx-cell">' + (i + 1) + '</td>'
                + '<td class="pd-name">' + hl(p.name, kw)
                    + (p.spec ? '<span class="sp">' + esc(p.spec) + '</span>' : '')
                    + '</td>'
                + '<td>' + orDash(p.unit) + '</td>'
                + '<td class="num">' + p.times + '</td>'
                + '<td class="num">' + esc(p.qty) + '</td>'
                + '<td class="num detail-new-price">￥' + esc(p.price) + '</td>'
                + '<td class="num">' + orDash(p.odate) + '</td>'
                + '<td class="num">' + range + '</td>'
                + '</tr>';
        }).join('');
    }

    async function openShopDetail(id, name, silent) {
        if (!shopDetailCard) return;

        detailShopId   = parseInt(id, 10) || 0;
        detailShopName = name || '';

        /* silent：数据变动后原地刷新，保留用户挑的排序、填的搜索词，也不跳转滚动 */
        if (!silent) {
            detailProducts = [];
            detailSortKey  = '';
            detailSortDir  = 'desc';
            updateDetailSortIndicators();
            if (detailKeyword) detailKeyword.value = '';
            if (clearDetailKw) clearDetailKw.style.display = 'none';
            if (detailCount)   detailCount.innerHTML = '';

            /* 标题里批发商名是主角（放大加粗），「供货商品与最新报价」只是灰色小字说明 */
            shopDetailTitle.innerHTML = '<span class="sd-name">' + esc(name || '批发商') + '</span>'
                + '<span class="sd-sub">供货商品与最新报价</span>';
            shopDetailMeta.innerHTML = '<span class="dh-muted">加载中…</span>';
            shopDetailBox.innerHTML  = '';
            showShopDetail(true);
            scrollToPanel('shop');
        }

        try {
            const d = await getJSON('?ajax=whdetail&id=' + encodeURIComponent(id));
            if (!d.ok) throw new Error(d.msg || '加载失败');

            const w = d.wholesaler || {};
            /* 标签 / 值成对铺进网格；备注的值跨列、最多两行；多个电话用间隔号连成一行 */
            const dash = '<b class="dh-muted">—</b>';
            const cell = (k, v, cls) => '<em>' + k + '</em>'
                + (v ? '<b' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(v) + '</b>' : dash);
            const stat = (k, v, cls) => '<span' + (cls ? ' class="' + cls + '"' : '') + '><em>' + k + '</em><b>' + v + '</b></span>';
            const phones = (w.phone || '').split(/[,，、;；/]+/).map((s) => s.trim()).filter(Boolean).join(' · ');
            shopDetailMeta.innerHTML =
                cell('联系人', w.contact) + cell('电话', phones)
                + cell('备注', w.note, 'dh-note')
                + '<span class="dh-stats">'
                    + stat('进货', d.orders + ' 单')
                    + stat('商品', d.sku_count + ' 种')
                    + stat('数量', d.total_qty)
                    + stat('累计', '￥' + d.total_amount, 'dh-money')
                + '</span>';

            /* 预先把商品名称拼成小写索引，输入时只做 indexOf（单位不参与搜索） */
            detailProducts = (d.products || []).map((p) => Object.assign({}, p, {
                _blob: (p.name || '').toLowerCase()
            }));
            renderDetailTable();
        } catch (err) {
            if (silent) {   /* 静默刷新失败：保留原表，只在标题下方提示一次，不把已有数据清空 */
                shopDetailMeta.innerHTML = '<span class="dh-muted">刷新失败：' + esc(err.message || '网络错误') + '</span>';
                return;
            }
            shopDetailMeta.innerHTML = '<span class="dh-muted">加载失败：' + esc(err.message || '网络错误') + '</span>';
            shopDetailBox.innerHTML  = emptyRow(8, '下拉详情失败，请返回重试');
        }
    }

    /* 详情页正在看时，数据一变就把它重新拉一遍；没打开则什么也不做 */
    function refreshDetailIfOpen() {
        if (!shopDetailCard || !detailShopId) return Promise.resolve();
        if (shopDetailCard.style.display === 'none') return Promise.resolve();
        return openShopDetail(detailShopId, detailShopName, true);
    }

    on(shopDetailBack, 'click', () => {
        showShopDetail(false);
        scrollToPanel('shop');
    });

    /* 详情表表头排序：点 # 恢复默认顺序；文字列第一次从小到大，数值/日期列第一次从大到小 */
    const DETAIL_TEXT_ASC = ['name', 'unit'];
    on(panelDetailThead, 'click', (e) => {
        const th = e.target.closest('th[data-sort]');
        if (!th) return;
        const key = th.dataset.sort;

        if (key === 'index') {
            detailSortKey = '';
            detailSortDir = 'desc';
            updateDetailSortIndicators();
            renderDetailTable();
            return;
        }

        if (detailSortKey === key) {
            detailSortDir = detailSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            detailSortKey = key;
            detailSortDir = DETAIL_TEXT_ASC.indexOf(key) >= 0 ? 'asc' : 'desc';
        }
        updateDetailSortIndicators();
        renderDetailTable();
    });

    /* 详情内的商品搜索（本地过滤，不请求后端；命中处高亮） */
    on(detailKeyword, 'input', () => {
        if (clearDetailKw) clearDetailKw.style.display = detailKeyword.value ? 'flex' : 'none';
        renderDetailTable();
    });

    on(detailKeyword, 'keydown', (e) => {
        if (e.key === 'Escape' && detailKeyword.value !== '') {
            e.preventDefault();
            detailKeyword.value = '';
            if (clearDetailKw) clearDetailKw.style.display = 'none';
            renderDetailTable();
        }
    });

    on(clearDetailKw, 'click', () => {
        detailKeyword.value = '';
        clearDetailKw.style.display = 'none';
        renderDetailTable();
        detailKeyword.focus();
    });

    /* 批发商列表内的关键词过滤（本地过滤，不请求后端） */
    on(shopKeyword, 'input', () => {
        if (clearShopKw) clearShopKw.style.display = shopKeyword.value ? 'flex' : 'none';
        renderShopTable();
    });

    on(clearShopKw, 'click', () => {
        shopKeyword.value = '';
        clearShopKw.style.display = 'none';
        renderShopTable();
        shopKeyword.focus();
    });

    /* ============================================================
       14. 初始化
       ============================================================ */
    resetForm();
    resetShopForm();
    renderChips();
    loadWholesalers();
})();
</script>
</body>
</html>
