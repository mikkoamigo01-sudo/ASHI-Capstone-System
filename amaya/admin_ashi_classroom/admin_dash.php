<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
<title>ASHI AMAYA — Admin Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── LIGHT THEME (default) ── */
:root {
  --accent: #111111;
  --sidebar-bg: #0f0f0f;
  --sidebar-muted: #888888;
  --content-bg: #f7f7f7;
  --card-bg: #ffffff;
  --border: #e5e5e5;
  --text-primary: #111111;
  --text-secondary: #665665;
  --text-muted: #999999;
  --shadow: 0 1px 4px rgba(0,0,0,0.08);
  --radius: 8px;
  --sidebar-w: 240px;
  --topbar-bg: #ffffff;
  --table-head-bg: #f9f9f9;
  --table-row-even: #fdfdfd;
  --table-row-hover: #fafafa;
  --input-bg: #ffffff;
}

/* ── DARK THEME ── */
[data-theme="dark"] {
  --accent: #ffffff;
  --sidebar-bg: #0a0a0a;
  --sidebar-muted: #665665;
  --content-bg: #141414;
  --card-bg: #1e1e1e;
  --border: #2e2e2e;
  --text-primary: #f0f0f0;
  --text-secondary: #aaaaaa;
  --text-muted: #665665;
  --shadow: 0 1px 4px rgba(0,0,0,0.4);
  --topbar-bg: #1a1a1a;
  --table-head-bg: #242424;
  --table-row-even: #1c1c1c;
  --table-row-hover: #252525;
  --input-bg: #252525;
}

body { font-family: 'Poppins', sans-serif; background: var(--content-bg); color: var(--text-primary); display: flex; min-height: 100vh; font-size: 14px; transition: background .3s, color .3s; }

/* ── SIDEBAR ── */
.sidebar { width: var(--sidebar-w); background: var(--sidebar-bg); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; }
.sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid #222; }
.sidebar-logo .logo-title { font-size: 17px; font-weight: 700; color: #fff; letter-spacing: .5px; }
.sidebar-logo .logo-sub { font-size: 11px; color: var(--sidebar-muted); margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
.nav-label { font-size: 10px; font-weight: 600; color: #555; letter-spacing: 1.2px; text-transform: uppercase; padding: 12px 24px 6px; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 24px; color: #aaa; cursor: pointer; font-size: 13.5px; transition: background .15s, color .15s; border-left: 3px solid transparent; user-select: none; }
.nav-item:hover { background: #1a1a1a; color: #fff; }
.nav-item.active { background: #1e1e1e; color: #fff; border-left-color: #fff; font-weight: 500; }
.nav-item .icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.sidebar-footer { padding: 16px 0; border-top: 1px solid #222; }
.nav-item.logout:hover { color: #ff6b6b; }

/* ── MAIN ── */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar { background: var(--topbar-bg); border-bottom: 1px solid var(--border); padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; transition: background .3s; }
.topbar-title { font-size: 16px; font-weight: 600; }
.admin-badge { display: flex; align-items: center; gap: 10px; }
.admin-avatar { width: 34px; height: 34px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--content-bg); font-size: 13px; font-weight: 600; }
.admin-name { font-size: 13px; font-weight: 500; }
.admin-role { font-size: 11px; color: var(--text-muted); }
.content { padding: 32px; flex: 1; }

/* ── PAGES ── */
.page { display: none; }
.page.active { display: block; }

/* ── STAT CARDS ── */
.stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 28px; }
.stat-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; box-shadow: var(--shadow); transition: background .3s, border-color .3s; }
.stat-card .s-icon { font-size: 22px; margin-bottom: 10px; opacity: .7; }
.stat-card .s-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 8px; }
.stat-card .s-value { font-size: 28px; font-weight: 700; line-height: 1; }
.stat-card .s-sub { font-size: 11px; color: var(--text-muted); margin-top: 6px; }

/* ── CHARTS ── */
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.chart-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow); transition: background .3s; }
.chart-card.full { grid-column: 1 / -1; }
.chart-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.chart-sub { font-size: 11px; color: var(--text-muted); margin-bottom: 18px; }
.chart-wrap { position: relative; height: 220px; }
.chart-wrap-sm { position: relative; height: 200px; }

/* ── SECTION HEADER ── */
.section-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.section-header h2 { font-size: 18px; font-weight: 700; }
.section-header p { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* ── TOOLBAR ── */
.toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; align-items: center; }
.search-wrap { position: relative; }
.search-wrap input { padding: 8px 12px 8px 34px; border: 1px solid var(--border); border-radius: var(--radius); font-family: inherit; font-size: 13px; width: 240px; background: var(--input-bg); color: var(--text-primary); outline: none; transition: background .3s, border-color .15s; }
.search-wrap input:focus { border-color: #999; }
.search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 14px; pointer-events: none; }
select.filter { padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); font-family: inherit; font-size: 13px; background: var(--input-bg); color: var(--text-primary); cursor: pointer; outline: none; transition: background .3s; }
select.filter:focus { border-color: #999; }
select.filter:disabled { opacity: .45; cursor: not-allowed; }

/* ── TABLE ── */
.table-wrap { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; transition: background .3s; }
table { width: 100%; border-collapse: collapse; }
thead { background: var(--table-head-bg); border-bottom: 2px solid var(--border); }
th { text-align: left; padding: 10px 14px; font-size: 10px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; }
td { padding: 9px 14px; font-size: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--table-row-hover); }
tbody tr:nth-child(even) { background: var(--table-row-even); }
tbody tr:nth-child(even):hover { background: var(--table-row-hover); }

/* ── CELLS ── */
.avatar-sm { width: 32px; height: 32px; background: var(--border); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: var(--text-secondary); flex-shrink: 0; }
.cell-name { display: flex; align-items: center; gap: 10px; }
.name-text { font-weight: 500; }

