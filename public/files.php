<?php
/**
 * 单文件文件管理服务
 *
 * 功能：文件上传/删除、视频播放、图片预览、后台下载
 * 使用：重命名此文件后放入任意公共目录即可使用
 * 文件存储在 <脚本名>/ 目录下，由Web服务器直接处理下载
 *
 * 部署说明：
 * 1. 确保Web服务器用户(如www-data)对脚本所在目录有写权限
 * 2. 目录会自动创建，权限设为0777确保可写
 * 3. 如遇权限问题，手动运行: chown -R www-data:www-data <目录> && chmod -R 755 <目录>
 *
 * @license MIT
 */

// 配置
define('SCRIPT_NAME', pathinfo(__FILE__, PATHINFO_FILENAME));
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . '.' . SCRIPT_NAME);
define('FILES_DIR', __DIR__ . DIRECTORY_SEPARATOR . SCRIPT_NAME);
define('TASKS_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'tasks');
define('ALLOWED_EXTENSIONS', null); // null = 允许所有，或 ['jpg', 'png', 'mp4', ...]

define('AUTH_PASS', getenv('AUTH_PASS') ?: ''); // 登录密码，从环境变量AUTH_PASS读取，留空则不启用认证

// 初始化目录
function init_directories() {
    $dirs = [DATA_DIR, FILES_DIR, TASKS_DIR];
    $errors = [];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                $errors[] = "无法创建目录: $dir";
                continue;
            }
        }
        
        if (!is_writable($dir)) {
            if (!chmod($dir, 0777)) {
                $errors[] = "目录不可写，请检查权限: $dir";
            }
        }
    }
    
    return $errors;
}

$dirErrors = init_directories();
if (!empty($dirErrors) && php_sapi_name() === 'cli') {
    foreach ($dirErrors as $err) {
        echo "Warning: $err\n";
    }
}

// 生成CSRF Token
function csrf_token(): string {
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool {
    $token = $_SERVER['HTTP_X_CSRF'] ?? $_POST['_csrf'] ?? '';
    return hash_equals($_SESSION['csrf'] ?? '', $token);
}

function check_auth(): bool {
    if (AUTH_PASS === '') {
        return true;
    }
    
    // 检查session中是否已登录
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        return true;
    }
    
    // 首次认证 - 只验证密码，忽略用户名
    if (!isset($_SERVER['PHP_AUTH_PW'])) {
        return false;
    }
    
    if ($_SERVER['PHP_AUTH_PW'] === AUTH_PASS) {
        $_SESSION['authenticated'] = true;
        return true;
    }
    
    return false;
}

function require_auth(): void {
    if (!check_auth()) {
        header('WWW-Authenticate: Basic realm="File Manager"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
}

function logout(): void {
    $_SESSION['authenticated'] = false;
}

// 安全处理文件名
function safe_filename(string $name): string {
    $name = basename($name);
    // 允许Unicode字母、数字、点、横线，替换其他字符为下划线
    $name = preg_replace('/[^\p{L}\p{N}\.\-]/u', '_', $name);
    $name = preg_replace('/_{2,}/', '_', $name);
    $name = trim($name, '_');
    return $name ?: 'file_' . time();
}

// 检查文件扩展名
function check_extension(string $filename): bool {
    if (ALLOWED_EXTENSIONS === null) {
        return true;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ALLOWED_EXTENSIONS, true);
}

// 从URL推断扩展名
function guess_extension_from_url(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext && strlen($ext) <= 5 && ctype_alnum($ext)) {
        return $ext;
    }
    return '';
}

// 生成唯一文件名（避免覆盖）
function unique_filename(string $filename): string {
    $path = FILES_DIR . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return $filename;
    }

    $name = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $counter = 1;

    while (file_exists(FILES_DIR . DIRECTORY_SEPARATOR . $name . '_' . $counter . ($ext ? '.' . $ext : ''))) {
        $counter++;
    }

    return $name . '_' . $counter . ($ext ? '.' . $ext : '');
}

