<?php
// Demo entry point (Aug 2026) — the one file that has to live outside
// app/, since it needs a stable, always-public URL
// (public_html/Wardstock/demo/) that never requires a real login. It does
// NOT duplicate a single screen: this just flips a session flag, then
// hands off into the exact same pages the real app already serves at
// public_html/Wardstock/ — db.php's get_db() is what actually routes to
// the separate demo database for the rest of this browser session.
//
// Path below is written for the DEPLOYED layout, where this file sits in
// its own demo/ subfolder directly alongside the (flattened) app files
// one level up — same convention every app/*.php file already uses for
// __DIR__.'/config/config.php', which likewise doesn't resolve from
// inside this repo's own godaddy/app/ folder until actually deployed.
// See README.md in this folder for deployment steps.
require_once __DIR__ . '/../auth.php';
start_session();
session_regenerate_id(true);
$_SESSION['demo_mode'] = true;
$_SESSION['user_id'] = 'demo'; // satisfies is_logged_in() -- never checked against app_user in demo mode
header('Location: ../index.php');
exit;
