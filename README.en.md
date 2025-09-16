# RGSX Sources Manager

[Version française](./README.md)

RGSX Sources Manager is an all‑in‑one tool to:
- Scrape game lists from pages/URLs (archive.org, 1fichier, myrient…)
- Edit `systems_list.json` and manage your platforms
- Edit per‑platform game lists (`games/*.json`)
- Build a ready‑to‑use ZIP package (systems_list.json, images/, games/)

This repository contains a working version that can be used:
- Locally on Windows (with bundled portable PHP)
- On a web server with PHP

---

## 1) Local usage on Windows (recommended)

Requirements: Windows 10/11. No PHP installation needed (included in `data/php_local_server`).

Steps:
1. Download and extract the project archive into a folder (avoid spaces if possible).
2. Open the folder and run `RGSX_Manager.bat`.
3. The script starts a small built‑in PHP server on `127.0.0.1:8088` and opens your browser at:
   - `http://127.0.0.1:8088/data/rgsx_sources_manager.php`
4. The web UI appears. You can then use the 4 tabs: Scrape, Platforms, Games, ZIP Package.

Notes:
- If your firewall prompts for PHP access, allow local access.
- The built‑in server stops when you close the window that opened ("PHP Server").

---

## 2) Usage on a hosted server with PHP (accessible from any PC or mobile)

Requirements: Web Server (Apache/Nginx) + PHP 8.x (or 7.4+).

Minimal deployment:
1. Copy `data/rgsx_sources_manager.php` and the `data/assets` folder (containing `lang/`, `batocera_systems.json`, etc.) to the server.
2. Configure your DocumentRoot (or place the files under a publicly accessible path).
3. Open in a browser, for example:
   - `https://your-domain.tld/data/rgsx_sources_manager.php`

Remarks:
- The exact path depends on your virtual host structure. The file must be reachable via HTTP.
- For server‑side deployments, use a standard PHP/Apache setup rather than portable PHP.

---

## UI tour and typical workflow

The application has 4 tabs.

### 1) Scrape
- Import a data ZIP (file or URL):
  - "Load" uploads a ZIP that contains `systems_list.json`, `games/*.json`, `images/*`.
  - "Use official RGSX base" fills the URL with the official RGSX source to get a complete base you can edit.
- "URLs or HTML" area:
  - Paste one or more URLs to scrape (archive.org, 1fichier, myrient).
  - Provide a password if a 1fichier folder is protected.
  - Click "Scrape".
- Results:
  - Each detected source shows its file count.
  - To attach the result to a platform: choose a platform (list) and click "Attach to platform".
  - You can also "Add all" (all results) to a chosen platform.

### 2) Platforms (systems_list.json)
- Add a platform:
  - Pick a name from the list, fill in `platform_name` and `folder`.
  - Optional: image (`platform_image_file`). If none, the tool suggests `<platform_name>.png` by default.
- Paginated list:
  - "Per page" selector: 10 / 20 / 25 / 50 / 100 (20 by default).
  - "Prev/Next" navigation.
- Edit/Delete:
  - "Edit" opens an inline edit row to change name/folder and image.
  - "View" shows a preview if the image is available in `images/`.

### 3) Games (games/*.json)
- Import platform files:
  - Upload one or more `games/Platform.json` files (or a ZIP, recommended for >20).
- Add a row (manually):
  - Choose the platform file, then fill Name, URL, Size.
- Per‑platform display (accordion):
  - Shows the number of rows and the games table.
  - Each row has Edit/Delete. Long names and URLs are truncated (ellipsis, URL middle truncation) for readability.
- Systems pagination (tab 2) is independent from the games content.

### 4) ZIP Package
- Actions:
  - "Create ZIP": generates `games.zip` containing `systems_list.json` (root), `images/`, and `games/`.
  - "Download systems_list.json": to fetch only this file.

---

## Troubleshooting

- Built‑in server doesn’t start:
  - Ensure `data/php_local_server/php.exe` exists.
  - Run `RGSX_Manager.bat` from a folder with sufficient permissions.
- Batocera lists don’t populate:
  - Check `data/assets/batocera_systems.json` and your browser’s network console.
- Generated ZIP is empty or incomplete:
  - Make sure you added systems and games in the session before clicking "Create ZIP".

---

## License

This project bundles third‑party components (portable PHP) under their respective licenses.