/* ── BUTTONS ── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius); font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer; border: none; transition: background .15s, color .15s; white-space: nowrap; }
.btn-primary { background: var(--text-primary); color: var(--content-bg); }
.btn-primary:hover { opacity: .85; }
.btn-outline { background: var(--card-bg); color: var(--text-primary); border: 1px solid var(--border); }
.btn-outline:hover { background: var(--table-row-hover); border-color: #aaa; }
.btn-danger { background: var(--card-bg); color: #c0392b; border: 1px solid #f5c6c6; }
.btn-danger:hover { background: #fff5f5; }
.btn-success { background: var(--card-bg); color: #2d6a4f; border: 1px solid #b7e4c7; }
.btn-success:hover { background: #f0faf4; }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.action-btns { display: flex; gap: 6px; flex-wrap: wrap; }

/* ── BADGE ── */
.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; }
.badge-active   { background: #e8f5e9; color: #2d6a4f; }
.badge-archived { background: #f5f5f5; color: #888; }
.badge-gray     { background: #f0f0f0; color: #555; }
.badge-open     { background: #e8f0fe; color: #1a56db; }
.badge-closed   { background: #f5f5f5; color: #888; }

/* ── PAGINATION ── */
.pager { display: flex; align-items: center; justify-content: flex-end; gap: 6px; padding: 12px 16px; background: var(--card-bg); border-top: 1px solid var(--border); }

/* ── MODAL ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 1000; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: var(--card-bg); border-radius: 10px; width: 540px; max-width: 95vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.3); animation: fadeUp .2s ease; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 16px; border-bottom: 1px solid var(--border); }
.modal-title { font-size: 15px; font-weight: 700; }
.modal-close { width: 30px; height: 30px; background: var(--text-primary); color: var(--card-bg); border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.modal-close:hover { opacity: .8; }
.modal-body { padding: 24px; }
.modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.modal-field label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .7px; color: var(--text-muted); display: block; margin-bottom: 4px; }
.modal-field .field-val { font-size: 13px; font-weight: 500; }
.modal-field.full { grid-column: 1 / -1; }
.modal-avatar-row { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.modal-avatar-lg { width: 60px; height: 60px; background: var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: var(--text-secondary); flex-shrink: 0; }

/* ── SETTINGS ── */
.settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.settings-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); transition: background .3s; }
.settings-card.full { grid-column: 1 / -1; }
.settings-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }

/* Theme Toggle */
.theme-toggle-wrap { display: flex; gap: 12px; margin-top: 8px; }
.theme-option { flex: 1; border: 2px solid var(--border); border-radius: var(--radius); padding: 16px 12px; cursor: pointer; text-align: center; transition: border-color .2s, background .2s; background: var(--content-bg); }
.theme-option:hover { border-color: #888; }
.theme-option.selected { border-color: var(--text-primary); background: var(--card-bg); }
.theme-option .t-icon { font-size: 28px; margin-bottom: 8px; }
.theme-option .t-label { font-size: 12px; font-weight: 600; color: var(--text-primary); }
.theme-option .t-desc { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.theme-preview-light { width: 100%; height: 52px; border-radius: 6px; background: #f7f7f7; border: 1px solid #e5e5e5; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; padding: 8px; }
.theme-preview-dark  { width: 100%; height: 52px; border-radius: 6px; background: #141414; border: 1px solid #2e2e2e; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; padding: 8px; }
.tp-bar { width: 28px; height: 100%; border-radius: 3px; }
.tp-content { flex: 1; display: flex; flex-direction: column; gap: 4px; }
.tp-line { height: 6px; border-radius: 3px; }

.settings-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; gap: 12px; }
.settings-row:last-child { border-bottom: none; }
.settings-row .s-label { color: var(--text-secondary); font-size: 12px; flex-shrink: 0; }
.settings-row .s-value { font-weight: 500; font-size: 12px; text-align: right; color: var(--text-primary); }

/* Capstone badge */
.capstone-badge { display: inline-flex; align-items: center; gap: 6px; background: var(--table-head-bg); border: 1px solid var(--border); border-radius: 20px; padding: 4px 12px; font-size: 11px; color: var(--text-secondary); margin-top: 12px; }

/* ── FAQ ── */
.faq-item { border-bottom: 1px solid var(--border); }
.faq-item:last-child { border-bottom: none; }
.faq-q { width: 100%; text-align: left; background: none; border: none; padding: 14px 0; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; justify-content: space-between; align-items: center; color: var(--text-primary); gap: 12px; }
.faq-q .chevron { font-size: 12px; color: #aaa; transition: transform .2s; }
.faq-item.open .faq-q .chevron { transform: rotate(180deg); }
.faq-a { font-size: 12.5px; color: var(--text-secondary); line-height: 1.7; max-height: 0; overflow: hidden; transition: max-height .3s ease, padding .3s; }
.faq-item.open .faq-a { max-height: 300px; padding-bottom: 14px; }

/* ── TABS ── */
.tab-bar { display: flex; border-bottom: 2px solid var(--border); margin-bottom: 22px; }
.tab-btn { padding: 10px 22px; font-family: inherit; font-size: 13px; font-weight: 500; background: none; border: none; cursor: pointer; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: color .15s, border-color .15s; }
.tab-btn.active { color: var(--text-primary); border-bottom-color: var(--text-primary); }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* ── PROGRESS ── */
.progress-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
.mini-stat { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow); }
.mini-stat .ms-label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 4px; }
.mini-stat .ms-val { font-size: 22px; font-weight: 700; }
.back-btn { display: inline-flex; align-items: center; gap: 6px; font-family: inherit; font-size: 13px; font-weight: 500; color: var(--text-secondary); background: none; border: none; cursor: pointer; padding: 0; margin-bottom: 20px; }
.back-btn:hover { color: var(--text-primary); }

/* ── MATERIALS ── */
.material-group { margin-bottom: 28px; }
.material-group-title { font-size: 13px; font-weight: 600; padding: 10px 16px; background: var(--table-head-bg); border: 1px solid var(--border); border-radius: var(--radius) var(--radius) 0 0; border-bottom: none; color: var(--text-secondary); }

/* ── ICON BUTTONS ── */
.icon-btn {
  width: 30px;
  height: 30px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  font-size: 12px;
  flex-shrink: 0;
}
.icon-btn i { font-size: 12px; pointer-events: none; }
.state-row td { text-align: center; padding: 32px; color: var(--text-muted); font-size: 13px; }
.empty-state { text-align: center; padding: 48px 24px; color: var(--text-muted); }
.empty-state .e-icon { font-size: 36px; margin-bottom: 12px; opacity: .4; }
.empty-state p { font-size: 13px; }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #ddd; border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: #bbb; }

/* ══════════════════════════════════════════
   SYSTEM PAGE STYLES
══════════════════════════════════════════ */

/* Outer section wrapper — centers card, buttons bottom-right */
.system-section-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

/* The display card — centered, fixed width */
.system-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 4px 20px rgba(0,0,0,.09);
  overflow: hidden;
  width: 420px;
  transition: background .3s;
}

/* ── Banner with animated SVG wave ── */
.sys-card-header {
  position: relative;
  background: #111;
  height: 96px;
  overflow: hidden;
  display: flex;
  align-items: flex-start;
  padding: 18px 28px 0;
  gap: 8px;
}
[data-theme="dark"] .sys-card-header { background: #000; }

.sys-card-header-icon {
  font-size: 16px;
  position: relative;
  z-index: 2;
  line-height: 1;
}
.sys-card-header-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.8px;
  text-transform: uppercase;
  color: rgba(255,255,255,.6);
  position: relative;
  z-index: 2;
  line-height: 1.6;
}

/* Animated SVG wave inside the banner */
.sys-wave {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 200%;
  height: 52px;
  animation: waveScroll 6s linear infinite;
}
.sys-wave2 {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 200%;
  height: 44px;
  opacity: .45;
  animation: waveScroll 9s linear infinite reverse;
}

@keyframes waveScroll {
  0%   { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

/* Body of the card — centered content */
.sys-card-body {
  padding: 28px 32px 26px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0;
  text-align: center;
}

.sys-field-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 1.3px;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 4px;
}

.sys-year-display {
  font-size: 40px;
  font-weight: 700;
  line-height: 1;
  letter-spacing: -2px;
  color: var(--text-primary);
  transition: color .3s;
  margin-bottom: 20px;
}

/* Divider */
.sys-divider {
  width: 48px;
  height: 2px;
  background: var(--border);
  border-radius: 2px;
  margin: 0 auto 20px;
}

/* Quarter */
.sys-quarter-badge {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: var(--text-primary);
  color: var(--card-bg);
  font-size: 22px;
  font-weight: 700;
  padding: 7px 22px 7px 16px;
  border-radius: 10px;
  letter-spacing: .5px;
  transition: background .3s, color .3s;
  margin-bottom: 20px;
}
.sys-quarter-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #22c55e;
  flex-shrink: 0;
  box-shadow: 0 0 0 2px rgba(34,197,94,.3);
}

/* Updated timestamp */
.sys-updated {
  font-size: 10px;
  color: var(--text-muted);
  padding-top: 16px;
  border-top: 1px solid var(--border);
  width: 100%;
  text-align: center;
}




/* ── Action buttons — bottom-right of the section ── */
.system-card-actions {
  position: absolute;
  bottom: 0;
  right: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-end;
}

.sys-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 10px 0;
  width: 178px;
  border-radius: var(--radius);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: all .2s;
  white-space: nowrap;
  letter-spacing: .2px;
}

.sys-btn-quarter {
  background: #2563eb;
  color: #fff;
  box-shadow: 0 4px 14px rgba(37,99,235,.35);
}
.sys-btn-quarter:hover {
  background: #1d4ed8;
  box-shadow: 0 8px 20px rgba(37,99,235,.45);
  transform: translateY(-1px);
}

.sys-btn-year {
  background: #16a34a;
  color: #fff;
  box-shadow: 0 4px 14px rgba(22,163,74,.32);
}
.sys-btn-year:hover {
  background: #15803d;
  box-shadow: 0 8px 20px rgba(22,163,74,.42);
  transform: translateY(-1px);
}

/* ── SYSTEM MODALS ── */
.sys-modal { width: 420px; }

.sys-modal .modal-header {
  background: var(--table-head-bg);
  border-radius: 10px 10px 0 0;
}

.sys-info-row {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
}

.sys-info-box {
  flex: 1;
  background: var(--table-head-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px 16px;
}

.sys-info-box .sib-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 4px;
}

.sys-info-box .sib-val {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-primary);
}

.sys-form-group {
  margin-bottom: 16px;
}

.sys-form-group label {
  display: block;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .7px;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 6px;
}

.sys-form-group select,
.sys-form-group input[type="text"] {
  width: 100%;
  font-family: inherit;
  font-size: 13px;
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--input-bg);
  color: var(--text-primary);
  outline: none;
  transition: border-color .15s;
}

.sys-form-group select:focus,
.sys-form-group input[type="text"]:focus {
  border-color: #888;
}

.sys-hint {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 5px;
}

.sys-warn {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: var(--radius);
  padding: 10px 14px;
  font-size: 11px;
  color: #b45309;
  margin-bottom: 16px;
  line-height: 1.5;
}

.sys-msg {
  font-size: 12px;
  padding: 9px 12px;
  border-radius: var(--radius);
  margin-bottom: 14px;
  display: none;
}
.sys-msg.ok  { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; display: block; }
.sys-msg.err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; display: block; }

.sys-apply-btn {
  width: 100%;
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
  padding: 10px;
  border: none;
  border-radius: var(--radius);
  cursor: pointer;
  transition: all .2s;
}