// 获取文件列表
function get_files(): array {
    $files = [];
    $handle = opendir(FILES_DIR);
    if ($handle === false) {
        return $files;
    }
    while (($file = readdir($handle)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = FILES_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $files[] = [
            'name' => $file,
            'size' => filesize($path),
            'time' => filemtime($path),
            'type' => mime_content_type($path) ?: 'application/octet-stream',
        ];
    }
    closedir($handle);
    usort($files, fn($a, $b) => $b['time'] - $a['time']);
    return $files;
}

// 获取下载任务
function get_tasks(): array {
    $tasks = [];
    $now = time();
    $handle = opendir(TASKS_DIR);
    if ($handle === false) {
        return $tasks;
    }
    while (($file = readdir($handle)) !== false) {
        if ($file === '.' || $file === '..' || pathinfo($file, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }
        $taskPath = TASKS_DIR . DIRECTORY_SEPARATOR . $file;
        $data = json_decode(file_get_contents($taskPath), true);
        
        // 清理已完成超过30秒的任务
        if (isset($data['status']) && $data['status'] === 'completed' && isset($data['completed'])) {
            if ($now - $data['completed'] > 30) {
                unlink($taskPath);
                continue;
            }
        }
        
        if ($data) {
            $tasks[] = $data + ['id' => substr($file, 0, -5)];
        }
    }
    closedir($handle);
    return $tasks;
}

// 获取当前脚本的URL路径
function self_url(): string {
    return htmlspecialchars($_SERVER['PHP_SELF']);
}

// 获取files目录的URL路径
function files_url(): string {
    $scriptDir = dirname($_SERVER['PHP_SELF']);
    $path = htmlspecialchars($scriptDir . '/' . SCRIPT_NAME);
    return preg_replace('/^(\/\/)/', '/', $path);
}

// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 处理API请求
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'list':
            header('Content-Type: application/json');
            echo json_encode(['files' => get_files(), 'tasks' => get_tasks()]);
            exit;

        case 'upload':
            require_auth();
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            if (!csrf_check()) {
                throw new Exception('CSRF token mismatch', 403);
            }

            $filename = safe_filename($_POST['filename'] ?? $_FILES['file']['name'] ?? 'file');
            if (!check_extension($filename)) {
                throw new Exception('File type not allowed', 400);
            }

            $append = isset($_GET['append']) && $_GET['append'] === '1';
            $target = FILES_DIR . DIRECTORY_SEPARATOR . $filename;

            // 非追加模式且文件存在时，生成唯一文件名
            if (!$append && file_exists($target)) {
                $filename = unique_filename($filename);
                $target = FILES_DIR . DIRECTORY_SEPARATOR . $filename;
            }

            $temp = FILES_DIR . DIRECTORY_SEPARATOR . '.' . uniqid() . '.tmp';

            if (isset($_FILES['file'])) {
                if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Upload error: ' . $_FILES['file']['error'], 400);
                }
                move_uploaded_file($_FILES['file']['tmp_name'], $temp);
            } else {
                $input = file_get_contents('php://input');
                file_put_contents($temp, $input);
            }

            if ($append && file_exists($target)) {
                $fp = fopen($target, 'ab');
                fwrite($fp, file_get_contents($temp));
                fclose($fp);
                unlink($temp);
            } else {
                rename($temp, $target);
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'file' => $filename]);
            exit;

        case 'delete':
            require_auth();
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            if (!csrf_check()) {
                throw new Exception('CSRF token mismatch', 403);
            }
            $file = safe_filename($_POST['file'] ?? '');
            $path = FILES_DIR . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                throw new Exception('File not found', 404);
            }

            unlink($path);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;

        case 'task':
            require_auth();
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            if (!csrf_check()) {
                throw new Exception('CSRF token mismatch', 403);
            }

            $url = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL);
            if (!$url) {
                throw new Exception('Invalid URL', 400);
            }

            // 确定文件名
            $userFilename = trim($_POST['filename'] ?? '');
            if ($userFilename) {
                $filename = safe_filename($userFilename);
            } else {
                $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
                $filename = safe_filename(basename($urlPath) ?: 'download');
            }

            // 如果没有扩展名，尝试从URL推断
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $ext = guess_extension_from_url($url);
                if ($ext) {
                    $filename .= '.' . $ext;
                }
            }

            if (!check_extension($filename)) {
                throw new Exception('File type not allowed', 400);
            }

            // 确保文件名唯一
            $filename = unique_filename($filename);

            $id = uniqid();
            $task = [
                'url' => $url,
                'filename' => $filename,
                'status' => 'pending',
                'created' => time(),
            ];
            file_put_contents(TASKS_DIR . DIRECTORY_SEPARATOR . $id . '.json', json_encode($task));

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id]);
            exit;

        case 'batch-delete':
            require_auth();
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            if (!csrf_check()) {
                throw new Exception('CSRF token mismatch', 403);
            }
            $files = json_decode($_POST['files'] ?? '[]', true);
            if (!is_array($files)) {
                throw new Exception('Invalid files list', 400);
            }

            $results = ['success' => [], 'failed' => []];
            foreach ($files as $file) {
                $file = safe_filename($file);
                $path = FILES_DIR . DIRECTORY_SEPARATOR . $file;
                if (is_file($path) && unlink($path)) {
                    $results['success'][] = $file;
                } else {
                    $results['failed'][] = $file;
                }
            }

            header('Content-Type: application/json');
            echo json_encode($results);
            exit;

        case 'rename':
            require_auth();
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            if (!csrf_check()) {
                throw new Exception('CSRF token mismatch', 403);
            }

            $oldname = safe_filename($_POST['oldname'] ?? '');
            $newname = safe_filename($_POST['newname'] ?? '');

            if (!$oldname || !$newname) {
                throw new Exception('Invalid filename', 400);
            }
            if (!check_extension($newname)) {
                throw new Exception('File type not allowed', 400);
            }

            $oldpath = FILES_DIR . DIRECTORY_SEPARATOR . $oldname;
            $newpath = FILES_DIR . DIRECTORY_SEPARATOR . $newname;

            if (!is_file($oldpath)) {
                throw new Exception('File not found', 404);
            }
            if (file_exists($newpath)) {
                throw new Exception('File already exists', 400);
            }

            rename($oldpath, $newpath);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 渲染页面
