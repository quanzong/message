# files.php - 单文件文件管理服务

一个极简的PHP文件管理系统，支持文件上传、下载、在线播放视频、预览图片和后台下载。

## 特点

- **单文件** - 所有功能集成在两个PHP文件中，可随意重命名
- **零配置** - 放入任意Web目录即可使用
- **响应式** - 支持移动端访问
- **大文件** - 支持分片上传
- **批量操作** - 支持批量选择删除文件

## 安装

将 `files.php` 放入Web服务器的公共目录，访问即可。

```bash
# 本地测试
php -S localhost:8000 -t public
# 访问 http://localhost:8000/files.php
```

## 目录结构

```
files.php              # 主程序
files/                 # 文件存储（Web服务器直接访问）
.files/                # 元数据目录（任务等）
```

## 后台下载服务

```bash
# 前台运行
php public/files.php.download.php

# systemd服务（可选）
sudo cp files.php.download.service /etc/systemd/system/
sudo systemctl enable --now files.php.download
```

## 配置

支持两种配置方式：

### 1. 环境变量（推荐）

```bash
# 设置环境变量
export AUTH_PASS=your_password      # 登录密码
export ALLOWED_EXTENSIONS=jpg,png   # 允许的文件类型
```

### 2. 直接修改代码

编辑 `files.php` 顶部的常量：

| 常量 | 说明 |
|------|------|
| `ALLOWED_EXTENSIONS` | 允许的文件类型，null为不限制 |
| `AUTH_PASS` | 登录密码，留空则不启用认证 |

## 重命名

可随意重命名 `files.php`，文件目录会自动适配：

| 脚本名 | 文件目录 | 元数据目录 |
|--------|----------|------------|
| `files.php` | `files/` | `.files/` |
| `index.php` | `index/` | `.index/` |
| `myapp.php` | `myapp/` | `.myapp/` |

## 安全建议

- 生产环境建议设置 `AUTH_PASS` 启用密码认证
- 使用HTTPS
- 配置 `ALLOWED_EXTENSIONS` 限制上传类型
- Web服务器配置禁止直接访问 `.` 开头的目录