.sys-apply-btn.blue  { background: #2563eb; color: #fff; }
.sys-apply-btn.blue:hover  { background: #1d4ed8; }
.sys-apply-btn.green { background: #16a34a; color: #fff; }
.sys-apply-btn.green:hover { background: #15803d; }

@media (max-width: 1100px) {
  .stat-grid { grid-template-columns: repeat(3, 1fr); }
  .charts-grid { grid-template-columns: 1fr; }
  .settings-grid { grid-template-columns: 1fr; }
  .progress-grid { grid-template-columns: 1fr 1fr; }
  .system-section-wrap { align-items: stretch; }
  .system-card { width: 100%; }
  .system-card-actions { position: static; justify-content: flex-end; }
}

.btn-reset-archive {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 18px;
  background-color: #e74c3c;
  color: #fff;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
  transition: background 0.2s;
}
.btn-reset-archive:hover {
  background-color: #c0392b;
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-title">ASHI AMAYA</div>
    <div class="logo-sub">Admin Panel</div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <div class="nav-item" onclick="window.location.href='create_acc/create_acc.php'"><span class="icon">➕</span><span>Create Account</span></div>
    <div class="nav-item" onclick="window.location.href='create_acc/subject_handler.php'"><span class="icon">➕</span><span>Handle Subjects</span></div>
    <div class="nav-item active" data-page="dashboard"><span class="icon">⊞</span><span>Dashboard</span></div>
    <div class="nav-item" data-page="students"><span class="icon">👤</span><span>Students</span></div>
    <div class="nav-item" data-page="teachers"><span class="icon">🎓</span><span>Teachers</span></div>
    <div class="nav-label">Academic</div>
    <div class="nav-item" data-page="materials"><span class="icon">📂</span><span>Materials</span></div>
    <div class="nav-item" data-page="grades"><span class="icon">📊</span><span>Grades &amp; Reports</span></div>
    <div class="nav-item" onclick="window.location.href='create_acc/grade_personalz.php'"><span class="icon">📅</span><span>Grading Configuration</span></div>
    <div class="nav-label">System</div>
    <div class="nav-item" data-page="settings"><span class="icon">⚙</span><span>Settings</span></div>
    <div class="nav-item" data-page="archive"><span class="icon">🗄</span><span>Archive</span></div>
    <div class="nav-item" data-page="system"><span class="icon">🕐</span><span>System</span></div>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php" style="text-decoration:none;">
      <div class="nav-item logout"><span class="icon">↩</span><span>Logout</span></div>
    </a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">
  <div class="topbar">
    <div class="topbar-title" id="topbar-title">Dashboard</div>
    <div class="admin-badge">
      <div class="admin-avatar">A</div>
      <div>
        <div class="admin-name">Administrator</div>
        <div class="admin-role">Super Admin</div>
      </div>
    </div>
  </div>

  <div class="content">

    <!-- ══════════════════════════════════════════ DASHBOARD -->
    <div class="page active" id="page-dashboard">
      <div class="section-header">
        <div><h2>Overview</h2><p>Welcome back. Here's what's happening at ASHI AMAYA.</p></div>
      </div>
      <div id="dash-academic-banner" style="
      display:flex;
      flex-direction:column;
      justify-content:center;
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:14px 20px;
      margin-bottom:20px;
      box-shadow:var(--shadow);
      min-height:64px;
      ">Loading academic period…</div>
      <div class="stat-grid" id="stat-grid">
        <div class="stat-card"><div class="s-label">Loading…</div><div class="s-value">—</div></div>
        <div class="stat-card"><div class="s-label">Loading…</div><div class="s-value">—</div></div>
        <div class="stat-card"><div class="s-label">Loading…</div><div class="s-value">—</div></div>
        <div class="stat-card"><div class="s-label">Loading…</div><div class="s-value">—</div></div>
        <div class="stat-card"><div class="s-label">Loading…</div><div class="s-value">—</div></div>
      </div>
      <div class="charts-grid">
        <div class="chart-card full">
          <div class="chart-title">Student Enrollment per Grade Level</div>
          <div class="chart-sub">Active students across grade levels</div>
          <div class="chart-wrap"><canvas id="barChart"></canvas></div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Assignment Submission Activity</div>
          <div class="chart-sub">Submissions over the past 7 days</div>
          <div class="chart-wrap"><canvas id="lineChart"></canvas></div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Quiz Pass / Fail Ratio</div>
          <div class="chart-sub">Based on all submitted quiz attempts</div>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="chart-wrap-sm" style="flex:1;"><canvas id="pieChart"></canvas></div>
            <div id="pie-legend"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ STUDENTS -->
    <div class="page" id="page-students">
      <div class="section-header">
        <div><h2>Students</h2><p>Manage and monitor all enrolled students.</p></div>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <i class="fa fa-magnifying-glass search-icon"></i>
          <input type="text" id="student-search" placeholder="Search by name or LRN…">
        </div>
        <select class="filter" id="student-grade-filter"><option value="">All Grades</option></select>
        <select class="filter" id="student-section-filter" disabled><option value="">All Sections</option></select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>LRN</th><th>Name</th><th>Email</th><th>Grade</th><th>Section</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="student-tbody"><tr class="state-row"><td colspan="7">Loading students…</td></tr></tbody>
        </table>
        <div class="pager" id="student-pager"></div>
      </div>
    </div>

    <!-- ══ STUDENT PROGRESS -->
    <div class="page" id="page-student-progress">
      <button class="back-btn" onclick="navigateTo('students')">← Back to Students</button>
      <div class="section-header">
        <div><h2 id="sp-name">Student</h2><p>Academic performance overview</p></div>
      </div>
      <div class="progress-grid">
        <div class="mini-stat"><div class="ms-label">Enrolled Subjects</div><div class="ms-val" id="sp-subjects">—</div></div>
        <div class="mini-stat"><div class="ms-label">Avg Assignment</div><div class="ms-val" id="sp-asgn">—</div></div>
        <div class="mini-stat"><div class="ms-label">Avg Quiz %</div><div class="ms-val" id="sp-quiz">—</div></div>
        <div class="mini-stat"><div class="ms-label">Submissions</div><div class="ms-val" id="sp-subs">—</div></div>
        <div class="mini-stat"><div class="ms-label">Late Submissions</div><div class="ms-val" id="sp-late">—</div></div>
        <div class="mini-stat"><div class="ms-label">Standing</div><div class="ms-val" id="sp-standing">—</div></div>
      </div>
      <div class="section-header" style="margin-top:4px;"><div><h2 style="font-size:15px;">Enrolled Subjects</h2></div></div>
      <div class="table-wrap" style="margin-bottom:20px;">
        <table>
          <thead><tr><th>Subject</th><th>Teacher</th><th>Submitted</th><th>Avg Score</th><th>Quiz Avg</th><th>Status</th></tr></thead>
          <tbody id="sp-subjects-tbody"><tr class="state-row"><td colspan="6">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="section-header"><div><h2 style="font-size:15px;">Submission History</h2></div></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Subject</th><th>Assignment</th><th>Submitted At</th><th>Score</th><th>Max</th><th>Status</th></tr></thead>
          <tbody id="sp-history-tbody"><tr class="state-row"><td colspan="6">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ TEACHERS -->
    <div class="page" id="page-teachers">
      <div class="section-header">
        <div><h2>Teachers</h2><p>View and manage all faculty members.</p></div>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <i class="fa fa-magnifying-glass search-icon"></i>
          <input type="text" id="teacher-search" placeholder="Search by name or email…">
        </div>
        <select class="filter" id="teacher-grade-filter"><option value="">All Grades</option></select>
        <select class="filter" id="teacher-section-filter" disabled><option value="">All Sections</option></select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Name</th><th>Email</th><th>Specialization</th><th>Advisory</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="teacher-tbody"><tr class="state-row"><td colspan="7">Loading teachers…</td></tr></tbody>
        </table>
        <div class="pager" id="teacher-pager"></div>
      </div>
    </div>

    <!-- ══ TEACHER PROGRESS -->
    <div class="page" id="page-teacher-progress">
      <button class="back-btn" onclick="navigateTo('teachers')">← Back to Teachers</button>
      <div class="section-header">
        <div><h2 id="tp-name">Teacher</h2><p>Teaching activity overview</p></div>
      </div>
      <div class="progress-grid">
        <div class="mini-stat"><div class="ms-label">Subjects Handled</div><div class="ms-val" id="tp-subjects">—</div></div>
        <div class="mini-stat"><div class="ms-label">Total Students</div><div class="ms-val" id="tp-students">—</div></div>
        <div class="mini-stat"><div class="ms-label">Assignments</div><div class="ms-val" id="tp-asgn">—</div></div>
        <div class="mini-stat"><div class="ms-label">Quizzes</div><div class="ms-val" id="tp-quiz">—</div></div>
        <div class="mini-stat"><div class="ms-label">Graded</div><div class="ms-val" id="tp-graded">—</div></div>
        <div class="mini-stat"><div class="ms-label">Materials</div><div class="ms-val" id="tp-materials">—</div></div>
      </div>
      <div class="section-header" style="margin-top:4px;"><div><h2 style="font-size:15px;">Subjects Handled</h2></div></div>
      <div class="table-wrap" style="margin-bottom:20px;">
        <table>
          <thead><tr><th>Subject</th><th>Section</th><th>Grade</th><th>Students</th><th>Assignments</th><th>Quizzes</th><th>Materials</th></tr></thead>
          <tbody id="tp-subjects-tbody"><tr class="state-row"><td colspan="7">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="section-header"><div><h2 style="font-size:15px;">Recent Grading Activity</h2></div></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Subject</th><th>Assignment</th><th>Score</th><th>Max</th><th>Graded At</th></tr></thead>
          <tbody id="tp-activity-tbody"><tr class="state-row"><td colspan="6">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ MATERIALS -->
    <div class="page" id="page-materials">
      <div class="section-header">
        <div><h2>Materials</h2><p>Read-only view of all academic content posted by teachers.</p></div>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <i class="fa fa-magnifying-glass search-icon"></i>
          <input type="text" id="mat-search" placeholder="Search materials…">
        </div>
        <select class="filter" id="mat-teacher-filter"><option value="">All Teachers</option></select>
        <select class="filter" id="mat-type-filter">
          <option value="">All Types</option>
          <option value="materials">Materials</option>
          <option value="assignments">Assignments</option>
          <option value="quizzes">Quizzes</option>
        </select>
      </div>
      <div id="materials-container"><div class="empty-state"><div class="e-icon">📂</div><p>Loading materials…</p></div></div>
    </div>

    <!-- ══════════════════════════════════════════ GRADES -->
    <div class="page" id="page-grades">
      <div class="section-header">
        <div><h2>Grades &amp; Reports</h2><p>Subject-level performance summaries and class averages.</p></div>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-outline" id="btn-export-csv">⬇ Export CSV</button>
        </div>
      </div>
      <div class="toolbar">
        <select class="filter" id="grades-grade-filter"><option value="">All Grades</option></select>
        <select class="filter" id="grades-subject-filter"><option value="">All Subjects</option></select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Subject</th><th>Teacher</th><th>Grade / Section</th><th>Students</th><th>Avg Asgn %</th><th>Avg Quiz %</th><th>Class Avg</th><th>Passed</th><th>Failed</th></tr></thead>
          <tbody id="grades-tbody"><tr class="state-row"><td colspan="9">Loading grades…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ SETTINGS -->
    <div class="page" id="page-settings">
      <div class="section-header">
        <div><h2>Settings</h2><p>Manage system preferences and appearance.</p></div>
      </div>
      <div class="settings-grid">
        <div class="settings-card">
          <h3>🎨 Appearance</h3>
          <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Choose how the admin panel looks. Your preference is saved automatically.</p>
          <div class="theme-toggle-wrap">
            <div class="theme-option selected" id="theme-light" onclick="setTheme('light')">
              <div class="theme-preview-light">
                <div class="tp-bar" style="background:#0f0f0f;"></div>
                <div class="tp-content"><div class="tp-line" style="background:#e5e5e5;width:80%;"></div><div class="tp-line" style="background:#f0f0f0;width:60%;"></div></div>
              </div>
              <div class="t-icon">☀️</div><div class="t-label">Light</div><div class="t-desc">Clean white interface</div>
            </div>
            <div class="theme-option" id="theme-dark" onclick="setTheme('dark')">
              <div class="theme-preview-dark">
                <div class="tp-bar" style="background:#0a0a0a;"></div>
                <div class="tp-content"><div class="tp-line" style="background:#2e2e2e;width:80%;"></div><div class="tp-line" style="background:#242424;width:60%;"></div></div>
              </div>
              <div class="t-icon">🌙</div><div class="t-label">Dark</div><div class="t-desc">Easy on the eyes</div>
            </div>
          </div>
          <p style="font-size:11px;color:var(--text-muted);margin-top:14px;" id="theme-status">Currently using: <strong>Light Mode</strong></p>
        </div>
        <div class="settings-card">
          <h3>ℹ System Information</h3>
          <div class="settings-row"><span class="s-label">System Name</span><span class="s-value">ASHI AMAYA</span></div>
          <div class="settings-row"><span class="s-label">Version</span><span class="s-value">v1.0.0</span></div>
          <div class="settings-row"><span class="s-label">School</span><span class="s-value">Amaya Senior High School</span></div>
          <div class="settings-row"><span class="s-label">Last Updated</span><span class="s-value"><?= date('F Y') ?></span></div>
          <div class="settings-row" style="flex-direction:column;align-items:flex-start;gap:6px;">
            <span class="s-label">Developed By</span>
            <span class="s-value" style="font-size:12px;line-height:1.6;">CvSU – Tanza Bagtas Cavite<br><span style="color:var(--text-muted);font-weight:400;">Bachelor of Science in Information Technology</span><br><span style="color:var(--text-muted);font-weight:400;">Capstone Project — S.Y. 2025–2026</span></span>
          </div>
          <div class="capstone-badge">🎓 <span>CvSU Capstone Project</span></div>
        </div>
        <div class="settings-card full">
          <h3>❓ Frequently Asked Questions</h3>
          <div id="faq-list"></div>
          <div class="system-change-wrap" style="margin-top:25px;display:flex;justify-content:flex-end;">
            <a href="reset/reset_preview.php" style="background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;padding:10px 22px;font-size:13px;font-weight:500;border-radius:8px;text-decoration:none;display:inline-block;transition:all .3s;box-shadow:0 4px 12px rgba(79,70,229,.25);">⚙️ System Change</a>
          </div>
        </div>
      </div>
    </div>

<!-- ══════════════════════════════════════════ ARCHIVE -->
    <div class="page" id="page-archive">
      <div class="section-header"><div><h2>Archive</h2><p>Restore or review archived students and teachers.</p></div><a href="reset/" class="btn btn-reset-archive">Reset Grade Archive</a></div>
      <div class="tab-bar">
        <button class="tab-btn active" data-tab="arc-students">Archived Students</button>
        <button class="tab-btn" data-tab="arc-teachers">Archived Teachers</button>
      </div>
      <div class="tab-content active" id="arc-students">
        <div class="toolbar">
          <div class="search-wrap"><i class="fa fa-magnifying-glass search-icon"></i><input type="text" id="arc-student-search" placeholder="Search archived students…"></div>
          <select class="filter" id="arc-student-grade"><option value="">All Grades</option></select>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>LRN</th><th>Name</th><th>Email</th><th>Grade</th><th>Section</th><th>Actions</th></tr></thead>
            <tbody id="arc-students-tbody"><tr class="state-row"><td colspan="6">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="tab-content" id="arc-teachers">
        <div class="toolbar">
          <div class="search-wrap"><i class="fa fa-magnifying-glass search-icon"></i><input type="text" id="arc-teacher-search" placeholder="Search archived teachers…"></div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Email</th><th>Specialization</th><th>Actions</th></tr></thead>
            <tbody id="arc-teachers-tbody"><tr class="state-row"><td colspan="4">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ SYSTEM -->
    <div class="page" id="page-system">
      <!--div class="section-header">
        <div><h2>System</h2><p>Manage school year and quarter settings.</p></div>
      </div-->

      <div class="system-section-wrap">

        <!-- ── Card ── -->
        <div class="system-card">

          <!-- Banner with animated wave -->
          <div class="sys-card-header">
            <span class="sys-card-header-icon">🏫</span>
            <span class="sys-card-header-label">Academic Period</span>

            <!-- Wave 1 -->
            <svg class="sys-wave" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 52" preserveAspectRatio="none">
              <path fill="rgba(255,255,255,0.10)" d="M0,26 C180,52 360,0 540,26 C720,52 900,0 1080,26 C1260,52 1440,0 1440,26 L1440,52 L0,52 Z"/>
              <path fill="rgba(255,255,255,0.07)" d="M0,36 C160,10 320,52 480,36 C640,20 800,52 960,36 C1120,20 1280,52 1440,36 L1440,52 L0,52 Z"/>
            </svg>

            <!-- Wave 2 -->
            <svg class="sys-wave2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 44" preserveAspectRatio="none">
              <path fill="rgba(255,255,255,0.06)" d="M0,22 C200,44 400,0 600,22 C800,44 1000,0 1200,22 C1300,33 1370,15 1440,22 L1440,44 L0,44 Z"/>
            </svg>
          </div>

          <!-- Body — centered -->
          <div class="sys-card-body">
            <div class="sys-field-label">School Year</div>
            <div class="sys-year-display" id="sys-year-val">—</div>

            <div class="sys-divider"></div>

            <div class="sys-field-label">Current Quarter</div>
            <div class="sys-quarter-badge" id="sys-quarter-val">
              <span class="sys-quarter-dot"></span>—
            </div>

            <div class="sys-updated" id="sys-updated-val">Loading…</div>
          </div>

        </div>

        <!-- ── Buttons — bottom-right of section ── -->
        <div class="system-card-actions">
          <button class="sys-btn sys-btn-quarter" onclick="openSysModal('quarter')">
            <i class="fa fa-rotate"></i> Change Quarter
          </button>
          <button class="sys-btn sys-btn-year" onclick="openSysModal('year')">
            <i class="fa fa-graduation-cap"></i> Change School Year
          </button>
        </div>

      </div>
    </div>

  </div><!-- /content -->
</main>

<!-- ══ MODAL — STUDENT ══ -->
<div class="modal-overlay" id="modal-student">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Student Information</div><button class="modal-close" onclick="closeModal('modal-student')">✕</button></div>
    <div class="modal-body" id="modal-student-body"></div>
  </div>
</div>

<!-- ══ MODAL — TEACHER ══ -->
<div class="modal-overlay" id="modal-teacher">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Teacher Information</div><button class="modal-close" onclick="closeModal('modal-teacher')">✕</button></div>
    <div class="modal-body" id="modal-teacher-body"></div>
  </div>
</div>

<!-- ══ MODAL — ARCHIVE ══ -->
<div class="modal-overlay" id="modal-archive">
  <div class="modal">
    <div class="modal-header"><div class="modal-title" id="modal-archive-title">Archived Record</div><button class="modal-close" onclick="closeModal('modal-archive')">✕</button></div>
    <div class="modal-body" id="modal-archive-body"></div>
  </div>
</div>

<!-- ══ MODAL — CHANGE QUARTER ══ -->
<div class="modal-overlay" id="modal-quarter">
  <div class="modal sys-modal">
    <div class="modal-header">
      <div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#2563eb;margin-bottom:3px;">🔄 Quarterly Settings</div>
        <div class="modal-title">Change Current Quarter</div>
      </div>
      <button class="modal-close" onclick="closeModal('modal-quarter')">✕</button>
    </div>
    <div class="modal-body">
      <div class="sys-info-row">
        <div class="sys-info-box"><div class="sib-label">School Year</div><div class="sib-val" id="qm-year">—</div></div>
        <div class="sys-info-box"><div class="sib-label">Current Quarter</div><div class="sib-val" id="qm-current">—</div></div>
      </div>
      <div class="sys-msg" id="qm-msg"></div>
      <div class="sys-form-group">
        <label for="qm-select">Set Quarter To:</label>
        <select id="qm-select">
          <option value="1">1st Grading</option>
          <option value="2">2nd Grading</option>
          <option value="3">3rd Grading</option>
          <option value="4">4th Grading</option>
        </select>
        <div class="sys-hint">Select any quarter to set it as the current active quarter.</div>
      </div>
      <button class="sys-apply-btn blue" onclick="applyQuarterChange()">Apply Quarter Change</button>
    </div>
  </div>
</div>

<!-- ══ MODAL — CHANGE SCHOOL YEAR ══ -->
<div class="modal-overlay" id="modal-year">
  <div class="modal sys-modal">
    <div class="modal-header">
      <div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#16a34a;margin-bottom:3px;">🎓 Yearly Settings</div>
        <div class="modal-title">Change School Year</div>
      </div>
      <button class="modal-close" onclick="closeModal('modal-year')">✕</button>
    </div>
    <div class="modal-body">
      <div class="sys-info-row">
        <div class="sys-info-box"><div class="sib-label">Current Year</div><div class="sib-val" id="ym-year">—</div></div>
        <div class="sys-info-box"><div class="sib-label">Current Quarter</div><div class="sib-val" id="ym-quarter">—</div></div>
      </div>
      <div class="sys-warn">⚠️ This should only be done after the yearly reset has been executed. Quarter will automatically reset to Q1.</div>
      <div class="sys-msg" id="ym-msg"></div>
      <div class="sys-form-group">
        <label for="ym-input">New School Year:</label>
        <input type="text" id="ym-input" placeholder="e.g. 2025-2026">
        <div class="sys-hint">Format: YYYY-YYYY · Must be exactly one year ahead of current.</div>
      </div>
      <button class="sys-apply-btn green" onclick="applyYearChange()">Apply Year Change</button>
    </div>
  </div>
</div>

<script>
'use strict';

// ════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════
const el   = id => document.getElementById(id);
const fmt  = v  => (v === null || v === undefined || v === '') ? '—' : v;
const fmtN = (v, d = 1) => (v === null || v === undefined || v === '') ? '—' : parseFloat(v).toFixed(d);
const initials = name => name.trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase();
const esc = s => String(s).replace(/'/g, "\\'");

function debounce(fn, ms = 350) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

async function api(url) {
  try {
    const res = await fetch(url); const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) { console.error('Bad JSON from', url, '\n', text.substring(0, 400)); return null; }
  } catch (e) { console.error('Fetch failed:', url, e); return null; }
}

function addOptions(selectId, values, labelKey, valueKey) {
  const sel = el(selectId);
  values.forEach(v => {
    const o = document.createElement('option');
    o.value = valueKey ? v[valueKey] : v;
    o.textContent = labelKey ? v[labelKey] : v;
    sel.appendChild(o);
  });
}

function buildPager(containerId, page, total_pages, goFn) {
  const pager = el(containerId);
  if (!pager) return;
  if (total_pages <= 1) { pager.innerHTML = ''; return; }
  const btn = (label, pg, disabled = false, active = false) =>
    `<button onclick="${goFn}(${pg})" class="btn btn-sm ${active ? 'btn-primary' : 'btn-outline'}" ${disabled ? 'disabled style="opacity:.4;cursor:default;"' : ''}>${label}</button>`;
  let pages = '';
  for (let i = 1; i <= total_pages; i++) {
    if (total_pages <= 7 || i === 1 || i === total_pages || Math.abs(i - page) <= 1)
      pages += btn(i, i, false, i === page);
    else if (Math.abs(i - page) === 2)
      pages += `<span style="padding:0 6px;color:#bbb;line-height:30px;">…</span>`;
  }
  pager.innerHTML = btn('←', page - 1, page === 1) + pages + btn('→', page + 1, page === total_pages);
}

// ════════════════════════════════════════════════════════════════
// THEME
// ════════════════════════════════════════════════════════════════
function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('ashi-theme', theme);
  el('theme-light').classList.toggle('selected', theme === 'light');
  el('theme-dark').classList.toggle('selected', theme === 'dark');
  el('theme-status').innerHTML = `Currently using: <strong>${theme === 'dark' ? 'Dark Mode' : 'Light Mode'}</strong>`;
}
(function () { const saved = localStorage.getItem('ashi-theme') || 'light'; setTheme(saved); })();

// ════════════════════════════════════════════════════════════════
// NAVIGATION
// ════════════════════════════════════════════════════════════════
const PAGE_TITLES = {
  dashboard: 'Dashboard',
  students: 'Students', 'student-progress': 'Student Progress',
  teachers: 'Teachers', 'teacher-progress': 'Teacher Progress',
  materials: 'Materials', grades: 'Grades & Reports',
  settings: 'Settings', archive: 'Archive', system: 'System',
};
const loaded = {};

function navigateTo(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item[data-page]').forEach(n => n.classList.remove('active'));
  const pageEl = el('page-' + page);
  if (pageEl) pageEl.classList.add('active');
  const navEl = document.querySelector(`.nav-item[data-page="${page}"]`);
  if (navEl) navEl.classList.add('active');
  el('topbar-title').textContent = PAGE_TITLES[page] || page;
  const loaders = {
    dashboard: loadDashboard, students: loadStudents, teachers: loadTeachers,
    materials: loadMaterials, grades: loadGrades, archive: loadArchive,
    system: loadSystemSettings,
  };
  if (loaders[page] && !loaded[page]) { loaded[page] = true; loaders[page](); }
}

document.querySelectorAll('.nav-item[data-page]').forEach(item => {
  item.addEventListener('click', () => navigateTo(item.dataset.page));
});

// ════════════════════════════════════════════════════════════════
// CHARTS
// ════════════════════════════════════════════════════════════════
const charts = {};
Chart.defaults.font  = { family: 'Poppins', size: 11 };
Chart.defaults.color = '#888';
function destroyChart(key) { if (charts[key]) { charts[key].destroy(); delete charts[key]; } }

// ════════════════════════════════════════════════════════════════
// DASHBOARD
// ════════════════════════════════════════════════════════════════
async function loadDashboard() {
  const data = await api('dashboard_stats.php');
  if (!data) {
    el('stat-grid').innerHTML = '<div class="stat-card" style="grid-column:1/-1;text-align:center;color:#c0392b;">Failed to load dashboard data. Check db.php.</div>';
    return;
  }

  // ── Academic period banner ──
  const ac = data.academic || {};
  const qLabels = { 1: '1st Grading', 2: '2nd Grading', 3: '3rd Grading', 4: '4th Grading' };
  const qLabel  = qLabels[ac.current_quarter] || ('Q' + ac.current_quarter);
  el('dash-academic-banner').innerHTML = `
    <span style="font-size:11px;font-weight:600;color:var(--text-muted);letter-spacing:.8px;text-transform:uppercase;">Academic Period</span>
    <span style="display:flex;align-items:center;gap:10px;margin-top:4px;flex-wrap:wrap;">
      <span style="font-size:14px;font-weight:700;">${ac.school_year || '—'}</span>
      <span style="width:1px;height:16px;background:var(--border);display:inline-block;"></span>
      <span style="display:inline-flex;align-items:center;gap:5px;">
        <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 2px rgba(34,197,94,.3);display:inline-block;"></span>
        <span style="font-size:13px;font-weight:600;">${qLabel}</span>
      </span>
    </span>`;

  // ── Stat cards ──
  const statDefs = [
    { key: 'students',    label: 'Total Students',  icon: '👤', sub: 'active students'   },
    { key: 'teachers',    label: 'Teachers',         icon: '🎓', sub: 'active faculty'    },
    { key: 'subjects',    label: 'Subjects',         icon: '📚', sub: 'active classes'    },
    { key: 'assignments', label: 'Assignments',      icon: '📝', sub: 'total issued'       },
    { key: 'quizzes',     label: 'Quizzes',          icon: '✅', sub: 'created this term' },
  ];
  el('stat-grid').innerHTML = statDefs.map(d => `
    <div class="stat-card">
      <div class="s-icon">${d.icon}</div>
      <div class="s-label">${d.label}</div>
      <div class="s-value">${Number(data.stats[d.key] ?? 0).toLocaleString()}</div>
      <div class="s-sub">${d.sub}</div>
    </div>`).join('');

  // ── Charts ──
  destroyChart('bar');
  charts['bar'] = new Chart(el('barChart'), {
    type: 'bar',
    data: { labels: data.enrollment.labels, datasets: [{ label: 'Students', data: data.enrollment.data, backgroundColor: '#111', borderRadius: 4, borderSkipped: false }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, border: { display: false } }, y: { grid: { color: '#f0f0f0' }, border: { display: false } } } }
  });

  destroyChart('line');
  charts['line'] = new Chart(el('lineChart'), {
    type: 'line',
    data: { labels: data.submissions.labels, datasets: [{ label: 'Submissions', data: data.submissions.data, borderColor: '#111', backgroundColor: 'rgba(17,17,17,0.06)', tension: 0.4, fill: true, pointBackgroundColor: '#111', pointRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, border: { display: false } }, y: { grid: { color: '#f0f0f0' }, border: { display: false } } } }
  });

  const passed = data.quiz_ratio.passed, failed = data.quiz_ratio.failed;
  const total  = passed + failed;
  const pPct   = total > 0 ? Math.round(passed / total * 100) : 0;
  destroyChart('pie');
  charts['pie'] = new Chart(el('pieChart'), {
    type: 'doughnut',
    data: { labels: ['Passed', 'Failed'], datasets: [{ data: [passed || 1, failed || 0], backgroundColor: ['#111', '#e0e0e0'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '68%' }
  });
  el('pie-legend').innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;"><span style="width:12px;height:12px;background:#111;border-radius:2px;display:inline-block;"></span><span style="font-size:12px;">Passed — ${pPct}%</span></div>
    <div style="display:flex;align-items:center;gap:8px;"><span style="width:12px;height:12px;background:#e0e0e0;border-radius:2px;display:inline-block;"></span><span style="font-size:12px;">Failed — ${100 - pPct}%</span></div>`;
}

// ════════════════════════════════════════════════════════════════
// STUDENTS
// ════════════════════════════════════════════════════════════════
const studentPager = { page: 1, total_pages: 1 };

async function loadStudents(resetPage = false) {
  if (resetPage) studentPager.page = 1;
  const search  = el('student-search').value.trim();
  const grade   = el('student-grade-filter').value;
  const section = el('student-section-filter').value;
  const data    = await api('get_student.php?' + new URLSearchParams({ action: 'list', search, grade, section, page: studentPager.page }));
  if (!data) { el('student-tbody').innerHTML = '<tr class="state-row"><td colspan="7">Failed to load.</td></tr>'; buildPager('student-pager', 1, 1, 'studentGoPage'); return; }
  if (!loaded['student-grade-opts']) { loaded['student-grade-opts'] = true; addOptions('student-grade-filter', data.filters.grades, null, null); }
  studentPager.total_pages = data.total_pages || 1;
  studentPager.page        = data.page        || 1;
  const tbody = el('student-tbody');
  if (!data.students.length) { tbody.innerHTML = '<tr class="state-row"><td colspan="7">No students found.</td></tr>'; buildPager('student-pager', 1, 1, 'studentGoPage'); return; }
  tbody.innerHTML = data.students.map(s => `
    <tr>
      <td style="font-size:11px;color:#999;">${fmt(s.lrn)}</td>
      <td><div class="cell-name"><div class="avatar-sm">${initials(s.first_name + ' ' + s.last_name)}</div><span class="name-text">${s.first_name} ${s.last_name}</span></div></td>
      <td>${fmt(s.email)}</td><td>${fmt(s.grade_level)}</td><td>${fmt(s.section)}</td>
      <td><span class="badge badge-active">Active</span></td>
      <td><div class="action-btns">
        <button class="btn btn-outline btn-sm icon-btn" title="View Info" onclick="openStudentModal(${s.id})"><i class="fa fa-eye"></i></button>
        <button class="btn btn-outline btn-sm icon-btn" title="View Progress" onclick="loadStudentProgress(${s.id},'${esc(s.first_name + ' ' + s.last_name)}')"><i class="fa fa-chart-line"></i></button>
        <button class="btn btn-danger btn-sm icon-btn" title="Archive" onclick="archiveStudent(${s.id},'${esc(s.first_name + ' ' + s.last_name)}')"><i class="fa fa-box-archive"></i></button>
      </div></td>
    </tr>`).join('');
  buildPager('student-pager', studentPager.page, studentPager.total_pages, 'studentGoPage');
}

function studentGoPage(pg) { studentPager.page = pg; loadStudents(false); }

async function onStudentGradeChange() {
  const grade = el('student-grade-filter').value;
  const secSel = el('student-section-filter');
  secSel.innerHTML = '<option value="">All Sections</option>';
  if (!grade) { secSel.disabled = true; loadStudents(true); return; }
  secSel.disabled = false;
  const data = await api('get_student.php?action=sections&grade=' + encodeURIComponent(grade));
  if (data && data.sections) data.sections.forEach(sec => { const o = document.createElement('option'); o.value = sec; o.textContent = sec; secSel.appendChild(o); });
  loadStudents(true);
}

el('student-search').addEventListener('input', debounce(() => loadStudents(true)));
el('student-grade-filter').addEventListener('change', onStudentGradeChange);
el('student-section-filter').addEventListener('change', () => loadStudents(true));

async function openStudentModal(id) {
  const data = await api('get_student.php?action=info&id=' + id);
  if (!data || !data.student) return;
  const s = data.student;
  const fullName = s.first_name + (s.middle_name ? ' ' + s.middle_name : '') + ' ' + s.last_name;
  el('modal-student-body').innerHTML = `
    <div class="modal-avatar-row">
      <div class="modal-avatar-lg">${initials(s.first_name + ' ' + s.last_name)}</div>
      <div><div style="font-size:16px;font-weight:700;">${fullName}</div>
      <div style="font-size:12px;color:var(--text-muted);">${fmt(s.grade_level)} — ${fmt(s.section)}</div>
      <span class="badge badge-active" style="margin-top:4px;">Active</span></div>
    </div>
    <div class="modal-grid">
      <div class="modal-field"><label>LRN</label><div class="field-val">${fmt(s.lrn)}</div></div>
      <div class="modal-field"><label>Birthdate</label><div class="field-val">${fmt(s.birthdate)}</div></div>
      <div class="modal-field full"><label>Email</label><div class="field-val">${fmt(s.email)}</div></div>
      <div class="modal-field full"><label>Username</label><div class="field-val">${fmt(s.username)}</div></div>
      <div class="modal-field"><label>Contact</label><div class="field-val">${fmt(s.contact_number)}</div></div>
      <div class="modal-field"><label>Grade &amp; Section</label><div class="field-val">${fmt(s.grade_level)} — ${fmt(s.section)}</div></div>
      <div class="modal-field"><label>Guardian</label><div class="field-val">${fmt(s.guardian_name)}</div></div>
      <div class="modal-field"><label>Relationship</label><div class="field-val">${fmt(s.relationship)}</div></div>
      <div class="modal-field full"><label>Guardian Contact</label><div class="field-val">${fmt(s.guardian_contact)}</div></div>
      ${s.bio ? `<div class="modal-field full"><label>Bio</label><div class="field-val">${fmt(s.bio)}</div></div>` : ''}
      <div class="modal-field"><label>Registered</label><div class="field-val">${s.created_at ? s.created_at.substring(0,10) : '—'}</div></div>
    </div>`;
  el('modal-student').classList.add('open');
}

async function loadStudentProgress(id, name) {
  el('sp-name').textContent = name;
  navigateTo('student-progress');
  ['sp-subjects','sp-asgn','sp-quiz','sp-subs','sp-late','sp-standing'].forEach(k => el(k).textContent = '…');
  el('sp-subjects-tbody').innerHTML = '<tr class="state-row"><td colspan="6">Loading…</td></tr>';
  el('sp-history-tbody').innerHTML  = '<tr class="state-row"><td colspan="6">Loading…</td></tr>';
  const data = await api('get_student.php?action=progress&id=' + id);
  if (!data) return;
  const ov = data.overview || {};
  el('sp-subjects').textContent = fmt(ov.enrolled_subjects);
  el('sp-asgn').textContent     = fmtN(ov.avg_assignment_score);
  el('sp-quiz').textContent     = ov.avg_quiz_pct != null ? fmtN(ov.avg_quiz_pct) + '%' : '—';
  el('sp-subs').textContent     = fmt(ov.total_submissions);
  el('sp-late').textContent     = fmt(ov.late_submissions);
  const avg = parseFloat(ov.avg_assignment_score) || 0;
  el('sp-standing').textContent = avg >= 90 ? 'Excellent' : avg >= 80 ? 'Good' : avg >= 75 ? 'Satisfactory' : avg > 0 ? 'Needs Improvement' : '—';
  el('sp-subjects-tbody').innerHTML = (data.subjects || []).length
    ? data.subjects.map(s => `<tr><td>${fmt(s.subject_name)}</td><td>${fmt(s.teacher_name)}</td><td>${fmt(s.submitted_assignments)} / ${fmt(s.total_assignments)}</td><td>${fmtN(s.avg_assignment_score)}</td><td>${s.avg_quiz_pct != null ? fmtN(s.avg_quiz_pct) + '%' : '—'}</td><td><span class="badge badge-active">${fmt(s.enrollment_status)}</span></td></tr>`).join('')
    : '<tr class="state-row"><td colspan="6">No subjects enrolled.</td></tr>';
  el('sp-history-tbody').innerHTML = (data.history || []).length
    ? data.history.map(h => `<tr><td>${fmt(h.subject_name)}</td><td>${fmt(h.assignment_title)}</td><td>${fmt(h.submitted_at)}</td><td>${fmtN(h.score,2)}</td><td>${fmtN(h.max_points,0)}</td><td><span class="badge ${h.status==='late'?'badge-closed':'badge-active'}">${fmt(h.status)}</span></td></tr>`).join('')
    : '<tr class="state-row"><td colspan="6">No submissions yet.</td></tr>';
}

async function archiveStudent(id, name) {
  if (!confirm('Archive ' + name + '?')) return;
  const data = await api('get_student.php?action=archive&id=' + id);
  if (data && data.success) { alert(name + ' has been archived.'); loadStudents(false); }
  else alert((data && data.message) || 'Archive failed.');
}

// ════════════════════════════════════════════════════════════════
// TEACHERS
// ════════════════════════════════════════════════════════════════
const teacherPager = { page: 1, total_pages: 1 };

async function loadTeachers(resetPage = false) {
  if (resetPage) teacherPager.page = 1;
  const search  = el('teacher-search').value.trim();
  const grade   = el('teacher-grade-filter').value;
  const section = el('teacher-section-filter').value;
  const data    = await api('get_teacher.php?' + new URLSearchParams({ action: 'list', search, grade, section, page: teacherPager.page }));
  if (!data) { el('teacher-tbody').innerHTML = '<tr class="state-row"><td colspan="7">Failed to load.</td></tr>'; buildPager('teacher-pager', 1, 1, 'teacherGoPage'); return; }
  if (!loaded['teacher-grade-opts']) { loaded['teacher-grade-opts'] = true; addOptions('teacher-grade-filter', data.filters.grades, null, null); }
  teacherPager.total_pages = data.total_pages || 1;
  teacherPager.page        = data.page        || 1;
  const tbody = el('teacher-tbody');
  if (!data.teachers.length) { tbody.innerHTML = '<tr class="state-row"><td colspan="7">No teachers found.</td></tr>'; buildPager('teacher-pager', 1, 1, 'teacherGoPage'); return; }
  tbody.innerHTML = data.teachers.map(t => `
    <tr>
      <td><div class="cell-name"><div class="avatar-sm">${initials(t.fullname)}</div><span class="name-text">${t.fullname}</span></div></td>
      <td>${fmt(t.email)}</td><td>${fmt(t.specialization)}</td>
      <td>${t.advisory_grade ? fmt(t.advisory_grade) + ' — ' + fmt(t.advisory_section) : '—'}</td>
      <td>${fmt(t.total_students)}</td>
      <td><span class="badge badge-active">Active</span></td>
      <td><div class="action-btns">
        <button class="btn btn-outline btn-sm icon-btn" title="View Info" onclick="openTeacherModal(${t.id})"><i class="fa fa-eye"></i></button>
        <button class="btn btn-outline btn-sm icon-btn" title="View Progress" onclick="loadTeacherProgress(${t.id},'${esc(t.fullname)}')"><i class="fa fa-chart-line"></i></button>
        <button class="btn btn-danger btn-sm icon-btn" title="Archive" onclick="archiveTeacher(${t.id},'${esc(t.fullname)}')"><i class="fa fa-box-archive"></i></button>
      </div></td>
    </tr>`).join('');
  buildPager('teacher-pager', teacherPager.page, teacherPager.total_pages, 'teacherGoPage');
}

function teacherGoPage(pg) { teacherPager.page = pg; loadTeachers(false); }

async function onTeacherGradeChange() {
  const grade = el('teacher-grade-filter').value;
  const secSel = el('teacher-section-filter');
  secSel.innerHTML = '<option value="">All Sections</option>';
  if (!grade) { secSel.disabled = true; loadTeachers(true); return; }
  secSel.disabled = false;
  const data = await api('get_teacher.php?action=sections&grade=' + encodeURIComponent(grade));
  if (data && data.sections) data.sections.forEach(sec => { const o = document.createElement('option'); o.value = sec; o.textContent = sec; secSel.appendChild(o); });
  loadTeachers(true);
}

el('teacher-search').addEventListener('input', debounce(() => loadTeachers(true)));
el('teacher-grade-filter').addEventListener('change', onTeacherGradeChange);
el('teacher-section-filter').addEventListener('change', () => loadTeachers(true));

async function openTeacherModal(id) {
  const data = await api('get_teacher.php?action=info&id=' + id);
  if (!data || !data.teacher) return;
  const t = data.teacher;
  el('modal-teacher-body').innerHTML = `
    <div class="modal-avatar-row">
      <div class="modal-avatar-lg">${initials(t.fullname)}</div>
      <div><div style="font-size:16px;font-weight:700;">${t.fullname}</div>
      <div style="font-size:12px;color:var(--text-muted);">${fmt(t.specialization)}</div>
      <span class="badge badge-active" style="margin-top:4px;">Active</span></div>
    </div>
    <div class="modal-grid">
      <div class="modal-field full"><label>Email</label><div class="field-val">${fmt(t.email)}</div></div>
      <div class="modal-field"><label>Phone</label><div class="field-val">${fmt(t.phone)}</div></div>
      <div class="modal-field"><label>Gender</label><div class="field-val">${fmt(t.gender)}</div></div>
      <div class="modal-field"><label>Birthdate</label><div class="field-val">${fmt(t.birthdate)}</div></div>
      <div class="modal-field"><label>Education</label><div class="field-val">${fmt(t.education)}</div></div>
      <div class="modal-field"><label>Specialization</label><div class="field-val">${fmt(t.specialization)}</div></div>
      <div class="modal-field"><label>Advisory Grade</label><div class="field-val">${fmt(t.advisory_grade)}</div></div>
      <div class="modal-field"><label>Advisory Section</label><div class="field-val">${fmt(t.advisory_section)}</div></div>
      <div class="modal-field full"><label>Address</label><div class="field-val">${fmt(t.address)}</div></div>
      ${t.bio ? `<div class="modal-field full"><label>Bio</label><div class="field-val">${fmt(t.bio)}</div></div>` : ''}
      <div class="modal-field"><label>Registered</label><div class="field-val">${t.created_at ? t.created_at.substring(0,10) : '—'}</div></div>
    </div>`;
  el('modal-teacher').classList.add('open');
}

async function loadTeacherProgress(id, name) {
  el('tp-name').textContent = name;
  navigateTo('teacher-progress');
  ['tp-subjects','tp-students','tp-asgn','tp-quiz','tp-graded','tp-materials'].forEach(k => el(k).textContent = '…');
  el('tp-subjects-tbody').innerHTML = '<tr class="state-row"><td colspan="7">Loading…</td></tr>';
  el('tp-activity-tbody').innerHTML = '<tr class="state-row"><td colspan="6">Loading…</td></tr>';
  const data = await api('get_teacher.php?action=progress&id=' + id);
  if (!data) return;
  const ov = data.overview || {};
  el('tp-subjects').textContent  = fmt(ov.subjects_handled);
  el('tp-students').textContent  = fmt(ov.total_students);
  el('tp-asgn').textContent      = fmt(ov.assignments_created);
  el('tp-quiz').textContent      = fmt(ov.quizzes_created);
  el('tp-graded').textContent    = fmt(ov.graded_submissions);
  el('tp-materials').textContent = fmt(ov.materials_posted);
  el('tp-subjects-tbody').innerHTML = (data.subjects || []).length
    ? data.subjects.map(s => `<tr><td>${fmt(s.subject_name)}</td><td>${fmt(s.section)}</td><td>${fmt(s.grade)}</td><td>${fmt(s.enrolled_students)}</td><td>${fmt(s.assignment_count)}</td><td>${fmt(s.quiz_count)}</td><td>${fmt(s.material_count)}</td></tr>`).join('')
    : '<tr class="state-row"><td colspan="7">No subjects found.</td></tr>';
  el('tp-activity-tbody').innerHTML = (data.activity || []).length
    ? data.activity.map(a => `<tr><td>${fmt(a.student_name)}</td><td>${fmt(a.subject_name)}</td><td>${fmt(a.assignment_title)}</td><td>${fmtN(a.score,2)}</td><td>${fmtN(a.max_points,0)}</td><td>${fmt(a.graded_at)}</td></tr>`).join('')
    : '<tr class="state-row"><td colspan="6">No grading activity yet.</td></tr>';
}

async function archiveTeacher(id, name) {
  if (!confirm('Archive ' + name + '?')) return;
  const data = await api('get_teacher.php?action=archive&id=' + id);
  if (data && data.success) { alert(name + ' has been archived.'); loadTeachers(false); }
  else alert((data && data.message) || 'Archive failed.');
}

// ════════════════════════════════════════════════════════════════
// MATERIALS
// ════════════════════════════════════════════════════════════════
async function loadMaterials() {
  const search = el('mat-search').value.trim();
  const teacher_id = el('mat-teacher-filter').value;
  const type = el('mat-type-filter').value;
  const data = await api('get_materials.php?' + new URLSearchParams({ action: 'list', search, teacher_id, type }));
  const container = el('materials-container');
  if (!data) { container.innerHTML = '<div class="empty-state"><div class="e-icon">❌</div><p>Failed to load materials.</p></div>'; return; }
  if (!loaded['mat-filters']) { loaded['mat-filters'] = true; addOptions('mat-teacher-filter', data.filters.teachers, 'fullname', 'id'); }
  if (!data.groups.length) { container.innerHTML = '<div class="empty-state"><div class="e-icon">📂</div><p>No materials found.</p></div>'; return; }
  container.innerHTML = data.groups.map(group => {
    const sub = group.subject;
    const rows = [...group.materials.map(m => ({...m,_type:'material'})), ...group.assignments.map(a => ({...a,_type:'assignment'})), ...group.quizzes.map(q => ({...q,_type:'quiz'}))]
      .sort((a,b) => new Date(b.created_at) - new Date(a.created_at))
      .map(item => {
        const typeBadge = item._type==='material'?'<span class="badge badge-gray">Material</span>':item._type==='assignment'?'<span class="badge badge-open">Assignment</span>':'<span class="badge badge-gray">Quiz</span>';
        const statusBadge = (!item.status||item.status==='published'||item.status==='active')?'<span class="badge badge-active">Open</span>':'<span class="badge badge-closed">Closed</span>';
        return `<tr><td>${typeBadge}</td><td>${fmt(item.title)}</td><td>${item.created_at?item.created_at.substring(0,10):'—'}</td><td>${item.due_date?item.due_date.substring(0,10):'—'}</td><td>${statusBadge}</td></tr>`;
      }).join('');
    return `<div class="material-group"><div class="material-group-title">${sub.teacher_name} — ${sub.subject_name} · ${sub.grade}</div>
      <div class="table-wrap" style="border-top:none;border-radius:0 0 var(--radius) var(--radius);">
        <table><thead><tr><th>Type</th><th>Title</th><th>Posted</th><th>Due Date</th><th>Status</th></tr></thead>
        <tbody>${rows||'<tr class="state-row"><td colspan="5">No items.</td></tr>'}</tbody></table>
      </div></div>`;
  }).join('');
}

el('mat-search').addEventListener('input', debounce(loadMaterials));
el('mat-teacher-filter').addEventListener('change', loadMaterials);
el('mat-type-filter').addEventListener('change', loadMaterials);

// ════════════════════════════════════════════════════════════════
// GRADES & REPORTS
// ════════════════════════════════════════════════════════════════
async function loadGrades() {
  const grade      = el('grades-grade-filter').value;
  const subject_id = el('grades-subject-filter').value;

  const data = await api('get_grds_rprts.php?' + new URLSearchParams({ action: 'summary', grade, subject_id }));
  if (!data) { el('grades-tbody').innerHTML = '<tr class="state-row"><td colspan="9">Failed to load.</td></tr>'; return; }

  // ── Populate grade dropdown once ──
  if (!loaded['grade-filters']) {
    loaded['grade-filters'] = true;
    addOptions('grades-grade-filter', data.grade_opts, null, null);
  }

  // ── Rebuild subject dropdown whenever grade changes ──
  // Always rebuild so it stays in sync with the selected grade
  const subSel  = el('grades-subject-filter');
  const prevVal = subSel.value;
  subSel.innerHTML = '<option value="">All Subjects</option>';
  (data.subject_opts || []).forEach(s => {
    const o = document.createElement('option');
    o.value       = s.id;
    o.textContent = s.subject_name + ' — ' + s.teacher_name;
    subSel.appendChild(o);
  });
  // Restore previous selection if still valid after filter change
  if ([...subSel.options].some(o => o.value === prevVal)) subSel.value = prevVal;

  // ── Render table ──
  const tbody = el('grades-tbody');
  if (!data.subjects.length) { tbody.innerHTML = '<tr class="state-row"><td colspan="9">No grade data found.</td></tr>'; return; }
  tbody.innerHTML = data.subjects.map(s => `
    <tr>
      <td><strong>${fmt(s.subject_name)}</strong></td>
      <td>${fmt(s.teacher_name)}</td>
      <td>${fmt(s.grade)} — ${fmt(s.section)}</td>
      <td>${fmt(s.enrolled_students)}</td>
      <td>${fmtN(s.avg_assignment_pct)}%</td>
      <td>${fmtN(s.avg_quiz_pct)}%</td>
      <td><strong>${fmtN(s.class_final_avg)}</strong></td>
      <td style="color:#2d6a4f;font-weight:600;">${fmt(s.students_passed)}</td>
      <td style="color:#c0392b;font-weight:600;">${fmt(s.students_failed)}</td>
    </tr>`).join('');
}

// Grade filter change: reset subject dropdown and reload
el('grades-grade-filter').addEventListener('change', () => {
  el('grades-subject-filter').innerHTML = '<option value="">All Subjects</option>';
  loadGrades();
});
el('grades-subject-filter').addEventListener('change', loadGrades);

el('btn-export-csv').addEventListener('click', () => {
  const grade      = encodeURIComponent(el('grades-grade-filter').value);
  const subject_id = encodeURIComponent(el('grades-subject-filter').value);
  window.location.href = `get_grds_rprts.php?action=export&format=csv&grade=${grade}&subject_id=${subject_id}`;
});

// ════════════════════════════════════════════════════════════════
// ARCHIVE
// ════════════════════════════════════════════════════════════════
function loadArchive() { loadArchivedStudents(); loadArchivedTeachers(); }

async function loadArchivedStudents() {
  const search = el('arc-student-search').value.trim();
  const grade  = el('arc-student-grade').value;
  const data   = await api('get_archive.php?' + new URLSearchParams({ action: 'list', type: 'students', search, grade }));
  if (!data) { el('arc-students-tbody').innerHTML = '<tr class="state-row"><td colspan="6">Failed to load.</td></tr>'; return; }
  if (!loaded['arc-grade-opts']) { loaded['arc-grade-opts'] = true; addOptions('arc-student-grade', data.grade_opts || [], null, null); }
  const tbody = el('arc-students-tbody');
  if (!data.records.length) { tbody.innerHTML = '<tr class="state-row"><td colspan="6">No archived students.</td></tr>'; return; }
  tbody.innerHTML = data.records.map(s => `
    <tr>
      <td style="font-size:11px;color:#999;">${fmt(s.lrn)}</td>
      <td><div class="cell-name"><div class="avatar-sm">${initials(s.first_name+' '+s.last_name)}</div><span class="name-text">${s.first_name} ${s.last_name}</span></div></td>
      <td>${fmt(s.email)}</td><td>${fmt(s.grade_level)}</td><td>${fmt(s.section)}</td>
      <td><div class="action-btns">
        <button class="btn btn-outline btn-sm icon-btn" title="View" onclick="openArchiveModal('students',${s.id})"><i class="fa fa-eye"></i></button>
        <button class="btn btn-success btn-sm icon-btn" title="Unarchive" onclick="unarchive('students',${s.id},'${esc(s.first_name+' '+s.last_name)}')"><i class="fa fa-rotate-left"></i></button>
      </div></td>
    </tr>`).join('');
}

async function loadArchivedTeachers() {
  const search = el('arc-teacher-search').value.trim();
  const data   = await api('get_archive.php?' + new URLSearchParams({ action: 'list', type: 'teachers', search }));
  if (!data) { el('arc-teachers-tbody').innerHTML = '<tr class="state-row"><td colspan="4">Failed to load.</td></tr>'; return; }
  const tbody = el('arc-teachers-tbody');
  if (!data.records.length) { tbody.innerHTML = '<tr class="state-row"><td colspan="4">No archived teachers.</td></tr>'; return; }
  tbody.innerHTML = data.records.map(t => `
    <tr>
      <td><div class="cell-name"><div class="avatar-sm">${initials(t.fullname)}</div><span class="name-text">${t.fullname}</span></div></td>
      <td>${fmt(t.email)}</td><td>${fmt(t.specialization)}</td>
      <td><div class="action-btns">
        <button class="btn btn-outline btn-sm icon-btn" title="View" onclick="openArchiveModal('teachers',${t.id})"><i class="fa fa-eye"></i></button>
        <button class="btn btn-success btn-sm icon-btn" title="Unarchive" onclick="unarchive('teachers',${t.id},'${esc(t.fullname)}')"><i class="fa fa-rotate-left"></i></button>
      </div></td>
    </tr>`).join('');
}

el('arc-student-search').addEventListener('input', debounce(loadArchivedStudents));
el('arc-student-grade').addEventListener('change', loadArchivedStudents);
el('arc-teacher-search').addEventListener('input', debounce(loadArchivedTeachers));

async function openArchiveModal(type, id) {
  const data = await api('get_archive.php?action=info&type=' + type + '&id=' + id);
  if (!data || !data.record) return;
  const r = data.record;
  el('modal-archive-title').textContent = type === 'students' ? 'Archived Student' : 'Archived Teacher';
  if (type === 'students') {
    el('modal-archive-body').innerHTML = `<div class="modal-grid">
      <div class="modal-field full"><label>Full Name</label><div class="field-val">${r.first_name} ${r.last_name}</div></div>
      <div class="modal-field"><label>LRN</label><div class="field-val">${fmt(r.lrn)}</div></div>
      <div class="modal-field"><label>Birthdate</label><div class="field-val">${fmt(r.birthdate)}</div></div>
      <div class="modal-field full"><label>Email</label><div class="field-val">${fmt(r.email)}</div></div>
      <div class="modal-field"><label>Grade</label><div class="field-val">${fmt(r.grade_level)}</div></div>
      <div class="modal-field"><label>Section</label><div class="field-val">${fmt(r.section)}</div></div>
      <div class="modal-field"><label>Guardian</label><div class="field-val">${fmt(r.guardian_name)}</div></div>
      <div class="modal-field"><label>Relationship</label><div class="field-val">${fmt(r.relationship)}</div></div>
      <div class="modal-field"><label>Status</label><div class="field-val"><span class="badge badge-archived">Archived</span></div></div>
    </div>`;
  } else {
    el('modal-archive-body').innerHTML = `<div class="modal-grid">
      <div class="modal-field full"><label>Full Name</label><div class="field-val">${fmt(r.fullname)}</div></div>
      <div class="modal-field full"><label>Email</label><div class="field-val">${fmt(r.email)}</div></div>
      <div class="modal-field"><label>Phone</label><div class="field-val">${fmt(r.phone)}</div></div>
      <div class="modal-field"><label>Gender</label><div class="field-val">${fmt(r.gender)}</div></div>
      <div class="modal-field"><label>Specialization</label><div class="field-val">${fmt(r.specialization)}</div></div>
      <div class="modal-field"><label>Education</label><div class="field-val">${fmt(r.education)}</div></div>
      <div class="modal-field"><label>Advisory Grade</label><div class="field-val">${fmt(r.advisory_grade)}</div></div>
      <div class="modal-field"><label>Advisory Section</label><div class="field-val">${fmt(r.advisory_section)}</div></div>
      <div class="modal-field full"><label>Address</label><div class="field-val">${fmt(r.address)}</div></div>
      <div class="modal-field"><label>Status</label><div class="field-val"><span class="badge badge-archived">Archived</span></div></div>
    </div>`;
  }
  el('modal-archive').classList.add('open');
}

async function unarchive(type, id, name) {
  if (!confirm('Restore ' + name + ' to active status?')) return;
  const data = await api('get_archive.php?action=unarchive&type=' + type + '&id=' + id);
  if (data && data.success) { alert(name + ' has been restored.'); loaded['arc-grade-opts'] = false; loadArchivedStudents(); loadArchivedTeachers(); }
  else alert((data && data.message) || 'Restore failed.');
}

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    this.classList.add('active');
    el(this.dataset.tab).classList.add('active');
  });
});

// ════════════════════════════════════════════════════════════════
// SYSTEM — Load & Display
// ════════════════════════════════════════════════════════════════

// In-memory state for system settings (loaded from DB via AJAX)
let sysState = { school_year: '—', current_quarter: '—', updated_at: '' };

async function loadSystemSettings() {
  const data = await api('get_system_settings.php');
  if (data && data.school_year) {
    sysState = data;
    renderSystemDisplay();
  } else {
    // Fallback: try the display PHP we already have
    el('sys-year-val').textContent    = 'Error';
    el('sys-quarter-val').textContent = '?';
    el('sys-updated-val').textContent = 'Could not load settings.';
  }
}

function renderSystemDisplay() {
  el('sys-year-val').textContent = sysState.school_year;
  el('sys-quarter-val').innerHTML = `<span class="sys-quarter-dot"></span>${gradingLabel(sysState.current_quarter)}`;
  el('sys-updated-val').textContent = sysState.updated_at
    ? 'Last updated: ' + new Date(sysState.updated_at).toLocaleString()
    : '';
}

// ── GRADING LABEL HELPER ──
function gradingLabel(q) {
  const labels = { 1: '1st Grading', 2: '2nd Grading', 3: '3rd Grading', 4: '4th Grading' };
  return labels[parseInt(q)] || 'Q' + q;
}

// ── OPEN SYSTEM MODALS ──
function openSysModal(type) {
  if (type === 'quarter') {
    el('qm-year').textContent    = sysState.school_year;
    el('qm-current').textContent = gradingLabel(sysState.current_quarter);
    // All 4 quarters always available — pre-select the current one
    const sel = el('qm-select');
    for (let i = 0; i < sel.options.length; i++) {
      sel.options[i].disabled = false;
      if (parseInt(sel.options[i].value) === parseInt(sysState.current_quarter)) {
        sel.selectedIndex = i;
      }
    }
    clearSysMsg('qm-msg');
    el('modal-quarter').classList.add('open');
  } else {
    el('ym-year').textContent    = sysState.school_year;
    el('ym-quarter').textContent = 'Q' + sysState.current_quarter;
    const parts = sysState.school_year.split('-');
    if (parts.length === 2) {
      el('ym-input').value = (parseInt(parts[0]) + 1) + '-' + (parseInt(parts[1]) + 1);
    }
    clearSysMsg('ym-msg');
    el('modal-year').classList.add('open');
  }
}

function clearSysMsg(id) {
  const m = el(id);
  m.className = 'sys-msg';
  m.textContent = '';
}

function showSysMsg(id, type, text) {
  const m = el(id);
  m.className = 'sys-msg ' + type;
  m.textContent = text;
}

// ── APPLY QUARTER CHANGE ──
async function applyQuarterChange() {
  const newQ = parseInt(el('qm-select').value);
  const curQ = parseInt(sysState.current_quarter);

  if (newQ < 1 || newQ > 4) { showSysMsg('qm-msg', 'err', '❌ Quarter must be between 1 and 4.'); return; }
  if (newQ === curQ)         { showSysMsg('qm-msg', 'err', '❌ That is already the current quarter.'); return; }

  const res = await api('get_system_settings.php?action=set_quarter&quarter=' + newQ);
  if (res && res.success) {
    sysState.current_quarter = newQ;
    if (res.updated_at) sysState.updated_at = res.updated_at;
    renderSystemDisplay();
    el('qm-current').textContent = gradingLabel(newQ);
    showSysMsg('qm-msg', 'ok', '✅ Updated to ' + gradingLabel(newQ) + '.');
    setTimeout(() => closeModal('modal-quarter'), 900);
  } else {
    showSysMsg('qm-msg', 'err', '❌ ' + (res && res.message ? res.message : 'Update failed.'));
  }
}

// ── APPLY YEAR CHANGE ──
async function applyYearChange() {
  const newYear = el('ym-input').value.trim();

  if (!/^\d{4}-\d{4}$/.test(newYear)) { showSysMsg('ym-msg', 'err', '❌ Invalid format. Use YYYY-YYYY (e.g. 2025-2026).'); return; }
  const [ns, ne] = newYear.split('-').map(Number);
  const [cs]     = sysState.school_year.split('-').map(Number);
  if (ns <= cs)       { showSysMsg('ym-msg', 'err', '❌ You cannot go back to a previous or same school year.'); return; }
  if (ne !== ns + 1)  { showSysMsg('ym-msg', 'err', '❌ School year must span exactly one year (e.g. 2025-2026).'); return; }

  const res = await api('get_system_settings.php?action=set_year&year=' + encodeURIComponent(newYear));
  if (res && res.success) {
    sysState.school_year     = newYear;
    sysState.current_quarter = 1;
    if (res.updated_at) sysState.updated_at = res.updated_at;
    renderSystemDisplay();
    el('ym-year').textContent    = newYear;
    el('ym-quarter').textContent = 'Q1';
    showSysMsg('ym-msg', 'ok', '✅ School year updated to ' + newYear + '. Quarter reset to Q1.');
    setTimeout(() => closeModal('modal-year'), 900);
  } else {
    showSysMsg('ym-msg', 'err', '❌ ' + (res && res.message ? res.message : 'Update failed.'));
  }
}

// ════════════════════════════════════════════════════════════════
// MODALS (generic)
// ════════════════════════════════════════════════════════════════
function closeModal(id) { el(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
});

// ════════════════════════════════════════════════════════════════
// FAQ
// ════════════════════════════════════════════════════════════════
const FAQS = [
  { q: 'How do I add a new student?', a: 'Students register through the student portal and appear automatically. Their record shows up on the Students page once they have an active account.' },
  { q: 'How does archiving work?', a: "Archiving sets the record's status to 'archived' in the database. It's hidden from active views but fully preserved. It can be restored anytime via the Archive page." },
  { q: 'Can I edit subject or assignment data?', a: 'Editing academic content is handled by teachers through their own portal. The admin panel is for monitoring and user management only.' },
  { q: 'How are grades calculated?', a: 'Class average = 60% assignment avg + 40% quiz avg when no final grade is set by the teacher. If a final_grade is recorded in student_subjects, that takes priority.' },
  { q: 'How does the Light/Dark theme work?', a: 'Click the theme option in Settings → Appearance. Your choice is saved in your browser and applied automatically every time you open the panel.' },
  { q: 'How does the CSV export work?', a: 'Clicking Export CSV calls get_grds_rprts.php?action=export&format=csv which streams the grade summary directly as a downloadable file.' },
  { q: 'Why is my data not showing up?', a: 'Open your browser DevTools → Network tab, click on the failing request and check the Response. Most likely the database credentials in db.php need to be updated, or the database has no data yet.' },
];
el('faq-list').innerHTML = FAQS.map((f, i) => `
  <div class="faq-item" id="faq-${i}">
    <button class="faq-q" onclick="this.closest('.faq-item').classList.toggle('open')">
      <span>${f.q}</span><span class="chevron">▼</span>
    </button>
    <div class="faq-a">${f.a}</div>
  </div>`).join('');

// ════════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════════
loadDashboard();
</script>
</body>
</html>