require_auth();
$csrf = csrf_token();
$self = self_url();
$filesUrl = files_url();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #333; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { font-size: 16px; color: #666; margin-bottom: 15px; }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .form-row input, .form-row textarea { flex: 1; min-width: 200px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-row textarea { height: 60px; resize: vertical; }
        .btn { padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #0056b3; }
        .btn.danger { background: #dc3545; }
        .btn.danger:hover { background: #c82333; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { color: #666; font-weight: 500; }
        .file-name { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-name a { color: #007bff; text-decoration: none; }
        .file-name a:hover { text-decoration: underline; }
        .actions a, .actions button { margin-right: 8px; color: #007bff; background: none; border: none; cursor: pointer; font-size: 13px; }
        .actions .delete { color: #dc3545; }
        .actions .batch-delete { color: #dc3545; font-weight: bold; }
        .progress { height: 24px; background: #e9ecef; border-radius: 12px; overflow: hidden; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.1s linear; box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3); }
        .progress-text { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; color: #333; font-weight: bold; text-shadow: 1px 1px 2px rgba(255,255,255,0.8); font-size: 12px; }
        .status { font-size: 12px; color: #666; margin-top: 5px; font-family: monospace; }
        .upload-info { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px; color: #666; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal.show { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: #fff; padding: 20px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow: auto; }
        .modal-close { float: right; font-size: 24px; cursor: pointer; color: #666; }
        .modal-close:hover { color: #333; }
        .empty { text-align: center; color: #999; padding: 40px; }
        .task-list { margin-top: 10px; }
        .task-item { padding: 8px; background: #f8f9fa; border-radius: 4px; margin-bottom: 5px; font-size: 13px; }
        .task-progress { position: relative; height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden; margin-top: 5px; }
        .task-progress-bar { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.3s ease; }
        .task-progress-text { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #333; font-weight: 500; text-shadow: 0 0 2px rgba(255,255,255,0.8); }
        .checkbox-group { display: flex; align-items: center; gap: 8px; }
        .selected-count { margin-left: 20px; color: #666; font-size: 13px; }
        .batch-actions { margin-left: auto; }
        .file-checkbox { width: 16px; height: 16px; cursor: pointer; }
        .table-header-actions { display: flex; align-items: center; gap: 15px; margin-bottom: 15px; }
        @media (max-width: 600px) {
            .form-row { flex-direction: column; }
            .form-row input, .form-row textarea { min-width: auto; }
            table { font-size: 13px; }
            th, td { padding: 8px; }
            .file-name { max-width: 150px; }
            .table-header-actions { flex-direction: column; align-items: flex-start; }
            .batch-actions { margin-left: 0; margin-top: 10px; align-self: flex-end; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📁 文件管理</h1>

        <div class="card">
            <h2>上传文件</h2>
            <div class="form-row">
                <input type="file" id="fileInput" multiple>
                <button class="btn" onclick="uploadFiles()">上传</button>
            </div>
            <div id="uploadProgress" style="margin-top:10px;display:none;">
                <div class="upload-info">
                    <span id="uploadInfo">准备上传...</span>
                    <span id="uploadSpeed">0 MB/s</span>
                </div>
                <div class="progress" style="position: relative;">
                    <div class="progress-bar" id="progressBar"></div>
                    <div class="progress-text" id="progressText">0%</div>
                </div>
                <div class="status" id="progressStatus">等待文件选择...</div>
            </div>
        </div>

        <div class="card">
            <h2>后台下载</h2>
            <div class="form-row">
                <input type="text" id="downloadFilename" placeholder="保存文件名（可选）">
                <textarea id="downloadUrl" placeholder="下载链接"></textarea>
                <button class="btn" onclick="addTask()">添加</button>
            </div>
            <div id="taskList" class="task-list"></div>
        </div>

        <div class="card">
            <h2>文件列表</h2>
            <div class="table-header-actions">
                <label class="checkbox-group">
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                    全选
                </label>
                <span class="selected-count">
                    已选择 <span id="selectedCount">0</span> 个文件
                </span>
                <button class="btn danger batch-actions" id="batchDeleteBtn" onclick="batchDelete()" style="display: none;">
                    批量删除
                </button>
            </div>
            <div id="fileList"></div>
        </div>
    </div>

    <div class="modal" id="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
        const CSRF = '<?= $csrf ?>';
        const SELF = '<?= $self ?>';
        const FILES_URL = '<?= $filesUrl ?>';

        async function api(action, data = {}) {
            const formData = new FormData();
            for (const [k, v] of Object.entries(data)) {
                formData.append(k, v);
            }
            formData.append('_csrf', CSRF);
            const res = await fetch(`${SELF}?action=${action}`, { method: 'POST', body: formData });
            return res.json();
        }

        async function loadFiles() {
            const res = await fetch(`${SELF}?action=list`);
            const data = await res.json();
            renderFiles(data.files);
            renderTasks(data.tasks);
        }

        function renderFiles(files) {
            const el = document.getElementById('fileList');
            if (!files.length) {
                el.innerHTML = '<div class="empty">暂无文件</div>';
                return;
            }

            el.innerHTML = `<table>
                <thead><tr><th>选择</th><th>文件名</th><th>大小</th><th>时间</th><th>操作</th></tr></thead>
                <tbody>${files.map(f => {
                    const fileUrl = `${FILES_URL}/${encodeURIComponent(f.name)}`;
                    const isVideo = f.type.startsWith('video/');
                    const isImg = f.type.startsWith('image/');
                    const actions = [];
                    actions.push(`<a href="${fileUrl}" download>下载</a>`);
                    if (isVideo) actions.push(`<a href="#" onclick="playVideo('${encodeURIComponent(f.name)}');return false;">播放</a>`);
                    if (isImg) actions.push(`<a href="#" onclick="showImage('${encodeURIComponent(f.name)}');return false;">预览</a>`);
                    actions.push(`<button class="delete" onclick="deleteFile('${encodeURIComponent(f.name)}')">删除</button>`);

                    return `<tr>
                        <td><input type="checkbox" class="file-checkbox" value="${encodeURIComponent(f.name)}"></td>
                        <td class="file-name"><a href="${fileUrl}" target="_blank">${escapeHtml(f.name)}</a></td>
                        <td>${formatSize(f.size)}</td>
                        <td>${new Date(f.time * 1000).toLocaleString()}</td>
                        <td class="actions">${actions.join('')}</td>
                    </tr>`;
                }).join('')}</tbody></table>`;

            // 添加复选框事件监听
            setupFileSelection();
        }

        function renderTasks(tasks) {
            const el = document.getElementById('taskList');
            if (!tasks.length) {
                el.innerHTML = '';
                return;
            }
            el.innerHTML = tasks.map(t => {
                let statusHtml = '';
                if (t.status === 'pending') {
                    statusHtml = '⏳ 等待中';
                } else if (t.status === 'downloading') {
                    if (t.total && t.total > 0) {
                        const percent = Math.min(100, Math.round((t.downloaded / t.total) * 100));
                        statusHtml = `
                            <div class="task-progress">
                                <div class="task-progress-bar" style="width: ${percent}%"></div>
                                <span class="task-progress-text">${percent}% (${formatSize(t.downloaded)} / ${formatSize(t.total)})</span>
                            </div>`;
                    } else {
                        statusHtml = '⬇️ 下载中...';
                    }
                } else if (t.status === 'completed') {
                    statusHtml = '✅ 下载完成';
                }
                return `<div class="task-item">${statusHtml} - ${escapeHtml(t.filename)}</div>`;
            }).join('');
        }

        async function uploadFiles() {
            const input = document.getElementById('fileInput');
            if (!input.files.length) return alert('请选择文件');

            const progress = document.getElementById('uploadProgress');
            const bar = document.getElementById('progressBar');
            const status = document.getElementById('progressStatus');
            const progressText = document.getElementById('progressText');
            const uploadInfo = document.getElementById('uploadInfo');
            const uploadSpeed = document.getElementById('uploadSpeed');
            
            progress.style.display = 'block';

            // 计算总大小
            let totalSize = 0;
            for (const file of input.files) {
                totalSize += file.size;
            }

            let uploadedSize = 0;
            let currentFileIndex = 0;
            let startTime = Date.now();
            let lastTime = startTime;
            let lastUploaded = 0;

            uploadInfo.textContent = `准备上传 ${input.files.length} 个文件，总大小: ${formatSize(totalSize)}`;
            status.textContent = '等待开始...';
            progressText.textContent = '0%';
            bar.style.width = '0%';

            for (const file of input.files) {
                currentFileIndex++;
                status.textContent = `开始上传: ${file.name} (${formatSize(file.size)})`;
                
                await uploadFile(file, (chunkUploaded) => {
                    uploadedSize += chunkUploaded;
                    const now = Date.now();
                    const timeDiff = (now - lastTime) / 1000;
                    const sizeDiff = uploadedSize - lastUploaded;
                    
                    // 更新速度
                    if (timeDiff > 0.5) {
                        const speed = sizeDiff / timeDiff;
                        uploadSpeed.textContent = `速度: ${formatSize(speed)}/s`;
                        lastTime = now;
                        lastUploaded = uploadedSize;
                    }

                    const percentage = Math.min(100, (uploadedSize / totalSize) * 100);
                    bar.style.width = percentage + '%';
                    progressText.textContent = `${percentage.toFixed(1)}%`;
                    
                    // 估算剩余时间
                    const elapsed = (now - startTime) / 1000;
                    const totalEstimated = totalSize > 0 ? (elapsed / uploadedSize) * totalSize : 0;
                    const remaining = Math.max(0, totalEstimated - elapsed);
                    
                    status.textContent = `上传中: ${file.name} (${formatSize(file.size)}) - ${currentFileIndex}/${input.files.length} - ${percentage.toFixed(1)}% - 剩余 ${formatTime(remaining)}`;
                });
            }

            status.textContent = '上传完成！正在刷新文件列表...';
            uploadSpeed.textContent = '完成';
            input.value = '';
            setTimeout(() => { 
                progress.style.display = 'none'; 
                loadFiles(); 
            }, 1000);
        }

        async function uploadFile(file, onProgress) {
            const chunkSize = 100 * 1024; // 100KB
            const chunks = Math.ceil(file.size / chunkSize);
            let uploadedChunks = 0;

            for (let i = 0; i < chunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(start + chunkSize, file.size);
                const blob = file.slice(start, end);

                const formData = new FormData();
                formData.append('file', blob);
                formData.append('filename', file.name);
                formData.append('_csrf', CSRF);

                const url = `${SELF}?action=upload&append=${i > 0 ? 1 : 0}`;
                await fetch(url, { method: 'POST', body: formData });
                
                uploadedChunks++;
                const chunkSizeActual = end - start;
                if (onProgress) {
                    onProgress(chunkSizeActual);
                }
            }
        }

        function formatTime(seconds) {
            if (seconds < 60) {
                return `${Math.ceil(seconds)}秒`;
            } else if (seconds < 3600) {
                const minutes = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${minutes}分${secs}秒`;
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return `${hours}小时${minutes}分`;
            }
        }

        async function addTask() {
            const url = document.getElementById('downloadUrl').value.trim();
            if (!url) return alert('请输入下载链接');

            const filename = document.getElementById('downloadFilename').value.trim();
            const res = await api('task', { url, filename });

            if (res.error) return alert(res.error);
            document.getElementById('downloadUrl').value = '';
            document.getElementById('downloadFilename').value = '';
            loadFiles();
        }

        function setupFileSelection() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            const selectAll = document.getElementById('selectAll');
            const selectedCount = document.getElementById('selectedCount');
            const batchDeleteBtn = document.getElementById('batchDeleteBtn');

            function updateSelectedCount() {
                const selected = document.querySelectorAll('.file-checkbox:checked');
                selectedCount.textContent = selected.length;
                batchDeleteBtn.style.display = selected.length > 0 ? 'inline-block' : 'none';
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });

            selectAll.addEventListener('change', function() {
                const checked = this.checked;
                checkboxes.forEach(cb => cb.checked = checked);
                updateSelectedCount();
            });

            updateSelectedCount();
        }

        function getSelectedFiles() {
            const checkboxes = document.querySelectorAll('.file-checkbox:checked');
            return Array.from(checkboxes).map(cb => decodeURIComponent(cb.value));
        }

        async function batchDelete() {
            const files = getSelectedFiles();
            if (files.length === 0) {
                alert('请先选择要删除的文件');
                return;
            }

            const confirmMsg = `确定要删除选中的 ${files.length} 个文件吗？\n\n${files.slice(0, 5).join('\n')}${files.length > 5 ? `\n...还有 ${files.length - 5} 个文件` : ''}`;
            if (!confirm(confirmMsg)) return;

            try {
                const formData = new FormData();
                formData.append('files', JSON.stringify(files));
                formData.append('_csrf', CSRF);

                const res = await fetch(`${SELF}?action=batch-delete`, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.error) {
                    alert('删除失败: ' + data.error);
                    return;
                }

                const successCount = data.success ? data.success.length : 0;
                const failedCount = data.failed ? data.failed.length : 0;

                let msg = `批量删除完成！\n`;
                if (successCount > 0) {
                    msg += `成功删除 ${successCount} 个文件\n`;
                }
                if (failedCount > 0) {
                    msg += `失败 ${failedCount} 个文件\n`;
                    if (data.failed && data.failed.length > 0) {
                        msg += `失败的文件: ${data.failed.slice(0, 3).join(', ')}${data.failed.length > 3 ? '...' : ''}`;
                    }
                }

                alert(msg);
                loadFiles();

                // 清除选择状态
                document.getElementById('selectAll').checked = false;
                setupFileSelection();

            } catch (error) {
                alert('删除请求失败: ' + error.message);
            }
        }

        async function deleteFile(name) {
            if (!confirm('确定删除？')) return;
            const res = await api('delete', { file: decodeURIComponent(name) });
            if (res.error) return alert(res.error);
            loadFiles();
        }

        function playVideo(name) {
            const src = `${FILES_URL}/${name}`;
            document.getElementById('modalBody').innerHTML = `<video controls autoplay style="max-width:80vw;max-height:80vh;"><source src="${src}"></video>`;
            document.getElementById('modal').classList.add('show');
        }

        function showImage(name) {
            const src = `${FILES_URL}/${name}`;
            document.getElementById('modalBody').innerHTML = `<img src="${src}" style="max-width:80vw;max-height:80vh;">`;
            document.getElementById('modal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('modal').classList.remove('show');
            document.getElementById('modalBody').innerHTML = '';
        }

        document.getElementById('modal').addEventListener('click', e => {
            if (e.target.id === 'modal') closeModal();
        });

        function escapeHtml(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function formatSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
            return bytes.toFixed(2) + ' ' + units[i];
        }

        loadFiles();
        setInterval(loadFiles, 5000);
    </script>
</body>
</html>